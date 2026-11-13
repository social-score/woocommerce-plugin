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
	const FACTOR_BASE = 85;
	// 30 steps: 0.85 to 0.99 and 1.01 to 1.15. Never exactly 1.00, which would show the real price.
	const FACTOR_SPREAD = 30;

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
			self::$current = self::verified( Bot_Detector::classify( self::server_headers() ) );
		}
		return self::$current;
	}

	/**
	 * A declared Googlebot or Bingbot is checked by DNS (Bot_Verifier). One that fails is a bad bot; one that cannot be
	 * checked stays a known bot with the signal `unverified_bot`.
	 *
	 * @param  array $verdict Result of Bot_Detector::classify().
	 * @return array
	 */
	private static function verified( array $verdict ) {
		if ( Bot_Detector::INFO_BOT !== $verdict['class'] || ! Connection::is_connected() ) {
			return $verdict;
		}
		$domains = Bot_Verifier::domains_for( isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' );
		if ( ! $domains ) {
			return $verdict;
		}
		$ip     = (string) apply_filters( 'socialscore_client_ip', isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		$result = Bot_Verifier::verify( $ip, $domains );
		if ( Bot_Verifier::FAILED === $result ) {
			return array_merge(
				$verdict,
				array(
					'class'   => Bot_Detector::BAD_BOT,
					'signals' => array( 'declared_bot', 'fake_bot' ),
				)
			);
		}
		if ( Bot_Verifier::UNKNOWN === $result ) {
			$verdict['signals'][] = 'unverified_bot';
		}
		return $verdict;
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
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Also in the admin: admin-ajax answers the storefront's own actions (should_inspect leaves the admin screens alone).
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
		if ( ( is_admin() && ! $this->is_storefront_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || defined( 'WP_CLI' ) || is_user_logged_in() ) {
			return false;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		// Left alone: REST routes outside the Store API, xmlrpc and cron, and every `wc-api` request. WooCommerce answers a
		// `wc-api` request with an empty 200 before any page is built (WC_API::handle_api_requests), so it shows nothing
		// to take, and a payment webhook from a gateway must always get through, whether the gateway has loaded yet or not.
		$skip = $this->is_rest_callback( $base, $path ) || $this->is_wc_api( $base, $path ) || $base . '/xmlrpc.php' === $path || $base . '/wp-cron.php' === $path;
		return ! (bool) apply_filters( 'socialscore_bot_guard_skip', $skip, $path );
	}

	/**
	 * admin-ajax is the storefront's own endpoint for the WooCommerce actions (`woocommerce_get_variation` and others) and
	 * is open to visitors who are not logged in, so it is guarded like the storefront. Other admin-ajax actions are not.
	 *
	 * @return bool
	 */
	private function is_storefront_ajax() {
		if ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Only the action name is read.
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		return 0 === strpos( $action, 'woocommerce_' );
	}

	/**
	 * Whether the request is a `wc-api` one: the query name (GET or POST) or the `/wc-api/<name>/` endpoint path.
	 *
	 * @param  string $base Path of the home URL, no trailing slash.
	 * @param  string $path Path of the request.
	 * @return bool
	 */
	private function is_wc_api( $base, $path ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Only the presence of the name is read.
		return isset( $_GET['wc-api'] ) || isset( $_POST['wc-api'] ) || 0 === stripos( $path, $base . '/wc-api/' ) || strtolower( $path ) === $base . '/wc-api';
	}

	/**
	 * Whether the request is for a REST route that is not the Store API: by the REST path, or by `rest_route` (which WordPress
	 * routes to REST on any address, so such a request never renders a page).
	 *
	 * @param string $base Path of the home URL, no trailing slash.
	 * @param string $path Path of the request.
	 * @return bool
	 */
	private function is_rest_callback( $base, $path ) {
		// WordPress reads rest_route from the POST body first, then the query.
		// phpcs:disable WordPress.Security.NonceVerification
		$param = '';
		foreach ( array( isset( $_POST['rest_route'] ) ? $_POST['rest_route'] : null, isset( $_GET['rest_route'] ) ? $_GET['rest_route'] : null ) as $candidate ) {
			if ( null !== $candidate ) {
				$param = is_string( $candidate ) ? sanitize_text_field( wp_unslash( $candidate ) ) : '?';
				break;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification
		if ( '' === $param && 0 === stripos( $path, $base . '/wp-json/' ) ) {
			$param = substr( $path, strlen( $base . '/wp-json' ) );
		}
		// An empty route is no REST request; a route that cannot be read as text is ambiguous and is checked.
		return '' !== $param && '?' !== $param && ! self::is_store_route( $param );
	}

	/**
	 * Whether a REST route is the Store API, whatever its case, slashes or encoding (WordPress routes case-insensitively).
	 *
	 * @param  string $route Route.
	 * @return bool
	 */
	public static function is_store_route( $route ) {
		$route = '/' . trim( (string) preg_replace( '#/+#', '/', strtolower( rawurldecode( (string) $route ) ) ), '/' );
		return '/wc/store' === $route || 0 === strpos( $route, '/wc/store/' );
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
	 * The response was changed for a bot: a page cache or a CDN must not keep it and show it to a person. Called by every
	 * place that changes what is shown, and before the 403.
	 *
	 * @return void
	 */
	private static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The constant page-cache plugins read.
			define( 'DONOTCACHEPAGE', true );
		}
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		if ( ! headers_sent() ) {
			header( 'Cache-Control: private, no-store', true );
		}
	}

	/**
	 * Ends the request with 403.
	 *
	 * @return void
	 */
	private function block() {
		self::no_cache();
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
		self::no_cache();
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
		if ( '' !== (string) $price ) {
			self::no_cache();
		}
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
		self::no_cache();
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
	 * The price a number in the page (the Pixel's events, the add-to-cart button attribute) shows: the substitute for a bad
	 * bot in substitute mode, the real one for everyone else.
	 *
	 * @param  float|int|string $price      Real price.
	 * @param  int|string       $product_id Product id.
	 * @return float
	 */
	public function shown_price( $price, $product_id ) {
		if ( Bot_Detector::BAD_BOT === self::current()['class'] && $this->should_inspect() && $this->acts() && self::MODE_SUBSTITUTE === $this->mode() ) {
			self::no_cache();
			return self::substitute_price( $price, $product_id );
		}
		return (float) $price;
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
		self::no_cache();
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
		// The decision is the route WordPress dispatched, not the address the visitor typed.
		if ( ! self::is_store_route( (string) $request->get_route() ) || ! $this->should_inspect() || ! $this->acts() ) {
			return $response;
		}
		$data = $response->get_data();
		self::no_cache();
		if ( is_array( $data ) || is_object( $data ) ) {
			$route      = '/' . trim( (string) preg_replace( '#/+#', '/', strtolower( (string) $request->get_route() ) ), '/' );
			$substitute = self::MODE_SUBSTITUTE === $this->mode() && 0 === strpos( $route, '/wc/store/v1/products' );
			$response->set_data( $this->scrub( $data, $substitute ) );
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
	private function scrub( $data, $substitute, $product_id = null ) {
		$is_object = is_object( $data );
		$node      = $is_object ? get_object_vars( $data ) : $data;
		if ( array_key_exists( 'low_stock_remaining', $node ) ) {
			$node['low_stock_remaining'] = null;
		}
		if ( isset( $node['id'] ) && is_scalar( $node['id'] ) && ( isset( $node['prices'] ) ) ) {
			$product_id = $node['id'];
		}
		if ( $substitute && isset( $node['prices'] ) && ( is_array( $node['prices'] ) || is_object( $node['prices'] ) ) && null !== $product_id ) {
			$node['prices'] = $this->substitute_prices( $node['prices'], $product_id );
		}
		foreach ( $node as $key => $value ) {
			if ( 'prices' !== $key && ( is_array( $value ) || is_object( $value ) ) ) {
				$node[ $key ] = $this->scrub( $value, $substitute, $product_id );
			}
		}
		return $is_object ? (object) $node : $node;
	}

	/**
	 * The `prices` of a Store API product (an object in the real answer, an array in some): price, regular and sale price,
	 * and the minimum and maximum of a price range, all integers in the smallest currency unit as text.
	 *
	 * @param  array|object $prices     Prices.
	 * @param  int|string   $product_id Product id.
	 * @return array|object
	 */
	private function substitute_prices( $prices, $product_id ) {
		$is_object = is_object( $prices );
		$p         = $is_object ? get_object_vars( $prices ) : $prices;
		foreach ( array( 'price', 'regular_price', 'sale_price' ) as $key ) {
			if ( isset( $p[ $key ] ) && ctype_digit( (string) $p[ $key ] ) ) {
				$p[ $key ] = (string) (int) round( self::substitute_price( (int) $p[ $key ], $product_id ) );
			}
		}
		if ( isset( $p['price_range'] ) && ( is_array( $p['price_range'] ) || is_object( $p['price_range'] ) ) ) {
			$range_is_object = is_object( $p['price_range'] );
			$range           = $range_is_object ? get_object_vars( $p['price_range'] ) : $p['price_range'];
			foreach ( array( 'min_amount', 'max_amount' ) as $key ) {
				if ( isset( $range[ $key ] ) && ctype_digit( (string) $range[ $key ] ) ) {
					$range[ $key ] = (string) (int) round( self::substitute_price( (int) $range[ $key ], $product_id ) );
				}
			}
			$p['price_range'] = $range_is_object ? (object) $range : $range;
		}
		return $is_object ? (object) $p : $p;
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
		$seed    = abs( crc32( (string) $product_id . '|' . gmdate( 'Y-m-d' ) . '|' . wp_salt() ) );
		$step    = $seed % self::FACTOR_SPREAD;
		$percent = self::FACTOR_BASE + $step;
		$percent = $percent >= 100 ? $percent + 1 : $percent; // Skip 100.
		$factor  = $percent / 100;
		return round( $price * $factor, 2 );
	}
}
