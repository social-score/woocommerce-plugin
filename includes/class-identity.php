<?php
/**
 * Login, registration and order events with a hashed email or phone, for recognising a person on Level 2 (requirements
 * C4). Prepared for stage 6 and OFF.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Level 1 sends nothing personal. This class sends something only when all of these hold, and each is checked every time:
 *
 * 1. The merchant has switched Level 2 on in SocialScore (`level2` in `GET /api/v2/stores/{id}/pixel`). The stage 6
 *    backend does not send it yet, so the value is false everywhere and this class does nothing in any shop.
 * 2. The plugin's own switch is on: the constant `SOCIALSCORE_LEVEL2` is true, or the filter `socialscore_level2_enabled`
 *    returns true. It is off by default, so an old shop cannot start sending personal data by an update.
 * 3. The visitor has given consent for marketing/statistics: the WP Consent API (`wp_has_consent( 'marketing' )`) when that
 *    plugin is active, else false; the filter `socialscore_level2_consent` gets that answer and may replace it (for a
 *    consent banner that has no WP Consent API support).
 *
 * What leaves the shop is a SHA-256 of the normalised email (lower case, trimmed) or phone (digits only), the event
 * type and the time. Never the plain value, never a name or an address. The event is queued in Action Scheduler and
 * sent by a background job to `POST /api/v2/stores/{id}/pixel/identity`, a route stage 6 adds; until it exists a send
 * is answered 404 and dropped (see "Level 2" in docs/architecture-pixel-s5.md for the shape).
 */
class Identity {


	const HOOK_SEND = 'socialscore_identity_event';
	const TYPES     = array( 'login', 'register', 'order' );

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_login', array( $this, 'on_login' ), 20, 2 );
		add_action( 'user_register', array( $this, 'on_register' ), 20, 1 );
		add_action( 'woocommerce_new_order', array( $this, 'on_order' ), 30, 1 );
		add_action( self::HOOK_SEND, array( $this, 'send' ), 10, 1 );
	}

	/**
	 * SHA-256 of a normalised email, or an empty string when it is not an email.
	 *
	 * @param  string $email Email.
	 * @return string
	 */
	public static function hash_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		return false === strpos( $email, '@' ) || strlen( $email ) > 254 ? '' : hash( 'sha256', $email );
	}

	/**
	 * SHA-256 of a phone's digits, or an empty string when there are fewer than 6 or more than 15.
	 *
	 * @param  string $phone Phone.
	 * @return string
	 */
	public static function hash_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		$length = strlen( (string) $digits );
		return $length < 6 || $length > 15 ? '' : hash( 'sha256', (string) $digits );
	}

	/**
	 * Whether personal events may be made at all: Level 2 on at SocialScore and in the plugin, and consent given.
	 *
	 * @return bool
	 */
	public static function allowed() {
		if ( ! Connection::is_connected() ) {
			return false;
		}
		$own = ( defined( 'SOCIALSCORE_LEVEL2' ) && SOCIALSCORE_LEVEL2 ) || (bool) apply_filters( 'socialscore_level2_enabled', false );
		if ( ! $own ) {
			return false;
		}
		$state = Pixel::state();
		if ( empty( $state['level2'] ) ) {
			return false;
		}
		$consent = function_exists( 'wp_has_consent' ) ? (bool) wp_has_consent( 'marketing' ) : false;
		return (bool) apply_filters( 'socialscore_level2_consent', $consent );
	}

	/**
	 * Whether a queued event may still leave the shop: connected, the plugin switch on and Level 2 on at SocialScore. The
	 * visitor's consent was checked when the event was made; a visitor's browser is not there when the job runs.
	 *
	 * @return bool
	 */
	private static function allowed_to_send() {
		if ( ! Connection::is_connected() ) {
			return false;
		}
		$own = ( defined( 'SOCIALSCORE_LEVEL2' ) && SOCIALSCORE_LEVEL2 ) || (bool) apply_filters( 'socialscore_level2_enabled', false );
		if ( ! $own ) {
			return false;
		}
		$state = Pixel::state();
		return ! empty( $state['level2'] );
	}

	/**
	 * A successful login.
	 *
	 * @param  string   $user_login Login name.
	 * @param  \WP_User $user       User.
	 * @return void
	 */
	public function on_login( $user_login, $user = null ) {
		unset( $user_login );
		if ( self::allowed() && is_object( $user ) && ! empty( $user->user_email ) ) {
			$this->queue( 'login', array( 'email_sha256' => self::hash_email( $user->user_email ) ) );
		}
	}

	/**
	 * A new account.
	 *
	 * @param  int $user_id User id.
	 * @return void
	 */
	public function on_register( $user_id ) {
		$user = self::allowed() ? get_userdata( (int) $user_id ) : null;
		if ( $user && ! empty( $user->user_email ) ) {
			$this->queue( 'register', array( 'email_sha256' => self::hash_email( $user->user_email ) ) );
		}
	}

	/**
	 * A new order: the hashed billing email and phone.
	 *
	 * @param  int $order_id Order id.
	 * @return void
	 */
	public function on_order( $order_id ) {
		if ( ! self::allowed() ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( $order ) {
			$this->queue(
				'order',
				array(
					'email_sha256' => self::hash_email( $order->get_billing_email() ),
					'phone_sha256' => self::hash_phone( $order->get_billing_phone() ),
				),
				(string) $order->get_id()
			);
		}
	}

	/**
	 * Queues one event, only when everything above allows it. The queue holds hashes and a type, nothing else.
	 *
	 * @param  string $type     login, register or order.
	 * @param  array  $hashes   email_sha256 and phone_sha256; an empty one is left out.
	 * @param  string $order_id The order's external id, for an order event.
	 * @return bool Whether an event was queued.
	 */
	private function queue( $type, array $hashes, $order_id = '' ) {
		$hashes = array_filter( $hashes, 'strlen' );
		if ( ! $hashes || ! self::allowed() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		$event = array(
			'type' => $type,
			'at'   => gmdate( 'c' ),
		) + $hashes;
		if ( '' !== $order_id ) {
			$event['order_external_id'] = $order_id;
		}
		as_enqueue_async_action( self::HOOK_SEND, array( $event ), Config::ACTION_GROUP );
		return true;
	}

	/**
	 * Sends one queued event. A 404 (the route of stage 6 is not there yet) or any 4xx drops it.
	 *
	 * @param  array $event The queued event.
	 * @return void
	 */
	public function send( $event ) {
		// Checked again now: the event may have waited in the queue while Level 2 or the visitor's consent changed. The
		// consent of the request that made the event cannot be read here, so the switches of the shop decide.
		if ( ! is_array( $event ) || ! in_array( $event['type'] ?? '', self::TYPES, true ) || ! self::allowed_to_send() ) {
			return;
		}
		$body = array( 'events' => array( $event ) );
		( new Api_Client( 5 ) )->request( 'POST', '/api/v2/stores/' . rawurlencode( (string) Connection::store_id() ) . '/pixel/identity', $body );
	}
}
