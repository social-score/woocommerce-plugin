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
	// What the Store API shows as the most one can add when stock is not managed.
	const NO_LIMIT = 9999;
	// How many substitutes one request remembers; past it every number is replaced and none is remembered.
	const REGISTRY_LIMIT = 20000;
	// The headers a proxy writes the client into.
	const PROXY_HEADERS = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_TRUE_CLIENT_IP' );
	// The ONE list of what carries a quantity or a price in the Store API. The API filter and the page cleaner both use it.
	const NULL_KEYS     = array( 'low_stock_remaining', 'stock_quantity', 'quantity_in_stock' );
	const LIMIT_PARENTS = array( 'add_to_cart', 'quantity_limits' );
	const LIMIT_KEY     = 'maximum';
	const PRICE_KEYS    = array( 'price', 'regular_price', 'sale_price', 'min_amount', 'max_amount', 'min_price', 'max_price' );
	const FACTOR_BASE   = 85;
	// 30 steps: 0.85 to 0.99 and 1.01 to 1.15. Never exactly 1.00, which would show the real price.
	const FACTOR_SPREAD = 30;

	/**
	 * What THIS request has put in place of a price, kept on the server only (nothing of it reaches the visitor): the numbers
	 * that came out of substitute_price, in minor units and as decimal text, each with the product it was made for (so a real price
	 * of another product that happens to equal a substitute is still replaced). A number after a price key that is in here is a
	 * substitute already and stays; every other number is replaced. So running twice changes nothing, and anything unknown
	 * (a product no code has seen, any number of variations, text from a review) is replaced rather than skipped.
	 *
	 * @var array{minor:array<string,bool>,dec:array<string,bool>} Keys are product|number.
	 */
	private static $out = array(
		'minor' => array(),
		'dec'   => array(),
	);

	/**
	 * Set when the registry grew past its limit: from then on every number is replaced and nothing is recorded.
	 *
	 * @var bool
	 */
	private static $overflow = false;

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
	 * A declared Googlebot or Bingbot is checked against the published address ranges (Bot_Verifier). One that fails is a bad bot; one that cannot be
	 * checked stays a known bot with the signal `unverified_bot`.
	 *
	 * @param  array $verdict Result of Bot_Detector::classify().
	 * @return array
	 */
	private static function verified( array $verdict ) {
		if ( Bot_Detector::INFO_BOT !== $verdict['class'] || ! Connection::is_connected() ) {
			return $verdict;
		}
		$engine = Bot_Verifier::engine_for( isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' );
		if ( '' === $engine ) {
			return $verdict;
		}
		$client = self::resolve_client();
		$result = Bot_Verifier::verify( $client['ip'], $engine );
		// A declared search engine that is turned away, or cannot be checked, from an address that has the marks of a proxy the
		// shop has not described: the merchant is told on the reports page (before the engines lose the shop).
		// Only when what the headers say is really that engine's address: a scraper cannot make the module warn the merchant by
		// writing headers.
		if ( 'cloudflare' === $client['why'] && self::header_names_the_engine( array( 'HTTP_CF_CONNECTING_IP' ), $engine ) ) {
			Bot_Settings::hint( 'cloudflare' );
		} elseif ( Bot_Verifier::FAILED === $result && self::header_names_the_engine( self::PROXY_HEADERS, $engine ) ) {
			Bot_Settings::hint( 'proxy' );
		}
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
	 * The address of the visitor, for the check of a declared Googlebot or Bingbot, or an empty string when it cannot be told.
	 * See resolve_client().
	 *
	 * @return string
	 */
	public static function client_ip() {
		return self::resolve_client()['ip'];
	}

	/**
	 * Whose address the check looks at. A header is the sender's word, so it is read only from a proxy the shop trusts:
	 *
	 * 1. The shop's own answer, the filter `socialscore_client_ip`.
	 * 2. `REMOTE_ADDR` is a trusted proxy (the shop is behind Cloudflare and says so, or the address is in its trusted proxy
	 *    list): the client is that proxy's header (`CF-Connecting-IP`; or the right-most address of the configured header that
	 *    is not a trusted proxy itself). No valid header: unknown.
	 * 3. `REMOTE_ADDR` is Cloudflare's but the shop did not say it is behind Cloudflare, or it is private or reserved: a proxy
	 *    is in front that the shop has not described. Unknown, so the check fails open.
	 * 4. Otherwise the address is public and nothing trusted stands in front of it: `REMOTE_ADDR` itself is the client, and
	 *    every forwarding header is ignored. A scraper cannot be verified by what it writes.
	 *
	 * @return array{ip:string,why:string} The address ('' when unknown) and why it is unknown: private, cloudflare, header or ''.
	 */
	private static function resolve_client() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$set    = (string) apply_filters( 'socialscore_client_ip', '' );
		if ( '' !== $set ) {
			return array(
				'ip'  => $set,
				'why' => '',
			);
		}
		if ( false === filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return array(
				'ip'  => '',
				'why' => 'private',
			);
		}
		$settings   = Bot_Settings::get();
		$cloudflare = Bot_Verifier::is_cloudflare( $remote );
		if ( $settings['behind_cloudflare'] && $cloudflare ) {
			$cf = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && is_string( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : '';
			return array(
				'ip'  => false !== filter_var( $cf, FILTER_VALIDATE_IP ) ? $cf : '',
				'why' => 'header',
			);
		}
		if ( self::in_ranges( $remote, $settings['trusted_proxies'] ) ) {
			return array(
				'ip'  => self::forwarded_client( $settings ),
				'why' => 'header',
			);
		}
		if ( $cloudflare ) {
			return array(
				'ip'  => '',
				'why' => 'cloudflare',
			);
		}
		if ( false === filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return array(
				'ip'  => '',
				'why' => 'private',
			);
		}
		return array(
			'ip'  => $remote,
			'why' => '',
		);
	}

	/**
	 * The client out of the header of a trusted proxy: the right-most address that is not a trusted proxy itself.
	 *
	 * @param  array $settings Bot_Settings::get().
	 * @return string Empty when the header is missing or holds nothing usable.
	 */
	private static function forwarded_client( array $settings ) {
		$key   = 'HTTP_' . strtoupper( str_replace( '-', '_', $settings['header'] ) );
		$value = isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
		foreach ( array_reverse( array_map( 'trim', explode( ',', $value ) ) ) as $address ) {
			if ( false === filter_var( $address, FILTER_VALIDATE_IP ) ) {
				return '';
			}
			if ( ! self::in_ranges( $address, $settings['trusted_proxies'] ) && ! ( $settings['behind_cloudflare'] && Bot_Verifier::is_cloudflare( $address ) ) ) {
				return $address;
			}
		}
		return '';
	}

	/**
	 * Whether an address is in any of the ranges.
	 *
	 * @param  string   $ip     Address.
	 * @param  string[] $ranges Ranges.
	 * @return bool
	 */
	private static function in_ranges( $ip, array $ranges ) {
		foreach ( $ranges as $cidr ) {
			if ( Bot_Verifier::in_range( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether any address in these headers is a real address of the engine the request claims to be.
	 *
	 * @param  string[] $headers Server keys.
	 * @param  string   $engine  Engine.
	 * @return bool
	 */
	private static function header_names_the_engine( array $headers, $engine ) {
		foreach ( $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) || ! is_string( $_SERVER[ $header ] ) ) {
				continue;
			}
			preg_match_all( '/[0-9a-f:.]{3,45}/i', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), $found );
			foreach ( array_slice( $found[0], 0, 10 ) as $address ) {
				if ( false !== filter_var( $address, FILTER_VALIDATE_IP ) && Bot_Verifier::VERIFIED === Bot_Verifier::verify( $address, $engine ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Forgets the classification (a new request in a long process, and the tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$current  = null;
		self::$out      = array(
			'minor' => array(),
			'dec'   => array(),
		);
		self::$overflow = false;
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
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 0 );
		add_filter( 'rest_pre_dispatch', array( $this, 'guard_rest' ), 10, 3 );
		// After the route's callbacks, inside dispatch(): the server's own answers, and also WooCommerce Blocks' hydration of
		// the page (it calls rest_do_request, which never reaches rest_post_dispatch), both pass here.
		add_filter( 'rest_request_after_callbacks', array( $this, 'store_api' ), 10, 3 );
		// And the served answer as a second line; the scrub is safe to run twice.
		add_filter( 'rest_post_dispatch', array( $this, 'store_api' ), 10, 3 );
	}

	/**
	 * Whether this request is one the guard looks at: a storefront request of a visitor who is not logged in. Cron, the
	 * command line, the REST API outside the Store API (payment and SocialScore callbacks) and `wc-api` callbacks are left
	 * alone, so an integration that uses an HTTP library is never taken for a scraper.
	 *
	 * @param  bool $dispatched_store True when WordPress has dispatched the request to a Store API route.
	 * @return bool
	 */
	private function should_inspect( $dispatched_store = false ) {
		if ( ( is_admin() && ! $this->is_storefront_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || defined( 'WP_CLI' ) || is_user_logged_in() ) {
			return false;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		// Left alone: REST routes outside the Store API, xmlrpc and cron, and every `wc-api` request. WooCommerce answers a
		// `wc-api` request with an empty 200 before any page is built (WC_API::handle_api_requests), so it shows nothing
		// to take, and a payment webhook from a gateway must always get through, whether the gateway has loaded yet or not.
		// A request WordPress has dispatched to a Store API route is never skipped on what its address looks like: the route
		// it was dispatched to decides (`rest_pre_dispatch`).
		$skip = ! $dispatched_store && ( $this->is_rest_callback( $base, $path ) || ( $this->is_wc_api( $path, $base ) && ! $this->is_ajax() ) || $base . '/xmlrpc.php' === $path || $base . '/wp-cron.php' === $path );
		return ! (bool) apply_filters( 'socialscore_bot_guard_skip', $skip, $path );
	}

	/**
	 * Whether this is an admin-ajax request: a `wc-api` marker in it does not exempt it (admin-ajax is not the API endpoint).
	 *
	 * @return bool
	 */
	private function is_ajax() {
		return function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
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
	 * Whether the request is a `wc-api` one, the way WooCommerce decides it.
	 *
	 * @param  string $path Path of the request.
	 * @param  string $base Path of the home URL, no trailing slash.
	 * @return bool
	 */
	private function is_wc_api( $path, $base ) {
		// As WooCommerce reads it (WC_API::handle_api_requests): the query name, as text, not empty after sanitising; or the
		// `/wc-api/<name>/` endpoint. An empty name, a list (`wc-api[]=x`) or a name in the POST body is a page request.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only the callback name is read.
		$name = isset( $_GET['wc-api'] ) && is_string( $_GET['wc-api'] ) ? sanitize_key( wp_unslash( $_GET['wc-api'] ) ) : '';
		if ( '' !== $name ) {
			return true;
		}
		// The endpoint form needs a name that is not empty after sanitising: `/wc-api/`, `/wc-api//` and `/wc-api/%20/` give
		// WooCommerce an empty name, and WordPress serves a normal page.
		return 1 === preg_match( '#^' . preg_quote( $base, '#' ) . '/wc-api/([^/?]+)#i', $path, $m ) && '' !== sanitize_key( rawurldecode( $m[1] ) );
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
		if ( isset( $_POST['rest_route'] ) ) {
			$param = is_string( $_POST['rest_route'] ) ? sanitize_text_field( wp_unslash( $_POST['rest_route'] ) ) : '?';
		} elseif ( isset( $_GET['rest_route'] ) ) {
			$param = is_string( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : '?';
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
	 * A block theme prints Store API product data into the page itself (WooCommerce Blocks puts it in script tags and in
	 * block attributes, built by the product schema and not through a REST request), where no REST filter reaches it. For a
	 * bad bot the page is therefore cleaned on its way out (`clean_page`).
	 *
	 * @return void
	 */
	public function start_buffer() {
		if ( Bot_Detector::BAD_BOT !== self::current()['class'] || ! $this->should_inspect() || ! $this->acts() ) {
			return;
		}
		self::no_cache();
		ob_start( array( $this, 'clean_page' ) );
	}

	/**
	 * The page of a bad bot with the product data in its JSON made safe (see Page_Cleaner): no remaining stock and no
	 * stock-sized maximum in any mode, and in substitute mode the prices replaced as they are in the API answers.
	 *
	 * @param  string $html Page.
	 * @return string
	 */
	public function clean_page( $html ) {
		return Page_Cleaner::clean( $html, self::MODE_SUBSTITUTE === $this->mode() );
	}

	/**
	 * Before WordPress runs a REST route: in block mode a bad bot gets 403 on every Store API route, decided from the route
	 * WordPress dispatched (`$request->get_route()`), whatever way the address or the POST body named it. This stops a bot
	 * that reaches the Store API through `POST /wp-json/wp/v2/types` with `rest_route=/wc/store/...` in the body, or by any
	 * encoding of the path.
	 *
	 * @param  mixed            $result  Result so far.
	 * @param  \WP_REST_Server  $server  Server.
	 * @param  \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function guard_rest( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || ! is_object( $request ) || ! self::is_store_route( (string) $request->get_route() ) ) {
			return $result;
		}
		if ( Bot_Detector::BAD_BOT !== self::current()['class'] || ! $this->should_inspect( true ) || ! $this->acts() || self::MODE_BLOCK !== $this->mode() ) {
			return $result;
		}
		self::no_cache();
		return new \WP_Error( 'socialscore_blocked', 'Access denied.', array( 'status' => 403 ) );
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
		if ( ! self::is_store_route( (string) $request->get_route() ) || ! $this->should_inspect( true ) || ! $this->acts() ) {
			return $response;
		}
		$data = $response->get_data();
		self::no_cache();
		if ( is_array( $data ) || is_object( $data ) ) {
			// Substitute on every Store API route and version that can carry a price (products, collection data, batch, any
			// later version), except what is the visitor's own cart and checkout: those show what is charged.
			$route = '/' . trim( (string) preg_replace( '#/+#', '/', strtolower( rawurldecode( (string) $request->get_route() ) ) ), '/' );
			// A batch holds the answers of its own sub-requests, each already through this filter: only its quantities are done.
			$substitute = self::MODE_SUBSTITUTE === $this->mode() && 1 !== preg_match( '#^/wc/store(/v\d+)?/(cart|checkout|order|batch)(/|$)#', $route );
			$response->set_data( $this->scrub( $data, $substitute ) );
		}
		return $response;
	}

	/**
	 * Walks a Store API answer and changes the stock and, when asked, the price fields of every product in it. Safe to run
	 * twice on the same data: a quantity that is already null or at the no-limit value stays, and a price this request already
	 * replaced (a registry on the server, never in the answer) is not replaced again.
	 *
	 * @param  array|object $data       Decoded answer.
	 * @param  bool         $substitute Whether to change prices.
	 * @param  mixed        $product_id Id of the product whose data this is.
	 * @return array|object
	 */
	private function scrub( $data, $substitute, $product_id = null ) {
		$is_object = is_object( $data );
		$node      = $is_object ? get_object_vars( $data ) : $data;
		// No quantities for a bad bot, in every mode.
		foreach ( self::NULL_KEYS as $key ) {
			if ( array_key_exists( $key, $node ) ) {
				$node[ $key ] = null;
			}
		}
		foreach ( self::LIMIT_PARENTS as $parent ) {
			if ( isset( $node[ $parent ] ) && ( is_array( $node[ $parent ] ) || is_object( $node[ $parent ] ) ) ) {
				$node[ $parent ] = $this->lift_limit( $node[ $parent ] );
			}
		}
		if ( isset( $node['id'] ) && is_scalar( $node['id'] ) && isset( $node['prices'] ) ) {
			$product_id = $node['id'];
		}
		if ( $substitute && isset( $node['prices'] ) && ( is_array( $node['prices'] ) || is_object( $node['prices'] ) ) && null !== $product_id ) {
			$node['prices'] = $this->substitute_prices( $node['prices'], $product_id );
		}
		// The collection data of the products route has a price range of its own, with no product id.
		if ( $substitute && ! isset( $node['prices'] ) && isset( $node['price_range'] ) && ( is_array( $node['price_range'] ) || is_object( $node['price_range'] ) ) ) {
			$node['price_range'] = $this->substitute_range( $node['price_range'], null === $product_id ? 'collection' : $product_id );
		}
		foreach ( $node as $key => $value ) {
			if ( 'prices' !== $key && ( is_array( $value ) || is_object( $value ) ) && ! ( 'price_range' === $key && $substitute && ! isset( $node['prices'] ) ) ) {
				$node[ $key ] = $this->scrub( $value, $substitute, $product_id );
			}
		}
		return $is_object ? (object) $node : $node;
	}

	/**
	 * The most that can be added, below the no-limit value, becomes the no-limit value.
	 *
	 * @param  array|object $limits The `add_to_cart` or `quantity_limits` data.
	 * @return array|object
	 */
	private function lift_limit( $limits ) {
		$is_object = is_object( $limits );
		$l         = $is_object ? get_object_vars( $limits ) : $limits;
		if ( isset( $l[ self::LIMIT_KEY ] ) && is_numeric( $l[ self::LIMIT_KEY ] ) && (int) $l[ self::LIMIT_KEY ] < self::NO_LIMIT ) {
			$l[ self::LIMIT_KEY ] = self::NO_LIMIT;
		}
		return $is_object ? (object) $l : $l;
	}

	/**
	 * The `prices` of a Store API product (an object in the real answer, an array in some): every price key, and the price
	 * range inside. Marked as shown, so a second pass leaves it alone.
	 *
	 * @param  array|object $prices     Prices.
	 * @param  int|string   $product_id Product id.
	 * @return array|object
	 */
	private function substitute_prices( $prices, $product_id ) {
		$is_object = is_object( $prices );
		$p         = $is_object ? get_object_vars( $prices ) : $prices;
		foreach ( self::PRICE_KEYS as $key ) {
			if ( isset( $p[ $key ] ) && ctype_digit( (string) $p[ $key ] ) ) {
				$p[ $key ] = $this->shown_minor( $p[ $key ], $product_id );
			}
		}
		if ( isset( $p['price_range'] ) && ( is_array( $p['price_range'] ) || is_object( $p['price_range'] ) ) ) {
			$p['price_range'] = $this->substitute_range( $p['price_range'], $product_id );
		}
		return $is_object ? (object) $p : $p;
	}

	/**
	 * A price in the Store API answer, as text: replaced, unless it is a number this request already produced (a second pass).
	 *
	 * @param  string     $value      Price.
	 * @param  int|string $product_id Product id of the answer.
	 * @return string
	 */
	private function shown_minor( $value, $product_id ) {
		$shown = self::shown_value( (string) $value, $product_id );
		return null === $shown ? (string) $value : $shown;
	}

	/**
	 * A price range of the Store API: `min_amount` and `max_amount` of a product, `min_price` and `max_price` of the
	 * collection data.
	 *
	 * @param  array|object $range      Range.
	 * @param  int|string   $product_id Product id, or a word for a collection.
	 * @return array|object
	 */
	private function substitute_range( $range, $product_id ) {
		$is_object = is_object( $range );
		$r         = $is_object ? get_object_vars( $range ) : $range;
		foreach ( self::PRICE_KEYS as $key ) {
			if ( isset( $r[ $key ] ) && ctype_digit( (string) $r[ $key ] ) ) {
				$r[ $key ] = $this->shown_minor( $r[ $key ], $product_id );
			}
		}
		return $is_object ? (object) $r : $r;
	}

	/**
	 * A decimal number as a comparison key (no trailing zeros).
	 *
	 * @param  float|string $value Number.
	 * @return string
	 */
	private static function dec_key( $value ) {
		return rtrim( rtrim( number_format( (float) $value, 6, '.', '' ), '0' ), '.' );
	}

	/**
	 * What to show instead of a number that stands where a price does, or null to leave it as it is. A number this request
	 * already produced is left; any other number, whoever it belongs to, is replaced with the substitute of the product named
	 * by `$hint` (an id the answer or the page names; 0 when there is none: then the value alone, and the day, decide), and the
	 * result is remembered. The id is only a way to give the same product the same factor in the API and in the page: it can
	 * never keep a real price from being replaced.
	 *
	 * @param  string          $text Number as written (digits, maybe a decimal point).
	 * @param  int|string|null $hint Product id, or null.
	 * @return string|null
	 */
	public static function shown_value( $text, $hint = null ) {
		if ( 1 !== preg_match( '/^\d+(?:\.\d+)?$/', (string) $text ) || (float) $text <= 0 ) {
			return null;
		}
		$is_dec = false !== strpos( $text, '.' );
		$id     = null === $hint || '' === $hint ? 0 : $hint;
		if ( ! self::$overflow && ( $is_dec ? isset( self::$out['dec'][ $id . '|' . self::dec_key( $text ) ] ) : isset( self::$out['minor'][ $id . '|' . (int) $text ] ) ) ) {
			return null;
		}
		if ( $is_dec ) {
			$places = strlen( substr( $text, strpos( $text, '.' ) + 1 ) );
			$shown  = number_format( self::substitute_price( (float) $text, $id ), $places, '.', '' );
			self::remember( 'dec', $id . '|' . self::dec_key( $shown ) );
			return $shown;
		}
		$shown = (string) (int) round( self::substitute_price( (int) $text, $id ) );
		self::remember( 'minor', $id . '|' . $shown );
		return $shown;
	}

	/**
	 * Remembers a number this request produced; past the limit, stops remembering and replaces everything from then on.
	 *
	 * @param  string $kind minor or dec.
	 * @param  string $key  The number.
	 * @return void
	 */
	private static function remember( $kind, $key ) {
		if ( count( self::$out['minor'] ) + count( self::$out['dec'] ) >= self::REGISTRY_LIMIT ) {
			self::$overflow = true;
			return;
		}
		self::$out[ $kind ][ $key ] = true;
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
