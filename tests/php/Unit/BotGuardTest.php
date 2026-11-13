<?php
namespace SocialScore\Tests\Unit;

use Brain\Monkey\Functions;
use SocialScore\Bot_Guard;
use SocialScore\Bot_Stats;
use SocialScore\Not_Human;
use SocialScore\Pixel;
use SocialScore\Tests\TestCase;

class BotGuardTest extends TestCase {

	const SITE = 'ps_abcdefghijklmnop';

	/** @var array Orders wc_get_order knows. */
	private $orders = array();
	/** @var array */
	private $server_before = array();
	/** @var int Times nocache_headers was called. */
	private $nocache = 0;
	/** @var string[] Hooks WooCommerce has (woocommerce_api_<name>). */
	private $hooked = array();
	/** @var array Statuses of the blocked requests. */
	private $blocked = array();

	protected function setUp(): void {
		parent::setUp();
		$this->server_before = $_SERVER;
		Bot_Guard::reset();
		$this->blocked = array();
		$this->hooked  = array();
		$this->nocache = 0;
		$this->orders  = array();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'has_action' )->alias( fn( $tag ) => in_array( $tag, $this->hooked, true ) );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'nocache_headers' )->alias( function () {
			++$this->nocache;
		} );
		Functions\when( 'wc_get_order' )->alias( fn( $id ) => $this->orders[ (int) $id ] ?? false );
		Functions\when( 'wc_price' )->alias( fn( $p ) => '<span class="amount">' . number_format( (float) $p, 2, '.', '' ) . '</span>' );
		Functions\when( 'wp_die' )->alias(
			function ( $message, $title = '', $args = array() ) {
				$this->blocked[] = $args['response'] ?? 0;
				throw new \RuntimeException( 'wp_die' );
			}
		);
		$this->connect_store();
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_before;
		Bot_Guard::reset();
		parent::tearDown();
	}

	private function request( $ua, $uri = '/product/shirt/', array $more = array() ) {
		$_SERVER['HTTP_USER_AGENT'] = $ua;
		$_SERVER['REQUEST_URI']     = $uri;
		unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'], $_SERVER['HTTP_SEC_FETCH_MODE'], $_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_SIGNATURE_AGENT'], $_SERVER['HTTP_ACCEPT'] );
		foreach ( $more as $k => $v ) {
			$_SERVER[ $k ] = $v;
		}
		Bot_Guard::reset();
	}

	private function server_says( $mode, $enabled = true ) {
		$this->http_responder = fn() => self::response( 200, array( 'enabled' => $enabled, 'site_id' => self::SITE, 'snippet_url' => 'x', 'bot_mode' => $mode ) );
		unset( $this->transients[ Pixel::TRANSIENT . md5( '42' ) ] );
	}

	private function inspect() {
		try {
			( new Bot_Guard() )->inspect();
		} catch ( \RuntimeException $e ) {
			return 'blocked';
		}
		return 'served';
	}

	private function product( $in_stock = true, $price = '20.00', $id = 7 ) {
		return new class( $in_stock, $price, $id ) {
			public $in, $price, $id;
			public function __construct( $in, $price, $id ) {
				$this->in    = $in;
				$this->price = $price;
				$this->id    = $id;
			}
			public function is_in_stock() {
				return $this->in;
			}
			public function get_price() {
				return $this->price;
			}
			public function get_id() {
				return $this->id;
			}
		};
	}

	// Block ---------------------------------------------------------------------------------------------------------

	public function test_block_mode_stops_a_bad_bot_with_403() {
		$this->server_says( 'block' );
		$this->request( 'python-requests/2.31.0' );
		$this->assertSame( 'blocked', $this->inspect() );
		$this->assertSame( array( 403 ), $this->blocked );
	}

	public function test_flag_mode_and_substitute_mode_serve_the_request() {
		foreach ( array( 'flag', 'substitute' ) as $mode ) {
			$this->server_says( $mode );
			$this->request( 'curl/8.4.0' );
			$this->assertSame( 'served', $this->inspect(), $mode );
		}
		$this->assertSame( array(), $this->blocked );
	}

	public function test_the_mode_is_flag_when_the_server_does_not_say() {
		$this->http_responder = fn() => self::response( 200, array( 'enabled' => true, 'site_id' => self::SITE, 'snippet_url' => 'x' ) );
		$this->request( 'curl/8.4.0' );
		$this->assertSame( 'served', $this->inspect() );
		$this->assertSame( 'flag', Pixel::state()['bot_mode'] );
	}

	public function test_a_mode_the_server_invents_is_flag() {
		$this->server_says( 'ban-hammer' );
		$this->assertSame( 'flag', Pixel::state()['bot_mode'] );
	}

	public function test_a_declared_good_bot_and_an_ai_agent_are_never_blocked_or_changed() {
		$this->server_says( 'block' );
		foreach ( array( 'Mozilla/5.0 (compatible; Googlebot/2.1)', 'Mozilla/5.0 (compatible; GPTBot/1.2)', 'Mozilla/5.0 (compatible; ChatGPT-User/1.0)' ) as $ua ) {
			$this->request( $ua );
			$this->assertSame( 'served', $this->inspect(), $ua );
		}
		$this->assertSame( array(), $this->blocked );
	}

	public function test_a_person_is_not_asked_about_and_costs_no_request_to_socialscore() {
		$this->server_says( 'block' );
		$this->request( 'Mozilla/5.0 (Windows NT 10.0) Chrome/126.0', '/', array( 'HTTP_ACCEPT' => 'text/html', 'HTTP_ACCEPT_LANGUAGE' => 'en' ) );
		$this->assertSame( 'served', $this->inspect() );
		$this->assertSame( array(), $this->http, 'no lookup for a visitor who is not a bot' );
		$this->assertArrayNotHasKey( Bot_Stats::OPTION, $this->options, 'nothing is written for a visitor who is not a bot' );
	}

	public function test_nothing_is_blocked_when_the_pixel_is_off_or_the_store_is_not_connected() {
		$this->server_says( 'block', false );
		$this->request( 'curl/8.4.0' );
		$this->assertSame( 'served', $this->inspect() );

		$this->server_says( 'block' );
		unset( $this->options['socialscore_connection'] );
		$this->request( 'curl/8.4.0' );
		$this->assertSame( 'served', $this->inspect() );
	}

	public function test_a_logged_in_visitor_the_admin_cron_and_the_command_line_are_left_alone() {
		$this->server_says( 'block' );
		$this->request( 'curl/8.4.0' );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$this->assertSame( 'served', $this->inspect() );

		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$this->assertSame( 'served', $this->inspect() );
	}

	public function test_callbacks_of_integrations_are_never_blocked() {
		$this->server_says( 'block' );
		foreach ( array( '/wp-json/stripe/v1/webhook', '/wp-json/socialscore/v1/proxy', '/xmlrpc.php', '/wp-cron.php', '/?wc-api=wc_gateway_paypal' ) as $uri ) {
			$this->request( 'curl/8.4.0', $uri );
			if ( false !== strpos( $uri, 'wc-api' ) ) {
				$_GET['wc-api'] = 'wc_gateway_paypal';
				$this->hooked   = array( 'woocommerce_api_wc_gateway_paypal' );
			}
			$this->assertSame( 'served', $this->inspect(), $uri );
			unset( $_GET['wc-api'] );
		}
		// The Store API is the shop's own front door: a bot is stopped there.
		$this->request( 'curl/8.4.0', '/wp-json/wc/store/v1/cart/add-item' );
		$this->assertSame( 'blocked', $this->inspect() );
	}

	public function test_a_request_to_wc_api_is_never_blocked_in_any_form_even_before_its_gateway_has_loaded() {
		$this->server_says( 'block' );
		foreach ( array( 'Java/11.0.2', 'Go-http-client/1.1', 'curl/8.4.0' ) as $ua ) {
			// Query form, hook not there yet (gateways load after init priority 1).
			$this->request( $ua, '/?wc-api=wc_gateway_stripe' );
			$_GET['wc-api'] = 'wc_gateway_stripe';
			$this->assertSame( 'served', $this->inspect(), $ua . ' query' );
			unset( $_GET['wc-api'] );
			// The endpoint path form of WooCommerce.
			$this->request( $ua, '/wc-api/WC_Gateway_Stripe/' );
			$this->assertSame( 'served', $this->inspect(), $ua . ' path' );
		}
		$this->assertSame( array(), $this->blocked );
	}

	public function test_what_woocommerce_does_not_treat_as_wc_api_is_a_page_request_and_is_blocked() {
		$this->server_says( 'block' );
		// Empty name, a list, and a name in the POST body: WooCommerce serves the normal page for all of them.
		$this->request( 'curl/8.4.0', '/product/x/?wc-api=' );
		$_GET['wc-api'] = '';
		$this->assertSame( 'blocked', $this->inspect(), 'empty name' );
		$_GET['wc-api'] = array( 'x' );
		$this->assertSame( 'blocked', $this->inspect(), 'a list' );
		$_GET['wc-api'] = '!!!'; // Nothing left after sanitising.
		$this->assertSame( 'blocked', $this->inspect(), 'a name with nothing left' );
		unset( $_GET['wc-api'] );
		$this->request( 'curl/8.4.0', '/product/x/' );
		$_POST['wc-api'] = 'x';
		$this->assertSame( 'blocked', $this->inspect(), 'POST body' );
		unset( $_POST['wc-api'] );
		// And a real name, in either form, passes (before any gateway has loaded).
		foreach ( array( 'Go-http-client/1.1', 'Java/11.0.2' ) as $ua ) {
			$this->request( $ua, '/?wc-api=probe_gw' );
			$_GET['wc-api'] = 'probe_gw';
			$this->assertSame( 'served', $this->inspect(), $ua );
			unset( $_GET['wc-api'] );
			$this->request( $ua, '/wc-api/probe_gw/' );
			$this->assertSame( 'served', $this->inspect(), $ua . ' path' );
		}
	}

	public function test_the_dispatched_store_route_decides_not_the_address_or_the_body() {
		$this->server_says( 'block' );
		$bot = function ( $path ) {
			$this->request( 'python-requests/2.31.0', $path );
		};
		// POST /wp-json/wp/v2/types with rest_route=/wc/store/... in the body: the address says "not the Store API",
		// the dispatched route says it is.
		foreach ( array( '/wc/store/v1/products/24', '/WC/STORE/v1/cart', '//wc/store//v1/cart/add-item', '/Wc/Store/V1/Cart/Add-Item/' ) as $route ) {
			$bot( '/wp-json/wp/v2/types' );
			$out = ( new Bot_Guard() )->guard_rest( null, null, $this->store_route( $route ) );
			$this->assertInstanceOf( \WP_Error::class, $out, $route );
			$this->assertSame( 403, $out->get_error_data()['status'] ?? null, $route );
		}
		// Not a Store API route, a person, another mode, or a result already decided: untouched.
		$bot( '/wp-json/wp/v2/types' );
		$this->assertNull( ( new Bot_Guard() )->guard_rest( null, null, $this->store_route( '/wp/v2/types' ) ) );
		$this->assertSame( 'already', ( new Bot_Guard() )->guard_rest( 'already', null, $this->store_route( '/wc/store/v1/cart' ) ) );
		$this->server_says( 'substitute' );
		$bot( '/wp-json/wp/v2/types' );
		$this->assertNull( ( new Bot_Guard() )->guard_rest( null, null, $this->store_route( '/wc/store/v1/cart' ) ) );
		$this->server_says( 'block' );
		$this->request( 'Mozilla/5.0 Chrome/126.0', '/wp-json/wp/v2/types', array( 'HTTP_ACCEPT' => 'text/html', 'HTTP_ACCEPT_LANGUAGE' => 'en' ) );
		$this->assertNull( ( new Bot_Guard() )->guard_rest( null, null, $this->store_route( '/wc/store/v1/cart' ) ) );
	}

	public function test_the_rest_route_is_judged_case_insensitively_and_from_the_post_body_first() {
		$this->server_says( 'block' );
		foreach ( array( '/WC/STORE/v1/products', '//wc//store/v1/products', '/Wc/Store' ) as $route ) {
			$this->request( 'curl/8.4.0', '/?rest_route=' . $route );
			$_GET['rest_route'] = $route;
			$this->assertSame( 'blocked', $this->inspect(), $route );
			unset( $_GET['rest_route'] );
		}
		// WordPress reads the POST value first: a harmless GET route cannot hide a Store API one in the body.
		$this->request( 'curl/8.4.0', '/?rest_route=/foo' );
		$_GET['rest_route']  = '/foo';
		$_POST['rest_route'] = '/wc/store/v1/products';
		$this->assertSame( 'blocked', $this->inspect() );
		// And the other way round: the POST value wins, so a Store API GET route does not decide.
		$_GET['rest_route']  = '/wc/store/v1/products';
		$_POST['rest_route'] = '/stripe/v1/webhook';
		$this->assertSame( 'served', $this->inspect() );
		unset( $_GET['rest_route'], $_POST['rest_route'] );
		// A route that is not text is ambiguous and is checked, not skipped.
		$_GET['rest_route'] = array( '/foo' );
		$this->assertSame( 'blocked', $this->inspect() );
		unset( $_GET['rest_route'] );
		// An upper-case REST prefix with the Store API is still the Store API.
		$this->request( 'curl/8.4.0', '/WP-JSON/WC/STORE/v1/products' );
		$this->assertSame( 'blocked', $this->inspect() );
		$this->request( 'curl/8.4.0', '/WP-JSON/stripe/v1/webhook' );
		$this->assertSame( 'served', $this->inspect() );
	}

	public function test_admin_ajax_storefront_actions_are_guarded_other_admin_screens_are_not() {
		$this->server_says( 'block' );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		$this->request( 'python-requests/2.31.0', '/wp-admin/admin-ajax.php' );
		$_REQUEST['action'] = 'woocommerce_get_variation';
		$this->assertSame( 'blocked', $this->inspect() );
		$_REQUEST['action'] = 'some_plugin_nopriv_action';
		$this->assertSame( 'served', $this->inspect() );
		unset( $_REQUEST['action'] );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		$this->request( 'python-requests/2.31.0', '/wp-admin/index.php' );
		$this->assertSame( 'served', $this->inspect() );
	}

	public function test_a_variation_answer_of_admin_ajax_gets_the_substitute_price_for_a_bot() {
		$this->server_says( 'substitute' );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		$_REQUEST['action'] = 'woocommerce_get_variation';
		$this->request( 'curl/8.4.0', '/wp-admin/admin-ajax.php' );
		$data = ( new Bot_Guard() )->variation( array( 'display_price' => 20, 'max_qty' => 5, 'availability_html' => '5 in stock' ), null, $this->product( true, '20.00', 7 ) );
		unset( $_REQUEST['action'] );
		$this->assertSame( Bot_Guard::substitute_price( 20, 7 ), $data['display_price'] );
		$this->assertSame( '', $data['max_qty'] );
	}

	public function test_a_made_up_marker_on_a_page_is_a_wc_api_request_and_woocommerce_ends_it_empty() {
		// WC_API::handle_api_requests answers any wc-api request with an empty 200 before a page is built, so there is
		// nothing to substitute; the guard leaves it alone.
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0', '/product/shirt/?wc-api=x' );
		$_GET['wc-api'] = 'x';
		$html = ( new Bot_Guard() )->price_html( '<span>20.00</span>', $this->product() );
		unset( $_GET['wc-api'] );
		$this->assertSame( '<span>20.00</span>', $html );
	}

	public function test_a_response_changed_for_a_bot_must_not_be_cached() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		( new Bot_Guard() )->price_html( 'x', $this->product() );
		$this->assertGreaterThanOrEqual( 1, $this->nocache );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	public function test_the_403_of_block_mode_is_not_cacheable() {
		$this->server_says( 'block' );
		$this->request( 'curl/8.4.0' );
		$this->assertSame( 'blocked', $this->inspect() );
		$this->assertSame( 1, $this->nocache );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}

	public function test_a_person_response_is_not_marked_uncacheable() {
		$this->server_says( 'substitute' );
		$this->request( 'Mozilla/5.0 Chrome/126.0', '/', array( 'HTTP_ACCEPT' => 'text/html', 'HTTP_ACCEPT_LANGUAGE' => 'en' ) );
		( new Bot_Guard() )->price_html( 'x', $this->product() );
		( new Bot_Guard() )->availability_text( 'x', $this->product() );
		$this->assertSame( 0, $this->nocache );
	}

	public function test_a_filter_can_exempt_a_request() {
		$this->server_says( 'block' );
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value ) => 'socialscore_bot_guard_skip' === $tag ? true : $value );
		$this->request( 'curl/8.4.0' );
		$this->assertSame( 'served', $this->inspect() );
	}

	public function test_the_hooks_are_registered_also_in_the_admin_because_admin_ajax_answers_the_storefront() {
		Functions\when( 'is_admin' )->justReturn( true );
		\Brain\Monkey\Actions\expectAdded( 'init' )->once();
		\Brain\Monkey\Filters\expectAdded( 'woocommerce_available_variation' )->once();
		( new Bot_Guard() )->register();
		$this->addToAssertionCount( 1 );
	}

	// Counting ------------------------------------------------------------------------------------------------------

	public function test_requests_of_bots_are_counted_per_day_and_class_without_an_address_or_agent() {
		$this->server_says( 'flag' );
		$this->request( 'curl/8.4.0', '/', array( 'REMOTE_ADDR' => '203.0.113.9' ) );
		$this->inspect();
		$this->inspect();
		$this->request( 'Mozilla/5.0 (compatible; Googlebot/2.1)' );
		$this->inspect();
		$this->assertSame(
			array( 'bad_bot' => 2, 'ai_agent' => 0, 'info_bot' => 1 ),
			Bot_Stats::totals( 30 )
		);
		$stored = json_encode( $this->options[ Bot_Stats::OPTION ] );
		$this->assertStringNotContainsString( '203.0.113.9', $stored );
		$this->assertStringNotContainsString( 'curl', $stored );
		$this->assertSame( 'no', $this->autoload[ Bot_Stats::OPTION ], 'the counts are not autoloaded' );
	}

	public function test_old_days_are_dropped() {
		$this->options[ Bot_Stats::OPTION ] = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$this->options[ Bot_Stats::OPTION ][ gmdate( 'Y-m-d', time() - $i * 86400 ) ] = array( 'bad_bot' => 1 );
		}
		Bot_Stats::record( 'bad_bot' );
		$this->assertCount( Bot_Stats::KEEP_DAYS, $this->options[ Bot_Stats::OPTION ] );
		$this->assertSame( 8, Bot_Stats::totals( 7 )['bad_bot'], 'today twice and the six days before it' );
	}

	// What a bad bot sees -------------------------------------------------------------------------------------------

	public function test_in_every_mode_a_bad_bot_sees_only_whether_an_item_is_in_stock() {
		foreach ( array( 'flag', 'substitute' ) as $mode ) {
			$this->server_says( $mode );
			$this->request( 'curl/8.4.0' );
			$guard = new Bot_Guard();
			$this->assertSame( 'In stock', $guard->availability_text( '5 in stock', $this->product( true ) ), $mode );
			$this->assertSame( 'Out of stock', $guard->availability_text( 'Out of stock', $this->product( false ) ), $mode );
		}
	}

	public function test_a_person_sees_the_shops_own_stock_text_and_prices() {
		$this->server_says( 'substitute' );
		$this->request( 'Mozilla/5.0 Chrome/126.0', '/', array( 'HTTP_ACCEPT' => 'text/html', 'HTTP_ACCEPT_LANGUAGE' => 'en' ) );
		$guard = new Bot_Guard();
		$this->assertSame( '5 in stock', $guard->availability_text( '5 in stock', $this->product() ) );
		$this->assertSame( '<span>20.00</span>', $guard->price_html( '<span>20.00</span>', $this->product() ) );
		$this->assertSame( array( 'max_qty' => 3 ), $guard->variation( array( 'max_qty' => 3 ), null, $this->product() ) );
	}

	public function test_flag_mode_shows_a_bad_bot_the_normal_price() {
		$this->server_says( 'flag' );
		$this->request( 'curl/8.4.0' );
		$guard = new Bot_Guard();
		$this->assertSame( '<span>20.00</span>', $guard->price_html( '<span>20.00</span>', $this->product() ) );
		$data = $guard->variation( array( 'max_qty' => 3, 'display_price' => 20, 'availability_html' => '<p>3 left</p>' ), null, $this->product() );
		$this->assertSame( 20, $data['display_price'] );
		$this->assertSame( '', $data['max_qty'] );
		$this->assertStringNotContainsString( '3 left', $data['availability_html'] );
	}

	public function test_substitute_mode_shows_a_bad_bot_a_different_stable_price() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$guard = new Bot_Guard();
		$html  = $guard->price_html( '<span>20.00</span>', $this->product( true, '20.00', 7 ) );
		$this->assertStringContainsString( 'amount', $html );
		$this->assertSame( $html, $guard->price_html( 'x', $this->product( true, '20.00', 7 ) ), 'the same for a product all day' );
		$price = Bot_Guard::substitute_price( 20, 7 );
		$this->assertGreaterThanOrEqual( 17.0, $price );
		$this->assertLessThanOrEqual( 23.0, $price );
		$factors = array();
		for ( $id = 1; $id <= 40; $id++ ) {
			$factors[ (string) Bot_Guard::substitute_price( 100, $id ) ] = true;
		}
		$this->assertGreaterThan( 5, count( $factors ), 'products get different factors' );
		$data = $guard->variation( array( 'display_price' => 20, 'display_regular_price' => 25, 'price_html' => 'x' ), null, $this->product( true, '20.00', 7 ) );
		$this->assertSame( Bot_Guard::substitute_price( 20, 7 ), $data['display_price'] );
		$this->assertSame( Bot_Guard::substitute_price( 25, 7 ), $data['display_regular_price'] );
	}

	public function test_substitute_mode_also_changes_the_price_in_the_structured_data() {
		$markup = array( 'price' => '20.00', 'priceSpecification' => array( 'price' => '20.00', 'priceCurrency' => 'EUR' ), 'availability' => 'https://schema.org/InStock' );

		$this->server_says( 'flag' );
		$this->request( 'curl/8.4.0' );
		$this->assertSame( $markup, ( new Bot_Guard() )->structured_offer( $markup, $this->product() ), 'flag mode leaves it' );

		$this->server_says( 'substitute' );
		Bot_Guard::reset();
		$out = ( new Bot_Guard() )->structured_offer( $markup, $this->product( true, '20.00', 7 ) );
		$this->assertSame( (string) Bot_Guard::substitute_price( 20, 7 ), $out['price'] );
		$this->assertSame( $out['price'], $out['priceSpecification']['price'] );
		$this->assertSame( 'EUR', $out['priceSpecification']['priceCurrency'] );

		// WooCommerce's own shape: a list of specifications.
		$listed = array( 'price' => '20.00', 'priceSpecification' => array( array( '@type' => 'UnitPriceSpecification', 'price' => '20.00', 'priceCurrency' => 'USD' ) ) );
		$out    = ( new Bot_Guard() )->structured_offer( $listed, $this->product( true, '20.00', 7 ) );
		$this->assertSame( (string) Bot_Guard::substitute_price( 20, 7 ), $out['priceSpecification'][0]['price'] );
		$this->assertSame( 'UnitPriceSpecification', $out['priceSpecification'][0]['@type'] );

		$this->request( 'Mozilla/5.0 Chrome/126.0', '/', array( 'HTTP_ACCEPT' => 'text/html', 'HTTP_ACCEPT_LANGUAGE' => 'en' ) );
		$this->assertSame( $markup, ( new Bot_Guard() )->structured_offer( $markup, $this->product() ), 'a person gets the real one' );
		$this->assertSame( $listed, ( new Bot_Guard() )->structured_offer( $listed, $this->product() ) );
	}

	public function test_a_price_of_zero_stays_and_the_substitute_never_touches_the_stored_product() {
		$this->assertSame( 0.0, Bot_Guard::substitute_price( 0, 7 ) );
		$this->assertSame( 0.0, Bot_Guard::substitute_price( 'free', 7 ) );
		$product = $this->product( true, '20.00', 7 );
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		( new Bot_Guard() )->price_html( 'x', $product );
		$this->assertSame( '20.00', $product->get_price(), 'only the text is replaced; the cart reads the real price' );
	}

	public function test_the_store_api_answer_loses_the_remaining_stock_and_in_substitute_mode_its_prices() {
		$request = new \WP_REST_Request();
		$route   = new class() extends \WP_REST_Request {
			public function get_route() {
				return '/wc/store/v1/products';
			}
		};
		unset( $request );
		$products = array(
			array( 'id' => 7, 'low_stock_remaining' => 2, 'prices' => array( 'price' => '2000', 'regular_price' => '2500', 'sale_price' => '2000', 'currency_code' => 'EUR' ) ),
		);
		$response = new class( $products ) {
			public $data;
			public function __construct( $data ) {
				$this->data = $data;
			}
			public function get_data() {
				return $this->data;
			}
			public function set_data( $data ) {
				$this->data = $data;
			}
		};

		$this->server_says( 'flag' );
		$this->request( 'curl/8.4.0' );
		$out = ( new Bot_Guard() )->store_api( $response, null, $route );
		$this->assertNull( $out->data[0]['low_stock_remaining'] );
		$this->assertSame( '2000', $out->data[0]['prices']['price'] );

		$this->server_says( 'substitute' );
		Bot_Guard::reset();
		$response->data = $products;
		$out            = ( new Bot_Guard() )->store_api( $response, null, $route );
		$this->assertSame( (string) (int) round( Bot_Guard::substitute_price( 2000, 7 ) ), $out->data[0]['prices']['price'] );
		$this->assertSame( 'EUR', $out->data[0]['prices']['currency_code'] );
	}

	public function test_a_store_api_answer_for_a_person_or_another_route_is_untouched() {
		$route = new class() extends \WP_REST_Request {
			public function get_route() {
				return '/wc/v3/products';
			}
		};
		$response = new class() {
			public $data = array( array( 'low_stock_remaining' => 2 ) );
			public function get_data() {
				return $this->data;
			}
			public function set_data( $data ) {
				$this->data = $data;
			}
		};
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		( new Bot_Guard() )->store_api( $response, null, $route );
		$this->assertSame( 2, $response->data[0]['low_stock_remaining'] );
	}

	// The done criterion ---------------------------------------------------------------------------------------------

	public function test_a_bot_without_javascript_is_caught_its_order_is_flagged_and_block_mode_stops_the_request() {
		// 1. A bot that never runs a script places an order in the default mode: nothing is stopped, the order is flagged.
		$this->server_says( 'flag' );
		$this->request( 'python-requests/2.31.0', '/checkout/', array( 'REQUEST_METHOD' => 'POST' ) );
		$this->assertSame( 'served', $this->inspect() );

		$order = new \WC_Order( array( 'id' => 77 ) );
		$this->orders[77] = $order;
		$flag = new Not_Human();
		$flag->flag_classic( $order, array() );
		$flag->note_new_order( 77 );

		$flagged = Not_Human::for_order( $order );
		$this->assertSame( 'bad_bot', $flagged['class'] );
		$this->assertContains( 'http_client_ua', $flagged['signals'] );
		$this->assertSame( 'server', $flagged['source'] );
		$this->assertSame( array( 'SocialScore: not from a human (Bad bot). Check this order.' ), $order->notes );
		$this->assertStringContainsString( 'Bad bot', \SocialScore\Check_Panels::column_text( $order ) );

		// 2. The merchant chose "block" at SocialScore: the same bot is stopped before it reaches the shop.
		$this->server_says( 'block' );
		$this->request( 'python-requests/2.31.0', '/checkout/', array( 'REQUEST_METHOD' => 'POST' ) );
		$this->assertSame( 'blocked', $this->inspect() );
		$this->assertSame( array( 403 ), $this->blocked );
	}

	// What WooCommerce really returns ---------------------------------------------------------------------------------

	private function store_api_response( $data ) {
		return new class( $data ) {
			public $data;
			public function __construct( $data ) {
				$this->data = $data;
			}
			public function get_data() {
				return $this->data;
			}
			public function set_data( $data ) {
				$this->data = $data;
			}
		};
	}

	private function store_route( $route ) {
		return new class( $route ) extends \WP_REST_Request {
			private $r;
			public function __construct( $r ) {
				$this->r = $r;
			}
			public function get_route() {
				return $this->r;
			}
		};
	}

	/** The Store API ProductSchema returns `prices` and `price_range` as objects, and `low_stock_remaining` as a number. */
	private function real_product() {
		return array(
			'id'                  => 24,
			'low_stock_remaining' => 3,
			'prices'              => (object) array(
				'price'         => '990',
				'regular_price' => '1290',
				'sale_price'    => '990',
				'price_range'   => (object) array( 'min_amount' => '990', 'max_amount' => '1990' ),
				'currency_code' => 'USD',
			),
			'variations'          => array( array( 'id' => 25, 'attributes' => array() ) ),
		);
	}

	public function test_the_store_api_prices_object_and_price_range_get_the_substitute_in_substitute_mode() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$out = ( new Bot_Guard() )->store_api( $this->store_api_response( $this->real_product() ), null, $this->store_route( '/wc/store/v1/products/24' ) )->data;
		$this->assertIsObject( $out['prices'], 'the answer keeps its shape' );
		$this->assertSame( (string) (int) round( Bot_Guard::substitute_price( 990, 24 ) ), $out['prices']->price );
		$this->assertSame( (string) (int) round( Bot_Guard::substitute_price( 1290, 24 ) ), $out['prices']->regular_price );
		$this->assertSame( (string) (int) round( Bot_Guard::substitute_price( 1990, 24 ) ), $out['prices']->price_range->max_amount );
		$this->assertSame( (string) (int) round( Bot_Guard::substitute_price( 990, 24 ) ), $out['prices']->price_range->min_amount );
		$this->assertSame( 'USD', $out['prices']->currency_code );
		$this->assertNull( $out['low_stock_remaining'] );
		$this->assertNotSame( '990', $out['prices']->price );
	}

	public function test_a_collection_of_products_is_scrubbed_each_with_its_own_id() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$second       = $this->real_product();
		$second['id'] = 30;
		$out          = ( new Bot_Guard() )->store_api( $this->store_api_response( array( $this->real_product(), $second ) ), null, $this->store_route( '/wc/store/v1/products' ) )->data;
		$this->assertSame( (string) (int) round( Bot_Guard::substitute_price( 990, 24 ) ), $out[0]['prices']->price );
		$this->assertSame( (string) (int) round( Bot_Guard::substitute_price( 990, 30 ) ), $out[1]['prices']->price );
	}

	public function test_the_store_api_route_in_any_case_is_still_the_store_api() {
		$this->server_says( 'flag' );
		$this->request( 'curl/8.4.0' );
		foreach ( array( '/WC/STORE/v1/products/34', '//wc/store//v1/products', '/Wc/Store/V1/Products/34/' ) as $route ) {
			$out = ( new Bot_Guard() )->store_api( $this->store_api_response( $this->real_product() ), null, $this->store_route( $route ) )->data;
			$this->assertNull( $out['low_stock_remaining'], $route );
		}
	}

	public function test_cart_routes_of_the_store_api_keep_the_real_prices_but_hide_stock() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$out = ( new Bot_Guard() )->store_api( $this->store_api_response( $this->real_product() ), null, $this->store_route( '/wc/store/v1/cart' ) )->data;
		$this->assertSame( '990', $out['prices']->price, 'what the cart charges is not changed' );
		$this->assertNull( $out['low_stock_remaining'] );
	}

	public function test_the_substitute_factor_is_never_exactly_one() {
		$same = 0;
		for ( $id = 1; $id <= 3000; $id++ ) {
			if ( 100.0 === Bot_Guard::substitute_price( 100, $id ) ) {
				++$same;
			}
		}
		$this->assertSame( 0, $same );
		$seen = array();
		for ( $id = 1; $id <= 3000; $id++ ) {
			$seen[ (string) Bot_Guard::substitute_price( 100, $id ) ] = true;
		}
		$this->assertGreaterThanOrEqual( 28, count( $seen ), 'about thirty different factors' );
		$this->assertGreaterThanOrEqual( 85.0, min( array_keys( $seen ) ) );
		$this->assertLessThanOrEqual( 116.0, max( array_keys( $seen ) ) );
	}

	public function test_the_price_in_the_pixel_events_and_the_button_attribute_is_the_substitute_for_a_bot() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$this->assertSame( Bot_Guard::substitute_price( '9.90', 24 ), ( new Bot_Guard() )->shown_price( '9.90', 24 ) );
		$this->assertNotSame( 9.9, ( new Bot_Guard() )->shown_price( '9.90', 24 ) );
		$this->server_says( 'flag' );
		Bot_Guard::reset();
		$this->assertSame( 9.9, ( new Bot_Guard() )->shown_price( '9.90', 24 ) );
	}
}
