<?php
/**
 * Login, registration and order events with the email or phone of a person who consented, for recognising a person on
 * Level 2 (requirements C4, stage 6). Off unless the three conditions below hold.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Login, registration and order events for recognising a person on Level 2 (requirements C4, stage 6). OFF unless all
 * of these hold:
 *
 * 1. The merchant has switched Level 2 on in SocialScore (`level2` in `GET /api/v2/stores/{id}/pixel`: the flag of the
 *    environment, the merchant's "Marketing data" switch and a paid plan).
 * 2. The plugin's own switch is on: the constant `SOCIALSCORE_LEVEL2` is true, or the filter `socialscore_level2_enabled`
 *    returns true. It is off by default, so an old shop cannot start sending personal data by an update.
 * 3. The visitor has given consent for statistics AND marketing: the WP Consent API (`wp_has_consent( 'statistics' )` and
 *    `wp_has_consent( 'marketing' )`) when that plugin is active, else false; the filter `socialscore_level2_consent`
 *    gets that answer and may replace it (for a consent banner that has no WP Consent API support).
 *
 * An order an AI agent placed for a person (MCP Store module) has no banner and no consent of its own. It is queued
 * on conditions 1 and 2 only, marked `placed_by_agent`, and SocialScore puts it in the profile of a person who
 * already exists and consented; it never makes a person.
 *
 * The event is queued in Action Scheduler holding only hashes, a type, the time, the consent that was read, the
 * Level 2 cookie `ss_l2` of the request (a random id the visitor's browser got after consent) and a reference (user or
 * order id). The background job resolves the plain email and phone from the reference and sends them over TLS with the
 * shop's store key to `POST /api/v2/stores/{id}/pixel/identity`: SocialScore keeps a comparison hash with the company's
 * secret and a sealed copy for the administrator's Reveal. Never a name or an address.
 */
class Identity {


	const HOOK_SEND = 'socialscore_identity_event';
	const TYPES     = array( 'login', 'register', 'order' );
	const COOKIE    = 'ss_l2';

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
	 * The plugin's own switch and Level 2 at SocialScore: what every event needs, a visitor's or an agent's.
	 *
	 * @return bool
	 */
	private static function level2_on() {
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
	 * The consent of the visitor of this request: statistics and marketing, both, from the WP Consent API or the filter.
	 * Returns the state to send, or null when there is not consent for Level 2.
	 *
	 * @return array|null
	 */
	private static function consent() {
		$api = function_exists( 'wp_has_consent' ) && wp_has_consent( 'statistics' ) && wp_has_consent( 'marketing' );
		if ( ! (bool) apply_filters( 'socialscore_level2_consent', $api ) ) {
			return null;
		}
		return array(
			'analytics' => true,
			'marketing' => true,
			'source'    => $api ? 'wpca' : 'none',
		);
	}

	/**
	 * Whether personal events may be made at all: Level 2 on at SocialScore and in the plugin, and consent given.
	 *
	 * @return bool
	 */
	public static function allowed() {
		return self::level2_on() && null !== self::consent();
	}

	/**
	 * The id of the Level 2 cookie of this request, or an empty string: a UUIDv4 the browser got from the Pixel after
	 * consent. Read only when an event is made, which needs consent.
	 *
	 * @return string
	 */
	private static function device_id() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against a UUIDv4 pattern below.
		$value = isset( $_COOKIE[ self::COOKIE ] ) ? wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) : '';
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value ) ? $value : '';
	}

	/**
	 * Whether a queued event may still leave the shop: connected, the plugin switch on and Level 2 on at SocialScore. The
	 * visitor's consent was checked when the event was made; a visitor's browser is not there when the job runs.
	 *
	 * @return bool
	 */
	private static function allowed_to_send() {
		return self::level2_on();
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
			$this->queue( 'login', array( 'email_sha256' => self::hash_email( $user->user_email ) ), '', 'user:' . (int) $user->ID );
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
			$this->queue( 'register', array( 'email_sha256' => self::hash_email( $user->user_email ) ), '', 'user:' . (int) $user_id );
		}
	}

	/**
	 * A new order: the hashed billing email and phone. An order an agent placed goes without the visitor's consent,
	 * marked, and SocialScore ties it only to a person who already consented.
	 *
	 * @param  int $order_id Order id.
	 * @return void
	 */
	public function on_order( $order_id ) {
		if ( ! self::level2_on() ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}
		$agent = Mapper::is_agent_order( $order );
		if ( ! $agent && ! self::allowed() ) {
			return;
		}
		$this->queue(
			'order',
			array(
				'email_sha256' => self::hash_email( $order->get_billing_email() ),
				'phone_sha256' => self::hash_phone( $order->get_billing_phone() ),
			),
			(string) $order->get_id(),
			'order:' . $order->get_id(),
			$agent
		);
	}

	/**
	 * Queues one event, only when everything above allows it. The queue holds hashes, a type, a reference and the
	 * consent that was read, nothing else.
	 *
	 * @param  string $type     login, register or order.
	 * @param  array  $hashes   email_sha256 and phone_sha256; an empty one is left out.
	 * @param  string $order_id The order's external id, for an order event.
	 * @param  string $ref      `user:<id>` or `order:<id>`: where the background job finds the plain value.
	 * @param  bool   $agent    The order was placed by an AI agent.
	 * @return bool Whether an event was queued.
	 */
	private function queue( $type, array $hashes, $order_id = '', $ref = '', $agent = false ) {
		$hashes = array_filter( $hashes, 'strlen' );
		if ( ! $hashes || ! self::level2_on() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		$event = array(
			'type' => $type,
			'at'   => gmdate( 'c' ),
		) + $hashes;
		if ( $agent ) {
			$event['placed_by_agent'] = true;
		} else {
			$consent = self::consent();
			if ( null === $consent ) {
				return false;
			}
			$event['consent'] = $consent;
			$device           = self::device_id();
			if ( '' !== $device ) {
				$event['device_id'] = $device;
			}
		}
		if ( '' !== $order_id ) {
			$event['order_external_id'] = $order_id;
		}
		if ( '' !== $ref ) {
			$event['ref'] = $ref;
		}
		as_enqueue_async_action( self::HOOK_SEND, array( $event ), Config::ACTION_GROUP );
		return true;
	}

	/**
	 * The plain email and phone behind a queued event, when the user or the order is still there and still has the
	 * value the hash was made from. A value that changed since is left out: the hash alone is sent.
	 *
	 * @param  array $event The queued event.
	 * @return array email and phone, each possibly missing.
	 */
	private static function plain_values( array $event ) {
		$ref = (string) ( $event['ref'] ?? '' );
		$out = array();
		if ( 0 === strpos( $ref, 'user:' ) ) {
			$user = get_userdata( (int) substr( $ref, 5 ) );
			$mail = $user && ! empty( $user->user_email ) ? strtolower( trim( (string) $user->user_email ) ) : '';
			if ( '' !== $mail && isset( $event['email_sha256'] ) && self::hash_email( $mail ) === $event['email_sha256'] ) {
				$out['email'] = $mail;
			}
		} elseif ( 0 === strpos( $ref, 'order:' ) ) {
			$order = wc_get_order( (int) substr( $ref, 6 ) );
			if ( $order ) {
				$mail  = strtolower( trim( (string) $order->get_billing_email() ) );
				$phone = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );
				if ( '' !== $mail && isset( $event['email_sha256'] ) && self::hash_email( $mail ) === $event['email_sha256'] ) {
					$out['email'] = $mail;
				}
				if ( '' !== (string) $phone && isset( $event['phone_sha256'] ) && self::hash_phone( $phone ) === $event['phone_sha256'] ) {
					$out['phone'] = $phone;
				}
			}
		}
		return $out;
	}

	/**
	 * Sends one queued event. A 404 or any 4xx drops it.
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
		$plain = self::plain_values( $event );
		if ( isset( $plain['email'] ) ) {
			unset( $event['email_sha256'] );
		}
		if ( isset( $plain['phone'] ) ) {
			unset( $event['phone_sha256'] );
		}
		unset( $event['ref'] );
		$body = array( 'events' => array( $event + $plain ) );
		( new Api_Client( 5 ) )->request( 'POST', '/api/v2/stores/' . rawurlencode( (string) Connection::store_id() ) . '/pixel/identity', $body );
	}
}
