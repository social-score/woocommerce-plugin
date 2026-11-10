<?php
/**
 * SocialScore Pixel on the storefront: loader, shop events, and the order to visit link.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * The Pixel script itself decides what to collect from the visitor's consent; nothing here reads consent.
 * The server only (a) prints the loader and the shop events when the Pixel is on and (b) copies the first-party
 * cookies ss_pv and ss_ps onto the order when it is made. The browser never links a visit to an order.
 */
class Pixel {

	const TRANSIENT       = 'socialscore_pixel_';
	const STATE_TTL       = 600;
	const FAILED_TTL      = 60;
	const META_VISITOR    = '_ss_pixel_visitor';
	const META_SESSION    = '_ss_pixel_session';
	const META_PURCHASE   = '_ss_pixel_purchase_sent';
	const SESSION_EVENTS  = 'socialscore_pixel_events';
	const MAX_QUEUED      = 20;
	const HANDLE_LOADER   = 'socialscore-pixel';
	const HANDLE_EVENTS   = 'socialscore-pixel-events';
	const SITE_ID_PATTERN = '/^ps_[a-z2-7]{16}$/D';
	const SOURCE_PATTERN  = '/^(shopify)?$/D';
	const UUID_PATTERN    = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';
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
	 * Registers hooks. The order link works in every request that creates an order; the storefront
	 * output only outside the admin.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'link_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'link_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'link_order_and_save' ), 10, 1 );

		if ( is_admin() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'remember_add_to_cart' ), 10, 4 );
		add_action( 'woocommerce_thankyou', array( $this, 'purchase' ), 10, 1 );
		add_filter( 'woocommerce_loop_add_to_cart_args', array( $this, 'loop_button_args' ), 10, 2 );
	}

	/**
	 * Valid UUIDv4 in lower case, or an empty string.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	public static function uuid( $value ) {
		$value = is_string( $value ) ? strtolower( $value ) : '';
		return 1 === preg_match( self::UUID_PATTERN, $value ) ? $value : '';
	}

	/**
	 * Value of a cookie the Pixel snippet writes, when it is a UUIDv4.
	 *
	 * @param string $name Cookie name.
	 * @return string
	 */
	public static function cookie_uuid( $name ) {
		if ( ! isset( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
			return '';
		}
		return self::uuid( sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) );
	}

	/**
	 * Copies the visit cookies onto an order that is being created. A value that is not a UUIDv4 is ignored
	 * and an earlier valid value stays.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool True when the order meta changed.
	 */
	public function link_order( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return false;
		}
		$changed = false;
		foreach ( array(
			self::META_VISITOR => 'ss_pv',
			self::META_SESSION => 'ss_ps',
		) as $meta_key => $cookie ) {
			$value = self::cookie_uuid( $cookie );
			if ( '' !== $value && $order->get_meta( $meta_key ) !== $value ) {
				$order->update_meta_data( $meta_key, $value );
				$changed = true;
			}
		}
		return $changed;
	}

	/**
	 * Block checkout, after the order exists: the same link, written through.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function link_order_and_save( $order ) {
		if ( $this->link_order( $order ) ) {
			$order->save_meta_data();
		}
	}

	/**
	 * Pixel state of the connected store: array( enabled, site_id ). Cached for ten minutes; a failed lookup
	 * counts as off for one minute so a slow API cannot slow every page.
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

		$response = ( new Api_Client() )->request( 'GET', '/api/v2/stores/' . rawurlencode( (string) Connection::store_id() ) . '/pixel' );
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
			'data' => $data,
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

		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() && ! is_checkout_pay_page() ) {
			$cart = function_exists( 'WC' ) ? WC()->cart : null;
			if ( $cart && ! $cart->is_empty() ) {
				$events[] = self::event(
					'begin_checkout',
					array(
						'value'    => (float) $cart->get_total( 'edit' ),
						'currency' => (string) get_woocommerce_currency(),
						'items'    => count( $cart->get_cart() ),
					)
				);
			}
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
		$deps = array_values( array_unique( array_merge( (array) $meta['dependencies'], array( self::HANDLE_LOADER, 'jquery', 'wp-data' ) ) ) );
		wp_enqueue_script( self::HANDLE_EVENTS, SOCIALSCORE_URL . 'build/pixel-events.js', $deps, $meta['version'], true );
		wp_add_inline_script(
			self::HANDLE_EVENTS,
			'window.socialscorePixel=' . wp_json_encode( array( 'currency' => (string) get_woocommerce_currency() ), self::JSON_FLAGS ) . ';',
			'before'
		);
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
		$queued   = $session->get( self::SESSION_EVENTS );
		$queued   = is_array( $queued ) ? $queued : array();
		$queued[] = self::event(
			'add_to_cart',
			array(
				'item_id'  => (string) (int) $product_id,
				'quantity' => max( 1, (int) $quantity ),
				'price'    => self::price( wc_get_product( $variation_id ? $variation_id : $product_id ) ),
				'currency' => (string) get_woocommerce_currency(),
			)
		);
		$session->set( self::SESSION_EVENTS, array_slice( $queued, -self::MAX_QUEUED ) );
	}

	/**
	 * Thank-you page: one purchase event per order. The flag in the order meta keeps a reload or a
	 * second visit from counting it again. It carries no personal data.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function purchase( $order_id ) {
		if ( '' === $this->active_site_id() ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order || $order->get_meta( self::META_PURCHASE ) ) {
			return;
		}
		$order->update_meta_data( self::META_PURCHASE, '1' );
		$order->save_meta_data();
		$event = self::event(
			'purchase',
			array(
				'order_external_id' => (string) $order->get_id(),
				'value'             => (float) $order->get_total(),
				'currency'          => (string) $order->get_currency(),
			)
		);
		if ( wp_script_is( self::HANDLE_LOADER, 'enqueued' ) ) {
			wp_add_inline_script( self::HANDLE_LOADER, self::push_js( array( $event ) ) );
		} else {
			$this->pending[] = $event;
		}
	}
}
