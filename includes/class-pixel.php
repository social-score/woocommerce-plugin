<?php
/**
 * SocialScore Pixel on the storefront: loader, shop events, and the order to visit link.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * The Pixel writes nothing to the visitor's device and sends no visitor or session id; the server derives the session.
 * This class only prints the loader and the shop events when the Pixel is on. The purchase event (with the order id)
 * is what links an order to its visit; no cookie is copied onto the order.
 */
class Pixel {

	const TRANSIENT      = 'socialscore_pixel_';
	const STATE_TTL      = 600;
	const FAILED_TTL     = 60;
	const LOOKUP_TIMEOUT = 2;
	// Legacy order meta of plugin versions before contract v4. Nothing writes them any more; the privacy
	// export and eraser and the uninstall still handle orders that carry them.
	const META_VISITOR    = '_ss_pixel_visitor';
	const META_SESSION    = '_ss_pixel_session';
	const META_LOGIN      = '_ss_pixel_login';
	const LOGIN_TTL       = 600;
	const META_PURCHASE   = '_ss_pixel_purchase_sent';
	const SESSION_EVENTS  = 'socialscore_pixel_events';
	const MAX_QUEUED      = 20;
	const HANDLE_LOADER   = 'socialscore-pixel';
	const HANDLE_EVENTS   = 'socialscore-pixel-events';
	const SITE_ID_PATTERN = '/^ps_[a-z2-7]{16}$/D';
	const SOURCE_PATTERN  = '/^(shopify)?$/D';
	const JSON_FLAGS      = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

	/**
	 * Site id of this request, null until first asked.
	 *
	 * @var string|null
	 */
	private $site_id = null;

	/**
	 * Events that came before the loader was enqueued: block themes render the page body, and with it the
	 * thank-you hook, before wp_enqueue_scripts runs.
	 *
	 * @var array[]
	 */
	private $pending = array();

	/**
	 * Orders whose purchase event waits in $pending: they are marked as sent only once the event is printed.
	 *
	 * @var \WC_Order[]
	 */
	private $pending_orders = array();

	/**
	 * Ids of the orders whose purchase event this request already made.
	 *
	 * @var bool[]
	 */
	private $purchased = array();

	/**
	 * Whether this request already made its begin_checkout event.
	 *
	 * @var bool
	 */
	private $checkout_started = false;

	/**
	 * Registers hooks. The login is noted in every request that logs a user in; the storefront
	 * output only outside the admin.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_login', array( $this, 'remember_login' ), 10, 2 );

		if ( is_admin() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'remember_add_to_cart' ), 10, 4 );
		add_action( 'woocommerce_thankyou', array( $this, 'purchase' ), 10, 1 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'begin_checkout_classic' ), 10, 0 );
		add_filter( 'woocommerce_loop_add_to_cart_args', array( $this, 'loop_button_args' ), 10, 2 );
	}

	/**
	 * A successful login: remember it for the user's next storefront page, without a lookup of the Pixel state. The login request itself is
	 * usually a redirect, so the event is printed by the page that follows. Only the time is kept, never an id
	 * in the event.
	 *
	 * @param string   $user_login Login name.
	 * @param \WP_User $user       User.
	 * @return void
	 */
	public function remember_login( $user_login, $user = null ) {
		unset( $user_login );
		if ( ! is_object( $user ) || empty( $user->ID ) || ! Connection::is_connected() ) {
			return;
		}
		// Never ask the API here: a login, wp-admin ones included, must not wait for a lookup. Only a cached "off"
		// skips the note; with a cold cache it is kept and the next storefront page decides.
		$cached = get_transient( self::TRANSIENT . md5( (string) Connection::store_id() ) );
		if ( is_array( $cached ) && empty( $cached['enabled'] ) ) {
			return;
		}
		update_user_meta( (int) $user->ID, self::META_LOGIN, time() );
	}

	/**
	 * The login event of the current user, once, when the login was recent.
	 *
	 * @return array|null The event, or null when there is none.
	 */
	private function login_event() {
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return null;
		}
		$at = (int) get_user_meta( $user_id, self::META_LOGIN, true );
		if ( 0 === $at ) {
			return null;
		}
		delete_user_meta( $user_id, self::META_LOGIN );
		return ( time() - $at ) <= self::LOGIN_TTL ? self::event( 'login', array() ) : null;
	}

	/**
	 * Pixel state of the connected store: array( enabled, site_id ). Cached for ten minutes; a failed or running lookup
	 * counts as off for one minute, and the lookup waits at most two seconds, so a slow API cannot slow every page.
	 *
	 * @return array{enabled:bool,site_id:string}
	 */
	public static function state() {
		$off = array(
			'enabled' => false,
			'site_id' => '',
		);
		if ( ! Connection::is_connected() ) {
			return $off;
		}
		$key    = self::TRANSIENT . md5( (string) Connection::store_id() );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['enabled'], $cached['site_id'] ) ) {
			return $cached;
		}

		// Mark the lookup as running before it starts: requests that arrive while it waits see "off" and
		// do not start their own lookup, so a slow API costs one short wait per minute, not one per page.
		set_transient( $key, $off, self::FAILED_TTL );
		$response = ( new Api_Client( self::LOOKUP_TIMEOUT ) )->request( 'GET', '/api/v2/stores/' . rawurlencode( (string) Connection::store_id() ) . '/pixel' );
		$state    = $off;
		$ttl      = self::FAILED_TTL;
		if ( ! is_wp_error( $response ) && 200 === $response['status'] && is_array( $response['data'] ) ) {
			$site_id = isset( $response['data']['site_id'] ) && is_string( $response['data']['site_id'] ) ? $response['data']['site_id'] : '';
			$state   = array(
				'enabled' => ! empty( $response['data']['enabled'] ) && 1 === preg_match( self::SITE_ID_PATTERN, $site_id ),
				'site_id' => 1 === preg_match( self::SITE_ID_PATTERN, $site_id ) ? $site_id : '',
			);
			$ttl     = self::STATE_TTL;
		}
		set_transient( $key, $state, $ttl );
		return $state;
	}

	/**
	 * Drops the cached state, after the admin switched the Pixel.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_transient( self::TRANSIENT . md5( (string) Connection::store_id() ) );
	}

	/**
	 * The loader with its placeholders filled in. Both values are validated first and a value that fails is
	 * never rendered: it would run as script on the shop page.
	 *
	 * @param string $site_id Site id.
	 * @param string $source  Consent source: empty, or shopify.
	 * @return string Empty when a value is not valid or the loader file is missing.
	 */
	public static function render_loader( $site_id, $source = '' ) {
		if ( 1 !== preg_match( self::SITE_ID_PATTERN, (string) $site_id ) || 1 !== preg_match( self::SOURCE_PATTERN, (string) $source ) ) {
			return '';
		}
		$file = SOCIALSCORE_DIR . 'assets/pixel/loader.min.js';
		if ( ! is_readable( $file ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file shipped with the plugin.
		$loader = (string) file_get_contents( $file );
		return str_replace( array( '{{SITE_ID}}', '{{CONSENT_SOURCE}}' ), array( esc_attr( $site_id ), esc_attr( $source ) ), $loader );
	}

	/**
	 * Site id when the Pixel is on and the loader is printed on this request, else an empty string.
	 *
	 * @return string
	 */
	private function active_site_id() {
		if ( null === $this->site_id ) {
			$state         = self::state();
			$this->site_id = $state['enabled'] ? $state['site_id'] : '';
		}
		return $this->site_id;
	}

	/**
	 * JS that pushes events to the Pixel queue; the queue exists before the script loads.
	 *
	 * @param array[] $events Events, each array( type, data ).
	 * @return string
	 */
	private static function push_js( array $events ) {
		$json = array();
		foreach ( $events as $event ) {
			$json[] = wp_json_encode( $event, self::JSON_FLAGS );
		}
		return 'window.ssPixel=window.ssPixel||[];window.ssPixel.push(' . implode( ',', $json ) . ');';
	}

	/**
	 * One event in the shape ssPixel.push takes.
	 *
	 * @param string $type Event type.
	 * @param array  $data Event data.
	 * @return array
	 */
	private static function event( $type, array $data ) {
		return array(
			'type' => $type,
			'data' => $data ? $data : new \stdClass(),
		);
	}

	/**
	 * Loader and events on the storefront, only while the Pixel is on.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( is_admin() || '' === $this->active_site_id() ) {
			return;
		}
		$loader = self::render_loader( $this->active_site_id() );
		if ( '' === $loader ) {
			return;
		}

		// A script without a source: it only carries inline code, printed in the footer so late events
		// (the thank-you page) can still be added.
		wp_register_script( self::HANDLE_LOADER, false, array(), SOCIALSCORE_VERSION, true );
		wp_enqueue_script( self::HANDLE_LOADER );
		wp_add_inline_script( self::HANDLE_LOADER, $loader );

		$events = $this->page_events();
		if ( $events ) {
			wp_add_inline_script( self::HANDLE_LOADER, self::push_js( $events ) );
		}
		foreach ( $this->pending_orders as $order ) {
			$this->mark_purchase_sent( $order );
		}
		$this->pending_orders = array();
		$this->enqueue_events_script();
	}

	/**
	 * Events known when the page is built: product view, checkout start, and an add to cart that
	 * reloaded the page.
	 *
	 * @return array[]
	 */
	private function page_events() {
		$events        = $this->pending;
		$this->pending = array();

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product ) {
				$events[] = self::event(
					'view_item',
					array(
						'item_id'  => (string) $product->get_id(),
						'price'    => (float) $product->get_price(),
						'currency' => (string) get_woocommerce_currency(),
					)
				);
			}
		}

		// The Checkout block has no classic checkout form hook, so its page start is taken here. The classic
		// checkout reports on woocommerce_before_checkout_form.
		if ( function_exists( 'is_checkout' ) && is_checkout() && function_exists( 'has_block' ) && has_block( 'woocommerce/checkout', get_queried_object_id() ) ) {
			$event = $this->checkout_event();
			if ( $event ) {
				$events[] = $event;
			}
		}

		$login = $this->login_event();
		if ( $login ) {
			$events[] = $login;
		}

		$session = function_exists( 'WC' ) ? WC()->session : null;
		if ( $session ) {
			$queued = $session->get( self::SESSION_EVENTS );
			if ( is_array( $queued ) && $queued ) {
				$session->set( self::SESSION_EVENTS, null );
				$events = array_merge( $events, array_slice( $queued, 0, self::MAX_QUEUED ) );
			}
		}
		return $events;
	}

	/**
	 * begin_checkout once per request for a cart with items, never on the thank-you or the pay page.
	 *
	 * @return array|null The event, or null when there is nothing to report.
	 */
	private function checkout_event() {
		if ( $this->checkout_started || ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) || ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) ) {
			return null;
		}
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart || $cart->is_empty() ) {
			return null;
		}
		$this->checkout_started = true;
		return self::event(
			'begin_checkout',
			array(
				'value'    => (float) $cart->get_total( 'edit' ),
				'currency' => (string) get_woocommerce_currency(),
				'items'    => count( $cart->get_cart() ),
			)
		);
	}

	/**
	 * Classic checkout form: the checkout was started. It runs while the page body is built, after the loader is
	 * enqueued in a classic theme; a request where it is not yet enqueued gets the event with the loader.
	 *
	 * @return void
	 */
	public function begin_checkout_classic() {
		if ( '' === $this->active_site_id() ) {
			return;
		}
		$event = $this->checkout_event();
		if ( ! $event ) {
			return;
		}
		if ( wp_script_is( self::HANDLE_LOADER, 'enqueued' ) ) {
			wp_add_inline_script( self::HANDLE_LOADER, self::push_js( array( $event ) ) );
		} else {
			$this->pending[] = $event;
		}
	}

	/**
	 * The small bundle that watches add to cart in the browser, classic and block.
	 *
	 * @return void
	 */
	private function enqueue_events_script() {
		$asset = SOCIALSCORE_DIR . 'build/pixel-events.asset.php';
		if ( ! is_readable( $asset ) ) {
			return;
		}
		$meta = require $asset;
		// No forced jquery or wp-data: they would load React, the data stores and jQuery on every page. The bundle
		// uses window.jQuery and window.wp.data when the shop has loaded them, and waits for them otherwise.
		$deps = array_values( array_unique( array_merge( (array) $meta['dependencies'], array( self::HANDLE_LOADER ) ) ) );
		wp_enqueue_script( self::HANDLE_EVENTS, SOCIALSCORE_URL . 'build/pixel-events.js', $deps, $meta['version'], true );
		$config = array(
			'currency' => (string) get_woocommerce_currency(),
			'storeApi' => esc_url_raw( rest_url( 'wc/store/v1/' ) ),
		);
		wp_add_inline_script( self::HANDLE_EVENTS, 'window.socialscorePixel=' . wp_json_encode( $config, self::JSON_FLAGS ) . ';', 'before' );
	}

	/**
	 * Price a visitor sees for a product, as a number.
	 *
	 * @param \WC_Product|false $product Product.
	 * @return float
	 */
	private static function price( $product ) {
		return $product ? (float) $product->get_price() : 0.0;
	}

	/**
	 * Lets the browser report the price of a product added from a list with AJAX.
	 *
	 * @param array       $args    Button arguments.
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	public function loop_button_args( $args, $product ) {
		if ( '' !== $this->active_site_id() && is_array( $args ) && $product ) {
			$args['attributes']['data-ss-price'] = (string) self::price( $product );
		}
		return $args;
	}

	/**
	 * Add to cart that reloads the page: keep the event in the WooCommerce session for the next page.
	 * AJAX and REST adds are reported by the browser instead, so they are not queued here.
	 *
	 * @param string $cart_item_key Cart key.
	 * @param int    $product_id    Product id.
	 * @param int    $quantity      Quantity added.
	 * @param int    $variation_id  Variation id.
	 * @return void
	 */
	public function remember_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0 ) {
		unset( $cart_item_key );
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || '' === $this->active_site_id() ) {
			return;
		}
		$session = function_exists( 'WC' ) ? WC()->session : null;
		if ( ! $session ) {
			return;
		}
		$queued = $session->get( self::SESSION_EVENTS );
		$queued = is_array( $queued ) ? $queued : array();
		$data   = array(
			'item_id'  => (string) (int) $product_id,
			'quantity' => max( 1, (int) $quantity ),
			'price'    => self::price( wc_get_product( $variation_id ? $variation_id : $product_id ) ),
			'currency' => (string) get_woocommerce_currency(),
		);
		if ( $variation_id ) {
			$data['variant_id'] = (string) (int) $variation_id;
		}
		$queued[] = self::event( 'add_to_cart', $data );
		$session->set( self::SESSION_EVENTS, array_slice( $queued, -self::MAX_QUEUED ) );
	}

	/**
	 * Thank-you page: one purchase event per order. The flag in the order meta keeps a reload or a
	 * second visit from counting it again. It carries no personal data. The flag is set only after the event is
	 * printed, so a request that prints nothing does not use up the order's one purchase event.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function purchase( $order_id ) {
		if ( '' === $this->active_site_id() ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order || $order->get_meta( self::META_PURCHASE ) || isset( $this->purchased[ (int) $order_id ] ) ) {
			return;
		}
		// A shop manager who opens the thank-you page is not the buyer: no event, and the order's one purchase
		// event stays for the customer.
		$viewer = (int) get_current_user_id();
		if ( $viewer > 0 && $viewer !== (int) $order->get_customer_id() && current_user_can( Config::CAPABILITY ) ) {
			return;
		}
		$this->purchased[ (int) $order_id ] = true;
		$event                              = self::event(
			'purchase',
			array(
				'order_external_id' => (string) $order->get_id(),
				'value'             => (float) $order->get_total(),
				'currency'          => (string) $order->get_currency(),
			)
		);
		if ( wp_script_is( self::HANDLE_LOADER, 'enqueued' ) ) {
			wp_add_inline_script( self::HANDLE_LOADER, self::push_js( array( $event ) ) );
			$this->mark_purchase_sent( $order );
		} else {
			// The loader is not enqueued yet: enqueue() prints the event and marks the order, or drops both.
			$this->pending[]        = $event;
			$this->pending_orders[] = $order;
		}
	}

	/**
	 * Remembers that the purchase event of an order was printed.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function mark_purchase_sent( $order ) {
		$order->update_meta_data( self::META_PURCHASE, '1' );
		$order->save_meta_data();
	}
}
