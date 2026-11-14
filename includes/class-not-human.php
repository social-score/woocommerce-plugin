<?php
/**
 * The "not from a human" flag on orders (requirements C2, section 7).
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * An order is flagged from two places. The module's own detection of the request that placed it (Bot_Guard::current(), a
 * bot that ran no JavaScript) is written at checkout. The class the Pixel gave the visit behind the order comes
 * later, through the one order to session link (the checkout reference, synced as the order's `cart_token`): a delayed
 * background job asks `GET /api/v2/stores/{id}/orders/{id}/session` once the order is in SocialScore, and again a few
 * times while the Pixel has not sent the session yet. Both end in the same order meta, which the orders list, the order
 * screen and the reports page read. Nothing here touches the order's status: a bot's order that is real is not a
 * problem in itself, the merchant is told to look.
 */
class Not_Human {


	const META_CLASS    = '_socialscore_not_human';
	const META_SIGNALS  = '_socialscore_not_human_signals';
	const META_SOURCE   = '_socialscore_not_human_source';
	const META_VISIT    = '_socialscore_visit_class';
	const META_NOTED    = '_socialscore_not_human_noted';
	const HOOK_SESSION  = 'socialscore_order_session';
	const SOURCE_SERVER = 'server';
	const SOURCE_PIXEL  = 'pixel';
	// Seconds to wait before attempt 0, 1, 2 and 3 of the session lookup. The Pixel server sends a session some minutes
	// after the visit ends, so the first look is early and the later ones are patient.
	const DELAYS = array( 120, 900, 3600, 21600 );

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'flag_classic' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'flag_block' ), 20, 2 );
		add_action( 'woocommerce_new_order', array( $this, 'note_new_order' ), 20, 1 );
		add_action( self::HOOK_SESSION, array( $this, 'handle_session' ), 10, 2 );
	}

	/**
	 * Classes that are not a person, with their text for the merchant.
	 *
	 * @param  string $kind Class.
	 * @return string Empty for a class that is not flagged.
	 */
	public static function text( $kind ) {
		switch ( $kind ) {
			case Bot_Detector::BAD_BOT:
				return __( 'Bad bot', 'socialscore' );
			case Bot_Detector::AI_AGENT:
				return __( 'AI agent', 'socialscore' );
			case Bot_Detector::INFO_BOT:
				return __( 'Known bot', 'socialscore' );
		}
		return '';
	}

	/**
	 * The warning the merchant reads on the order, per class.
	 *
	 * @param  string $kind Class.
	 * @return string
	 */
	public static function warning( $kind ) {
		switch ( $kind ) {
			case Bot_Detector::BAD_BOT:
				return __( 'This order did not come from a person: the visit behind it was a bad bot. Check it again before shipping.', 'socialscore' );
			case Bot_Detector::AI_AGENT:
				return __( 'This order did not come from a person directly: it was placed by an AI agent. Check it before shipping.', 'socialscore' );
			case Bot_Detector::INFO_BOT:
				return __( 'This order did not come from a person: the visit behind it was a known bot. Check it before shipping.', 'socialscore' );
		}
		return '';
	}

	/**
	 * Merchant text for a signal code. A code this version does not know shows as plain words.
	 *
	 * @param  string $code Code.
	 * @return string
	 */
	public static function signal_text( $code ) {
		$texts = array(
			'webdriver'             => __( 'The browser is automated', 'socialscore' ),
			'headless_ua'           => __( 'Headless browser', 'socialscore' ),
			'software_gpu'          => __( 'Software graphics, no real GPU', 'socialscore' ),
			'empty_screen'          => __( 'No screen size', 'socialscore' ),
			'no_interaction'        => __( 'No mouse, touch or keyboard use', 'socialscore' ),
			'idle_heartbeats'       => __( 'Page open with no activity', 'socialscore' ),
			'page_burst'            => __( 'Many pages in a very short time', 'socialscore' ),
			'device_farm'           => __( 'Device farm', 'socialscore' ),
			'fp_many_ips'           => __( 'The same device from many networks', 'socialscore' ),
			'impossible_device'     => __( 'Device values that cannot be real', 'socialscore' ),
			'tz_country_mismatch'   => __( 'Time zone does not fit the country', 'socialscore' ),
			'lang_country_mismatch' => __( 'Language does not fit the country', 'socialscore' ),
			'robotic_typing'        => __( 'Typing too even for a person', 'socialscore' ),
			'signature_agent'       => __( 'Signed by an AI agent', 'socialscore' ),
			'declared_bot'          => __( 'Says it is a bot', 'socialscore' ),
			'declared_agent'        => __( 'Says it is an AI agent', 'socialscore' ),
			'fake_bot'              => __( 'Says it is a search engine, but its address is not the engine\'s', 'socialscore' ),
			'unverified_bot'        => __( 'Says it is a search engine; the address could not be checked', 'socialscore' ),
			'empty_ua'              => __( 'No user agent', 'socialscore' ),
			'http_client_ua'        => __( 'Script or HTTP library, not a browser', 'socialscore' ),
			'scanner_ua'            => __( 'Security scanner', 'socialscore' ),
			'no_accept_language'    => __( 'No language header, as every browser sends', 'socialscore' ),
			'no_accept'             => __( 'No accept header, as every browser sends', 'socialscore' ),
			'no_fetch_metadata'     => __( 'No fetch metadata from a modern browser', 'socialscore' ),
		);
		return isset( $texts[ $code ] ) ? $texts[ $code ] : str_replace( '_', ' ', (string) $code );
	}

	/**
	 * What an order is flagged with.
	 *
	 * @param  \WC_Order $order Order.
	 * @return array{class:string,signals:string[],source:string}|null Null when it is not flagged.
	 */
	public static function for_order( $order ) {
		$class = (string) $order->get_meta( self::META_CLASS );
		if ( '' === self::text( $class ) ) {
			return null;
		}
		$signals = $order->get_meta( self::META_SIGNALS );
		return array(
			'class'   => $class,
			'signals' => self::clean_signals( $signals ),
			'source'  => (string) $order->get_meta( self::META_SOURCE ),
		);
	}

	/**
	 * Keeps only well-formed signal codes.
	 *
	 * @param  mixed $signals Value from meta or the API.
	 * @return string[]
	 */
	private static function clean_signals( $signals ) {
		if ( ! is_array( $signals ) ) {
			return array();
		}
		$out = array();
		foreach ( $signals as $code ) {
			if ( is_string( $code ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,39}$/D', $code ) ) {
				$out[ $code ] = $code;
			}
		}
		return array_values( array_slice( $out, 0, 32 ) );
	}

	/**
	 * Warning and reasons for the order screen, escaped, or an empty string.
	 *
	 * @param  \WC_Order $order Order.
	 * @return string
	 */
	public static function notice_html( $order ) {
		$flag = self::for_order( $order );
		if ( ! $flag ) {
			return '';
		}
		$html = '<div class="notice notice-warning inline socialscore-not-human"><p><strong>' . esc_html( self::text( $flag['class'] ) ) . '</strong> ' . esc_html( self::warning( $flag['class'] ) ) . '</p>';
		if ( $flag['signals'] ) {
			$html .= '<ul>';
			foreach ( $flag['signals'] as $code ) {
				$html .= '<li>' . esc_html( self::signal_text( $code ) ) . '</li>';
			}
			$html .= '</ul>';
		}
		return $html . '</div>';
	}

	/**
	 * Classic checkout: flags the order when this request is a bot's.
	 *
	 * @param  \WC_Order $order Order, not yet saved.
	 * @param  array     $data  Posted data.
	 * @return void
	 */
	public function flag_classic( $order, $data = array() ) {
		unset( $data );
		$this->flag_from_request( $order );
	}

	/**
	 * Checkout block: the same for the Store API checkout.
	 *
	 * @param  \WC_Order        $order   Order, not yet saved.
	 * @param  \WP_REST_Request $request Request.
	 * @return void
	 */
	public function flag_block( $order, $request = null ) {
		unset( $request );
		$this->flag_from_request( $order );
	}

	/**
	 * Writes the request's own verdict on the order, when it is not a person's. No JavaScript is needed to be caught: the
	 * verdict is read from the headers of the request that placed the order.
	 *
	 * @param  \WC_Order $order Order, not yet saved.
	 * @return void
	 */
	private function flag_from_request( $order ) {
		if ( ! is_object( $order ) || ! Connection::is_connected() ) {
			return;
		}
		$verdict = Bot_Guard::current();
		if ( ! Bot_Detector::is_not_human( $verdict['class'] ) ) {
			return;
		}
		$order->update_meta_data( self::META_CLASS, $verdict['class'] );
		$order->update_meta_data( self::META_SIGNALS, $verdict['signals'] );
		$order->update_meta_data( self::META_SOURCE, self::SOURCE_SERVER );
	}

	/**
	 * Adds the order note for a flag written at checkout, once the order has an id.
	 *
	 * @param  int $order_id Order id.
	 * @return void
	 */
	public function note_new_order( $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( $order && $this->note( $order ) ) {
			Sync::pause();
			$order->save();
			Sync::resume();
		}
	}

	/**
	 * One order note per flagged order and class. The caller saves the order.
	 *
	 * @param  \WC_Order $order Order.
	 * @return bool Whether a note was added.
	 */
	private function note( $order ) {
		$flag = self::for_order( $order );
		if ( ! $flag || (string) $order->get_meta( self::META_NOTED ) === $flag['class'] ) {
			return false;
		}
		/* translators: %s: class such as "Bad bot". */
		$order->add_order_note( sprintf( __( 'SocialScore: not from a human (%s). Check this order.', 'socialscore' ), self::text( $flag['class'] ) ) );
		$order->update_meta_data( self::META_NOTED, $flag['class'] );
		return true;
	}

	/**
	 * Called when an order has reached SocialScore: asks for the class of its visit after a short wait.
	 *
	 * @param  \WC_Order $order Order.
	 * @return void
	 */
	public static function after_order_sync( $order ) {
		if ( ! function_exists( 'as_schedule_single_action' ) || '' !== (string) $order->get_meta( self::META_VISIT ) ) {
			return;
		}
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK_SESSION, array( (int) $order->get_id(), 0 ), Config::ACTION_GROUP ) ) {
			return;
		}
		as_schedule_single_action( time() + self::DELAYS[0], self::HOOK_SESSION, array( (int) $order->get_id(), 0 ), Config::ACTION_GROUP );
	}

	/**
	 * Asks SocialScore for the class of the visit behind an order. A visit that is not there yet is asked for again later;
	 * the Pixel being off or the store gone ends it.
	 *
	 * @param  int $order_id Order id.
	 * @param  int $attempt  Attempts made so far.
	 * @return void
	 */
	public function handle_session( $order_id, $attempt = 0 ) {
		if ( ! Connection::is_connected() ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}
		$path     = '/api/v2/stores/' . rawurlencode( (string) Connection::store_id() ) . '/orders/' . rawurlencode( (string) (int) $order_id ) . '/session';
		$response = ( new Api_Client( 5 ) )->request( 'GET', $path );

		if ( ! is_wp_error( $response ) && 404 === $response['status'] ) {
			// No visit is tied to the order yet: look again later, up to the last delay. Any other 404 is final.
			if ( 'session_not_found' === Sync::error_code( $response ) && (int) $attempt + 1 < count( self::DELAYS ) && function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + self::DELAYS[ (int) $attempt + 1 ], self::HOOK_SESSION, array( (int) $order_id, (int) $attempt + 1 ), Config::ACTION_GROUP );
			}
			return;
		}
		if ( Sync::settle( $response, self::HOOK_SESSION, array( (int) $order_id ), (int) $attempt ) && is_array( $response['data'] ) ) {
			$this->apply_session( $order, $response['data'] );
		}
	}

	/**
	 * Writes the Pixel's class of the visit on the order: a flag for a bot, an agent or a known bot, and no flag (or a
	 * removed one) for a person. The class of a later session replaces the one of an earlier look, as in SocialScore. A
	 * flag the module wrote itself from the checkout request is kept: the Pixel never saw that bot run JavaScript.
	 *
	 * @param  \WC_Order $order Order.
	 * @param  array     $data  Answer of the session route.
	 * @return void
	 */
	private function apply_session( $order, array $data ) {
		$class = isset( $data['class'] ) && is_string( $data['class'] ) ? $data['class'] : '';
		if ( ! in_array( $class, array( 'human', 'lurker', Bot_Detector::INFO_BOT, Bot_Detector::AI_AGENT, Bot_Detector::BAD_BOT ), true ) ) {
			return;
		}
		$order->update_meta_data( self::META_VISIT, $class );
		$current = (string) $order->get_meta( self::META_SOURCE );
		if ( Bot_Detector::is_not_human( $class ) ) {
			$signals = self::clean_signals( $data['signals'] ?? array() );
			if ( ! empty( $data['device_farm'] ) && ! in_array( 'device_farm', $signals, true ) ) {
				$signals[] = 'device_farm';
			}
			$order->update_meta_data( self::META_CLASS, $class );
			$order->update_meta_data( self::META_SIGNALS, $signals );
			$order->update_meta_data( self::META_SOURCE, self::SOURCE_PIXEL );
		} elseif ( self::SOURCE_SERVER !== $current ) {
			$order->delete_meta_data( self::META_CLASS );
			$order->delete_meta_data( self::META_SIGNALS );
			$order->delete_meta_data( self::META_SOURCE );
			$order->delete_meta_data( self::META_NOTED );
		}
		$this->note( $order );
		Sync::pause();
		$order->save();
		Sync::resume();
	}
}
