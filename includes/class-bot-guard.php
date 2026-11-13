<?php
/**
 * What the shop does with a bad bot: flag it, show it substitute data, or stop the request (requirements section 5).
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * The detection is Bot_Detector, on every storefront request of a visitor who is not logged in, with no cookie and
 * no stored id. The decision is not made here: the mode is the store's setting in SocialScore (`bot_mode` in
 * `GET /api/v2/stores/{id}/pixel`), read through Pixel::state() and cached for ten minutes. Until it is known the mode
 * is `flag`, which changes nothing a visitor sees. Only a bad bot is ever blocked or shown substitute data: a declared
 * search engine, a link preview and an AI agent are served as they are.
 *
 * Substitute data is a change of what is shown (price text, variation JSON, Store API product prices), never of what
 * is charged: the cart and the checkout read the real prices, so a person who is taken for a bot by mistake pays the
 * right amount.
 */
class Bot_Guard {


	const MODE_FLAG       = 'flag';
	const MODE_SUBSTITUTE = 'substitute';
	const MODE_BLOCK      = 'block';
	// The substitute price is the real one times a factor between 0.85 and 1.15, fixed for a product and a day.
	const FACTOR_BASE   = 85;
	const FACTOR_SPREAD = 31;

	/**
	 * Classification of this request, made once.
	 *
	 * @var array|null
	 */
	private static $current = null;

	/**
	 * The class and signals of this request.
	 *
	 * @return array{class:string,signals:string[],agent_system:?string,agent_purpose:?string}
	 */
	public static function current() {
		if ( null === self::$current ) {
			self::$current = Bot_Detector::classify( self::server_headers() );
		}
		return self::$current;
	}

	/**
	 * Forgets the classification (a new request in a long process, and the tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$current = null;
	}

	/**
	 * The request headers the detector reads.
	 *
	 * @return array<string,string>
	 */
	private static function server_headers() {
		$out = array();
		foreach ( array( 'HTTP_USER_AGENT', 'HTTP_SIGNATURE_AGENT', 'HTTP_ACCEPT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_SEC_FETCH_MODE', 'HTTP_SEC_FETCH_SITE', 'HTTPS' ) as $key ) {
			if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			}
		}
		return $out;
	}

	/**
	 * Registers the hooks. Nothing runs in the admin.
	 *
	 * @return void
	 */
	public function register() {
		if ( is_admin() ) {
			return;
		}
		add_action( 'init', array( $this, 'inspect' ), 1 );
		add_filter( 'woocommerce_get_availability_text', array( $this, 'availability_text' ), 10, 2 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_html' ), 10, 2 );
		add_filter( 'woocommerce_available_variation', array( $this, 'variation' ), 10, 3 );
		add_filter( 'woocommerce_structured_data_product_offer', array( $this, 'structured_offer' ), 10, 2 );
		add_filter( 'rest_post_dispatch', array( $this, 'store_api' ), 10, 3 );
	}

	/**
	 * Whether this request is one the guard looks at: a storefront request of a visitor who is not logged in. Cron, the
	 * command line, the REST API outside the Store API (payment and SocialScore callbacks) and `wc-api` callbacks are left
	 * alone, so an integration that uses an HTTP library is never taken for a scraper.
	 *
	 * @return bool
	 */
	private function should_inspect() {
		if ( is_admin() || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || defined( 'WP_CLI' ) || is_user_logged_in() ) {
			return false;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
     // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only the presence of a callback marker is read.
		$route    = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : '';
		$callback = isset( $_GET['wc-api'] ) || ( '' !== $route && 0 !== strpos( $route, '/wc/store' ) );
     // phpcs:enable WordPress.Security.NonceVerification.Recommended
		$skip = $callback
		|| ( false !== strpos( $path, '/wp-json/' ) && false === strpos( $path, '/wp-json/wc/store' ) )
		|| false !== strpos( $path, 'xmlrpc.php' )
		|| false !== strpos( $path, 'wp-cron.php' );
		return ! (bool) apply_filters( 'socialscore_bot_guard_skip', $skip, $path );
	}

	/**
	 * The mode of the store: flag until SocialScore says another.
	 *
	 * @return string
	 */
	private function mode() {
		$state = Pixel::state();
		return isset( $state['bot_mode'] ) && in_array( $state['bot_mode'], array( self::MODE_SUBSTITUTE, self::MODE_BLOCK ), true ) ? $state['bot_mode'] : self::MODE_FLAG;
	}

	/**
	 * Whether the module acts on this request: it is a bad bot, the store is connected and the Pixel is on. Asked only
	 * once a bad bot is seen, so a person's request never waits for SocialScore.
	 *
	 * @return bool
	 */
	private function acts() {
		if ( Bot_Detector::BAD_BOT !== self::current()['class'] || ! Connection::is_connected() ) {
			return false;
		}
		$state = Pixel::state();
		return ! empty( $state['enabled'] );
	}

	/**
	 * Early in the request: counts a bot, and stops a bad bot when the store's mode is `block`.
	 *
	 * @return void
	 */
	public function inspect() {
		$class = self::current()['class'];
		if ( ! Bot_Detector::is_not_human( $class ) || ! $this->should_inspect() || ! Connection::is_connected() ) {
			return;
		}
		$state = Pixel::state();
		if ( empty( $state['enabled'] ) ) {
			return;
		}
		Bot_Stats::record( $class );
		if ( Bot_Detector::BAD_BOT === $class && self::MODE_BLOCK === $this->mode() ) {
			$this->block();
		}
	}

	/**
	 * Ends the request with 403.
	 *
	 * @return void
	 */
	private function block() {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		wp_die(
			esc_html__( 'Access denied.', 'socialscore' ),
			esc_html__( 'Access denied', 'socialscore' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Stock without quantities for a bad bot, in every mode: only whether the item is in stock.
	 *
	 * @param  string      $text    Availability text.
	 * @param  \WC_Product $product Product.
	 * @return string
	 */
	public function availability_text( $text, $product ) {
		if ( ! $this->should_inspect() || ! $this->acts() || ! is_object( $product ) ) {
			return $text;
		}
		return $product->is_in_stock() ? __( 'In stock', 'socialscore' ) : __( 'Out of stock', 'socialscore' );
	}

	/**
	 * Substitute mode: the price shown to a bad bot.
	 *
	 * @param  string      $html    Price HTML.
	 * @param  \WC_Product $product Product.
	 * @return string
	 */
	public function price_html( $html, $product ) {
		if ( ! is_object( $product ) || ! $this->should_inspect() || ! $this->acts() || self::MODE_SUBSTITUTE !== $this->mode() ) {
			return $html;
		}
		$price = $product->get_price();
		return '' === (string) $price ? $html : '<span class="price">' . wc_price( self::substitute_price( $price, $product->get_id() ) ) . '</span>';
	}

	/**
	 * Substitute mode: the price in the page's structured data (JSON-LD) must not give the real one away.
	 *
	 * @param  array       $markup  Offer markup.
	 * @param  \WC_Product $product Product.
	 * @return array
	 */
	public function structured_offer( $markup, $product ) {
		if ( ! is_array( $markup ) || ! is_object( $product ) || ! $this->should_inspect() || ! $this->acts() || self::MODE_SUBSTITUTE !== $this->mode() ) {
			return $markup;
		}
		$id = $product->get_id();
		foreach ( array( 'price', 'lowPrice', 'highPrice' ) as $key ) {
			if ( isset( $markup[ $key ] ) && is_numeric( $markup[ $key ] ) ) {
				$markup[ $key ] = (string) self::substitute_price( $markup[ $key ], $id );
			}
		}
		// WooCommerce prints `priceSpecification` as a list of specifications; a single one is also taken.
		if ( isset( $markup['priceSpecification'] ) && is_array( $markup['priceSpecification'] ) ) {
			$specs = $markup['priceSpecification'];
			if ( isset( $specs['price'] ) ) {
				$specs = array( $specs );
				$one   = true;
			}
			foreach ( $specs as $i => $spec ) {
				if ( is_array( $spec ) && isset( $spec['price'] ) && is_numeric( $spec['price'] ) ) {
					$specs[ $i ]['price'] = (string) self::substitute_price( $spec['price'], $id );
				}
			}
			$markup['priceSpecification'] = isset( $one ) ? $specs[0] : $specs;
		}
		return $markup;
	}

	/**
	 * Variation data the product page prints as JSON: no quantity limits for a bad bot, and, in substitute mode, a
	 * substitute price.
	 *
	 * @param  array       $data      Variation data.
	 * @param  \WC_Product $product   Variable product.
	 * @param  \WC_Product $variation Variation.
	 * @return array
	 */
	public function variation( $data, $product, $variation ) {
		unset( $product );
		if ( ! is_array( $data ) || ! is_object( $variation ) || ! $this->should_inspect() || ! $this->acts() ) {
			return $data;
		}
		$data['max_qty'] = '';
		$in_stock        = $variation->is_in_stock();
		/* translators: Stock text shown to a bot, no quantity. */
		$data['availability_html'] = '<p class="stock ' . ( $in_stock ? 'in-stock' : 'out-of-stock' ) . '">' . esc_html( $in_stock ? __( 'In stock', 'socialscore' ) : __( 'Out of stock', 'socialscore' ) ) . '</p>';
		if ( self::MODE_SUBSTITUTE === $this->mode() ) {
			$id = $variation->get_id();
			foreach ( array( 'display_price', 'display_regular_price' ) as $key ) {
				if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) {
					$data[ $key ] = self::substitute_price( $data[ $key ], $id );
				}
			}
			$data['price_html'] = '<span class="price">' . wc_price( self::substitute_price( $variation->get_price(), $id ) ) . '</span>';
		}
		return $data;
	}

	/**
	 * The Store API product answers (`/wc/store/v1/products`): no remaining stock for a bad bot, and, in substitute
	 * mode, substitute prices (the API gives prices as integers in the smallest currency unit).
	 *
	 * @param  \WP_HTTP_Response $response Response.
	 * @param  \WP_REST_Server   $server   Server.
	 * @param  \WP_REST_Request  $request  Request.
	 * @return \WP_HTTP_Response
	 */
	public function store_api( $response, $server, $request ) {
		unset( $server );
		if ( ! is_object( $response ) || ! is_object( $request ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'set_data' ) ) {
			return $response;
		}
		if ( 0 !== strpos( (string) $request->get_route(), '/wc/store/' ) || ! $this->should_inspect() || ! $this->acts() ) {
			return $response;
		}
		$data = $response->get_data();
		if ( is_array( $data ) ) {
			$response->set_data( $this->scrub( $data, self::MODE_SUBSTITUTE === $this->mode() ) );
		}
		return $response;
	}

	/**
	 * Walks a Store API answer and changes the stock and, when asked, the price fields of every product in it.
	 *
	 * @param  array $data       Decoded answer.
	 * @param  bool  $substitute Whether to change prices.
	 * @return array
	 */
	private function scrub( array $data, $substitute ) {
		if ( array_key_exists( 'low_stock_remaining', $data ) ) {
			$data['low_stock_remaining'] = null;
		}
		if ( $substitute && isset( $data['prices'], $data['id'] ) && is_array( $data['prices'] ) ) {
			foreach ( array( 'price', 'regular_price', 'sale_price' ) as $key ) {
				if ( isset( $data['prices'][ $key ] ) && ctype_digit( (string) $data['prices'][ $key ] ) ) {
					$data['prices'][ $key ] = (string) (int) round( self::substitute_price( (int) $data['prices'][ $key ], $data['id'] ) );
				}
			}
		}
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->scrub( $value, $substitute );
			}
		}
		return $data;
	}

	/**
	 * The price shown instead of the real one: the real price times a factor between 0.85 and 1.15 that is the same for
	 * a product all day and differs between sites, so it cannot be averaged out. A price of zero or less stays.
	 *
	 * @param  float|int|string $price      Real price.
	 * @param  int|string       $product_id Product id.
	 * @return float
	 */
	public static function substitute_price( $price, $product_id ) {
		$price = (float) $price;
		if ( $price <= 0 ) {
			return $price;
		}
		$seed   = abs( crc32( (string) $product_id . '|' . gmdate( 'Y-m-d' ) . '|' . wp_salt() ) );
		$factor = ( self::FACTOR_BASE + ( $seed % self::FACTOR_SPREAD ) ) / 100;
		return round( $price * $factor, 2 );
	}
}
