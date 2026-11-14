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
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wc_get_product' )->justReturn( false ); // Unknown product: the first answer is taken as the real prices.
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'has_action' )->alias( fn( $tag ) => in_array( $tag, $this->hooked, true ) );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'nocache_headers' )->alias(
			function () {
				++$this->nocache;
			}
		);
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
		$this->http_responder = fn() => self::response(
			200,
			array(
				'enabled'     => $enabled,
				'site_id'     => self::SITE,
				'snippet_url' => 'x',
				'bot_mode'    => $mode,
			)
		);
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
		$this->http_responder = fn() => self::response(
			200,
			array(
				'enabled'     => true,
				'site_id'     => self::SITE,
				'snippet_url' => 'x',
			)
		);
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
		$this->request(
			'Mozilla/5.0 (Windows NT 10.0) Chrome/126.0',
			'/',
			array(
				'HTTP_ACCEPT'          => 'text/html',
				'HTTP_ACCEPT_LANGUAGE' => 'en',
			)
		);
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
			$this->assertInstanceOf( \WP_REST_Response::class, $out, $route );
			$this->assertSame( 403, $out->get_status(), $route );
			$this->assertSame( 'socialscore_blocked', $out->get_data()['code'], $route );
		}
		// Not a Store API route, a person, another mode, or a result already decided: untouched.
		$bot( '/wp-json/wp/v2/types' );
		$this->assertNull( ( new Bot_Guard() )->guard_rest( null, null, $this->store_route( '/wp/v2/types' ) ) );
		$this->assertSame( 'already', ( new Bot_Guard() )->guard_rest( 'already', null, $this->store_route( '/wc/store/v1/cart' ) ) );
		$this->server_says( 'substitute' );
		$bot( '/wp-json/wp/v2/types' );
		$this->assertNull( ( new Bot_Guard() )->guard_rest( null, null, $this->store_route( '/wc/store/v1/products' ) ), 'substitute mode still answers the products' );
		$this->assertInstanceOf( \WP_REST_Response::class, ( new Bot_Guard() )->guard_rest( null, null, $this->store_route( '/wc/store/v1/cart/add-item' ) ), 'but the cart is closed' );
		$this->server_says( 'block' );
		$this->request(
			'Mozilla/5.0 Chrome/126.0',
			'/wp-json/wp/v2/types',
			array(
				'HTTP_ACCEPT'          => 'text/html',
				'HTTP_ACCEPT_LANGUAGE' => 'en',
			)
		);
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
		$data = ( new Bot_Guard() )->variation(
			array(
				'display_price'     => 20,
				'max_qty'           => 5,
				'availability_html' => '5 in stock',
			),
			null,
			$this->product( true, '20.00', 7 )
		);
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
		$html           = ( new Bot_Guard() )->price_html( '<span>20.00</span>', $this->product() );
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
		$this->request(
			'Mozilla/5.0 Chrome/126.0',
			'/',
			array(
				'HTTP_ACCEPT'          => 'text/html',
				'HTTP_ACCEPT_LANGUAGE' => 'en',
			)
		);
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
			array(
				'bad_bot'  => 2,
				'ai_agent' => 0,
				'info_bot' => 1,
			),
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
		$this->request(
			'Mozilla/5.0 Chrome/126.0',
			'/',
			array(
				'HTTP_ACCEPT'          => 'text/html',
				'HTTP_ACCEPT_LANGUAGE' => 'en',
			)
		);
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
		$data = $guard->variation(
			array(
				'max_qty'           => 3,
				'display_price'     => 20,
				'availability_html' => '<p>3 left</p>',
			),
			null,
			$this->product()
		);
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
		$data = $guard->variation(
			array(
				'display_price'         => 20,
				'display_regular_price' => 25,
				'price_html'            => 'x',
			),
			null,
			$this->product( true, '20.00', 7 )
		);
		$this->assertSame( Bot_Guard::substitute_price( 20, 7 ), $data['display_price'] );
		$this->assertSame( Bot_Guard::substitute_price( 25, 7 ), $data['display_regular_price'] );
	}

	public function test_substitute_mode_also_changes_the_price_in_the_structured_data() {
		$markup = array(
			'price'              => '20.00',
			'priceSpecification' => array(
				'price'         => '20.00',
				'priceCurrency' => 'EUR',
			),
			'availability'       => 'https://schema.org/InStock',
		);

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
		$listed = array(
			'price'              => '20.00',
			'priceSpecification' => array(
				array(
					'@type'         => 'UnitPriceSpecification',
					'price'         => '20.00',
					'priceCurrency' => 'USD',
				),
			),
		);
		$out    = ( new Bot_Guard() )->structured_offer( $listed, $this->product( true, '20.00', 7 ) );
		$this->assertSame( (string) Bot_Guard::substitute_price( 20, 7 ), $out['priceSpecification'][0]['price'] );
		$this->assertSame( 'UnitPriceSpecification', $out['priceSpecification'][0]['@type'] );

		$this->request(
			'Mozilla/5.0 Chrome/126.0',
			'/',
			array(
				'HTTP_ACCEPT'          => 'text/html',
				'HTTP_ACCEPT_LANGUAGE' => 'en',
			)
		);
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
			array(
				'id'                  => 7,
				'low_stock_remaining' => 2,
				'prices'              => array(
					'price'         => '2000',
					'regular_price' => '2500',
					'sale_price'    => '2000',
					'currency_code' => 'EUR',
				),
			),
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
		$route    = new class() extends \WP_REST_Request {
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

		$order            = new \WC_Order( array( 'id' => 77 ) );
		$this->orders[77] = $order;
		$flag             = new Not_Human();
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
				'price_range'   => (object) array(
					'min_amount' => '990',
					'max_amount' => '1990',
				),
				'currency_code' => 'USD',
			),
			'variations'          => array(
				array(
					'id'         => 25,
					'attributes' => array(),
				),
			),
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

	public function test_substitute_applies_to_every_store_api_version_and_route_that_can_carry_a_price() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		foreach ( array( '/wc/store/v1/products', '/wc/store/products/24', '/wc/store/v2/products/24', '/wc/store/v1/products/collection-data', '/WC/STORE/V3/PRODUCTS' ) as $route ) {
			$out = ( new Bot_Guard() )->store_api( $this->store_api_response( $this->real_product() ), null, $this->store_route( $route ) )->data;
			$this->assertNotSame( '990', $out['prices']->price, $route );
		}
	}

	public function test_no_quantity_leaves_the_store_api_in_any_mode_not_even_the_most_that_can_be_added() {
		foreach ( array( 'flag', 'substitute', 'block' ) as $mode ) {
			$this->server_says( $mode );
			$this->request( 'curl/8.4.0' );
			$product                = $this->real_product();
			$product['add_to_cart'] = (object) array(
				'text'        => 'Add to cart',
				'minimum'     => 1,
				'maximum'     => 37,
				'multiple_of' => 1,
			);
			$out                    = ( new Bot_Guard() )->store_api( $this->store_api_response( $product ), null, $this->store_route( '/wc/store/v1/products/24' ) )->data;
			$this->assertNull( $out['low_stock_remaining'], $mode );
			$this->assertSame( 9999, $out['add_to_cart']->maximum, $mode );
			$this->assertSame( 1, $out['add_to_cart']->minimum, $mode );
		}
	}

	public function test_the_hook_is_the_one_inside_dispatch_so_the_blocks_hydration_is_covered() {
		\Brain\Monkey\Filters\expectAdded( 'rest_request_after_callbacks' )->once();
		\Brain\Monkey\Filters\expectAdded( 'rest_post_dispatch' )->once(); // The second line.
		( new Bot_Guard() )->register();
		$this->addToAssertionCount( 1 );
	}

	public function test_the_wc_api_endpoint_needs_a_name_that_is_not_empty_after_sanitising() {
		$this->server_says( 'block' );
		foreach ( array( '/wc-api/', '/wc-api//', '/wc-api/%20/', '/wc-api/%21%21/', '/WC-API/' ) as $path ) {
			$this->request( 'curl/8.4.0', $path );
			$this->assertSame( 'blocked', $this->inspect(), $path );
		}
		foreach ( array( '/wc-api/probe_gw/', '/wc-api/WC_Gateway_Paypal', '/WC-API/probe_gw/' ) as $path ) {
			$this->request( 'Java/11.0.2', $path );
			$this->assertSame( 'served', $this->inspect(), $path );
		}
	}

	public function test_the_collection_data_price_range_has_no_real_price_in_substitute_mode() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$data = array(
			'price_range'      => (object) array(
				'min_price'     => '990',
				'max_price'     => '1990',
				'currency_code' => 'USD',
			),
			'attribute_counts' => array(),
		);
		$out  = ( new Bot_Guard() )->store_api( $this->store_api_response( $data ), null, $this->store_route( '/wc/store/v1/products/collection-data' ) )->data;
		$this->assertNotSame( '990', $out['price_range']->min_price );
		$this->assertNotSame( '1990', $out['price_range']->max_price );
		$this->assertSame( 'USD', $out['price_range']->currency_code );
		$this->assertLessThan( (int) $out['price_range']->max_price, (int) $out['price_range']->min_price );
	}

	public function test_only_the_visitors_own_cart_and_checkout_keep_real_prices() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		foreach ( array( '/wc/store/v1/cart', '/wc/store/v1/cart/items', '/wc/store/v1/cart/add-item', '/wc/store/cart', '/wc/store/v2/cart', '/wc/store/v1/checkout', '/wc/store/v1/checkout/24', '/wc/store/v1/order/12' ) as $route ) {
			$out = ( new Bot_Guard() )->store_api( $this->store_api_response( $this->real_product() ), null, $this->store_route( $route ) )->data;
			$this->assertSame( '990', $out['prices']->price, $route );
			$this->assertNull( $out['low_stock_remaining'], $route );
		}
	}

	public function test_a_wc_api_marker_does_not_exempt_admin_ajax() {
		$this->server_says( 'block' );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		$this->request( 'python-requests/2.31.0', '/wp-admin/admin-ajax.php?wc-api=probe_gw' );
		$_REQUEST['action'] = 'woocommerce_get_variation';
		$_GET['wc-api']     = 'probe_gw';
		$this->assertSame( 'blocked', $this->inspect() );
		unset( $_REQUEST['action'], $_GET['wc-api'] );
		// The same marker on the storefront endpoint is the gateway callback.
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		$this->request( 'python-requests/2.31.0', '/?wc-api=probe_gw' );
		$_GET['wc-api'] = 'probe_gw';
		$this->assertSame( 'served', $this->inspect() );
		unset( $_GET['wc-api'] );
	}

	public function test_a_wc_api_marker_does_not_exempt_a_store_api_route() {
		$this->server_says( 'block' );
		$this->request( 'python-requests/2.31.0', '/wp-json/wc/store/v1/cart?wc-api=probe_gw' );
		$_GET['wc-api'] = 'probe_gw';
		$out            = ( new Bot_Guard() )->guard_rest( null, null, $this->store_route( '/wc/store/v1/cart' ) );
		unset( $_GET['wc-api'] );
		$this->assertInstanceOf( \WP_REST_Response::class, $out );
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

	// The page of a block theme ---------------------------------------------------------------------------------------

	private function hydrated_page() {
		return '<html><script id="wc-settings">{"id":24,"name":"Mug","prices":{"price":"4747","regular_price":"4747","sale_price":"4747","price_range":null,"currency_code":"USD"},"is_in_stock":true,"low_stock_remaining":2,"add_to_cart":{"text":"Add","minimum":1,"maximum":2,"multiple_of":1}}</script>'
			. '<div data-wp-context="{&quot;id&quot;:30,&quot;prices&quot;:{&quot;price&quot;:&quot;1990&quot;,&quot;price_range&quot;:{&quot;min_amount&quot;:&quot;1990&quot;,&quot;max_amount&quot;:&quot;2990&quot;}},&quot;low_stock_remaining&quot;:1}"></div></html>';
	}

	public function test_the_hydrated_json_of_a_block_theme_page_loses_all_quantities_in_flag_mode() {
		$this->server_says( 'flag' );
		$this->request( 'curl/8.4.0' );
		$out = ( new Bot_Guard() )->clean_page( $this->hydrated_page() );
		$this->assertStringNotContainsString( '"low_stock_remaining":2', $out );
		$this->assertStringContainsString( '"low_stock_remaining":null', $out );
		$this->assertStringContainsString( '&quot;low_stock_remaining&quot;:null', $out, 'also inside an attribute' );
		$this->assertStringContainsString( '"maximum":9999', $out );
		$this->assertStringContainsString( '"minimum":1', $out );
		$this->assertStringContainsString( '"price":"4747"', $out, 'flag mode shows the selling price' );
	}

	public function test_the_hydrated_json_of_a_block_theme_page_loses_what_the_data_level_did_not_produce() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$out = ( new Bot_Guard() )->clean_page( $this->hydrated_page() );
		foreach ( array( '4747', '1990', '2990' ) as $real ) {
			$this->assertStringNotContainsString( $real, $out );
		}
		$this->assertStringContainsString( '"price":"0"', $out );
		$this->assertStringContainsString( '&quot;min_amount&quot;:&quot;0&quot;', $out );
		$this->assertStringContainsString( '"currency_code":"USD"', $out );
		$this->assertStringContainsString( '"low_stock_remaining":null', $out );
	}

	public function test_what_the_data_level_produced_for_the_product_is_kept_in_the_page_and_nothing_is_scaled() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$guard = new Bot_Guard();
		$api   = $guard->store_api(
			$this->store_api_response(
				array(
					'id'     => 24,
					'prices' => (object) array(
						'price'         => '4747',
						'regular_price' => '5000',
					),
				)
			),
			null,
			$this->store_route( '/wc/store/v1/products/24' )
		)->data['prices'];
		$page  = '<script>{"id":24,"prices":{"price":"' . $api->price . '","regular_price":"' . $api->regular_price . '"}}</script>';
		$this->assertSame( $page, $guard->clean_page( $page ), 'the numbers the server produced stay as they are' );
		$this->assertNotSame( '4747', $api->price );
		$other = '<script>{"id":25,"prices":{"price":"' . $api->price . '"}}</script>';
		$this->assertStringContainsString( '"price":"0"', $guard->clean_page( $other ), 'the same number for another product is not kept' );
		$real = '<script>{"id":24,"prices":{"price":"4747","regular_price":"5000"}}</script>';
		$this->assertSame( '<script>{"id":24,"prices":{"price":"0","regular_price":"0"}}</script>', $guard->clean_page( $real ), 'a real price that reaches the page is removed, not scaled' );
	}

	public function test_text_with_a_price_and_an_id_gives_a_bot_nothing_to_measure() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$guard = new Bot_Guard();
		$seen  = array();
		foreach ( array( 5, 24, 777, 123456 ) as $id ) {
			$out    = $guard->clean_page( '<p>"price":"10000","id":' . $id . '</p><p>"id":' . $id . ',"price":"10000"</p>' );
			$seen[] = str_replace( (string) $id, 'ID', $out );
			$this->assertStringNotContainsString( '10000', $out );
		}
		$this->assertCount( 1, array_unique( $seen ), 'the same for every id: the number is removed, never scaled, so there is no factor to read' );
		$this->assertSame( '<p>"price":"0","id":ID</p><p>"id":ID,"price":"0"</p>', $seen[0] );
	}

	public function test_a_promotion_equal_to_the_substitute_of_the_regular_price_never_comes_out_as_a_real_price() {
		// Pick a product whose factor today is exactly 0.85, so the substitute of 100 is 85, the same as the sale price.
		$id = null;
		for ( $i = 1; $i < 400 && null === $id; $i++ ) {
			if ( 85.0 === Bot_Guard::substitute_price( 100, $i ) ) {
				$id = $i;
			}
		}
		$this->assertNotNull( $id, 'a product with the lowest factor exists among 400' );
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$guard = new Bot_Guard();
		$api   = $guard->store_api(
			$this->store_api_response(
				array(
					'id'     => $id,
					'prices' => (object) array(
						'price'         => '8500',
						'regular_price' => '10000',
						'sale_price'    => '8500',
					),
				)
			),
			null,
			$this->store_route( '/wc/store/v1/products/' . $id )
		)->data['prices'];
		$this->assertSame( (string) (int) round( Bot_Guard::substitute_price( 8500, $id ) ), $api->sale_price, 'the real sale price is replaced' );
		$page = $guard->clean_page( '<script>{"id":' . $id . ',"prices":{"price":"8500","sale_price":"8500"}}</script>' );
		$this->assertStringNotContainsString( '8500', $page, 'the real 85 is not in the page, because 85 is also a substitute and cannot be told apart' );
	}

	/**
	 * A product stand-in: prices as the getters give them, a parent for a variation, children for a variable product.
	 */
	private function fake_product( $id, $regular, $sale, $parent = 0, array $children = array(), $sold_individually = false ) {
		return new class( $id, $regular, $sale, $parent, $children, $sold_individually ) {
			public function __construct( private $id, private $regular, private $sale, private $parent, private array $children, private bool $single ) {}
			public function get_id() {
				return $this->id;
			}
			public function get_parent_id() {
				return $this->parent;
			}
			public function get_regular_price() {
				return $this->regular;
			}
			public function get_sale_price() {
				return $this->sale;
			}
			public function get_price() {
				return '' !== (string) $this->sale ? $this->sale : $this->regular;
			}
			public function is_type( $type ) {
				return 'variable' === $type && array() !== $this->children;
			}
			public function get_children() {
				return $this->children;
			}
			public function is_in_stock() {
				return true;
			}
			public function is_sold_individually() {
				return $this->single;
			}
		};
	}

	public function test_a_substitute_is_never_equal_to_another_real_price_of_the_same_product() {
		$products = array();
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) use ( &$products ) {
				return $products[ (int) $id ] ?? false;
			}
		);
		$id = null;
		for ( $i = 1; $i < 400 && null === $id; $i++ ) {
			if ( 85.0 === Bot_Guard::substitute_price( 100, $i ) ) {
				$id = $i; // Without the rule, the regular price 100 would be shown as the real sale price 85.
			}
		}
		$this->assertNotNull( $id );
		$products[ $id ] = $this->fake_product( $id, '100', '85' );
		Bot_Guard::reset();
		$regular = Bot_Guard::substitute_price( 100, $id );
		$sale    = Bot_Guard::substitute_price( 85, $id );
		$this->assertNotSame( 85.0, $regular, 'the real 85 does not appear as the substitute of the regular price' );
		$this->assertNotSame( 100.0, $regular );
		$this->assertNotSame( 100.0, $sale, 'nor the real 100 as the substitute of the sale price' );
		$this->assertNotSame( 85.0, $sale );
		$this->assertGreaterThanOrEqual( 85.0, $regular );
		$this->assertLessThanOrEqual( 115.0, $regular );
		$this->assertSame( $regular, Bot_Guard::substitute_price( 100, $id ), 'and it is the same all day' );
	}

	/** @return array<int,object> */
	private function variable_products( $a = '60', $b = '70' ) {
		$products = array(
			10 => $this->fake_product( 10, $a, '', 0, array( 11, 12 ) ),
			11 => $this->fake_product( 11, $a, '', 10 ),
			12 => $this->fake_product( 12, $b, '', 10 ),
		);
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) use ( &$products ) {
				return $products[ (int) $id ] ?? false;
			}
		);
		return $products;
	}

	/**
	 * What a bad bot is shown for a variable product (variations 11 and 12, prices $a and $b) on one day: the minimum and maximum
	 * of the Store API answer in minor units, and the display prices of the variations on the page in minor units.
	 */
	private function variable_shown( array $products, $day, $a, $b ) {
		Bot_Guard::reset();
		$day_filter = fn() => $day;
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) use ( $day_filter ) {
				return 'socialscore_factor_day' === $tag ? $day_filter() : $value;
			}
		);
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$guard = new Bot_Guard();
		$api   = $guard->store_api(
			$this->store_api_response(
				array(
					'id'     => 10,
					'prices' => (object) array(
						'price'       => (string) ( $a * 100 ),
						'price_range' => (object) array(
							'min_amount' => (string) ( $a * 100 ),
							'max_amount' => (string) ( $b * 100 ),
						),
					),
				)
			),
			null,
			$this->store_route( '/wc/store/v1/products/10' )
		)->data['prices'];
		$page  = array();
		foreach ( array(
			11 => $a,
			12 => $b,
		) as $vid => $real ) {
			$data   = $guard->variation(
				array(
					'variation_id'          => $vid,
					'display_price'         => $real,
					'display_regular_price' => $real,
				),
				$products[10],
				$products[ $vid ]
			);
			$page[] = (int) round( $data['display_price'] * 100 );
		}
		return array( (int) $api->price_range->min_amount, (int) $api->price_range->max_amount, $page[0], $page[1] );
	}

	public function test_the_answer_and_the_page_agree_on_a_variable_product_on_every_day_and_show_no_real_price() {
		foreach ( array( array( 60, 70 ), array( 60, 60.2 ), array( 100, 85 ) ) as list( $a, $b ) ) {
			$products = $this->variable_products( (string) $a, (string) $b );
			for ( $d = 0; $d < 60; $d++ ) {
				$day = gmdate( 'Y-m-d', 1767225600 + $d * 86400 );
				list( $api_min, $api_max, $page_min, $page_max ) = $this->variable_shown( $products, $day, $a, $b );
				$msg = $a . '/' . $b . ' on ' . $day;
				$this->assertSame( $api_min, $page_min, 'minimum: answer and page agree, ' . $msg );
				$this->assertSame( $api_max, $page_max, 'maximum: answer and page agree, ' . $msg );
				$real = array( (int) round( $a * 100 ), (int) round( $b * 100 ), (int) round( $a ), (int) round( $b ) );
				foreach ( array( $api_min, $api_max ) as $shown ) {
					$this->assertNotContains( $shown, $real, 'no real price is shown, ' . $msg );
					$this->assertNotContains( (int) round( $shown / 100 ), array( (int) round( $a ), (int) round( $b ) ), 'nor its rounded form, ' . $msg );
				}
			}
		}
	}

	public function test_the_page_keeps_a_variation_price_the_data_level_produced_and_removes_any_other() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$guard = new Bot_Guard();
		$data  = $guard->variation(
			array(
				'variation_id'          => 11,
				'display_price'         => 60,
				'display_regular_price' => 60,
				'variation_description' => '',
			),
			null,
			$this->fake_product( 11, '60', '', 10 )
		);
		$this->assertSame( 'variation_id', array_key_first( $data ) );
		$json = wp_json_encode( $data );
		$out  = $guard->clean_page( '<form data-product_variations="' . esc_attr( $json ) . '"></form><p>{"display_price":77,"variation_id":11}</p>' );
		$this->assertStringContainsString( '"display_price":' . $data['display_price'], html_entity_decode( $out ), 'the substitute of the data level stays' );
		$this->assertStringContainsString( '"display_price":0', $out, 'a number the data level did not produce is removed' );
	}

	public function test_substitute_mode_closes_the_cart_and_the_checkout_to_a_bad_bot_only() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		foreach ( array( '/wc/store/v1/cart/add-item', '/wc/store/v1/checkout', '/wc/store/V1/Order/5', '/wc/store/cart' ) as $route ) {
			$this->assertInstanceOf( \WP_REST_Response::class, ( new Bot_Guard() )->guard_rest( null, null, $this->store_route( $route ) ), $route );
		}
		$this->assertNull( ( new Bot_Guard() )->guard_rest( null, null, $this->store_route( '/wc/store/v1/products/5' ) ) );
		$closes = new \ReflectionMethod( Bot_Guard::class, 'closes_cart' );
		$closes->setAccessible( true );
		$this->assertFalse( $closes->invoke( null ) );
		$_REQUEST['add-to-cart'] = '5';
		$this->assertTrue( $closes->invoke( null ), '?add-to-cart=' );
		unset( $_REQUEST['add-to-cart'] );
		$_GET['wc-ajax'] = 'add_to_cart';
		$this->assertTrue( $closes->invoke( null ), 'wc-ajax add_to_cart' );
		$_GET['wc-ajax'] = 'get_refreshed_fragments';
		$this->assertFalse( $closes->invoke( null ) );
		unset( $_GET['wc-ajax'] );
		$_REQUEST['action'] = 'woocommerce_add_to_cart';
		$this->assertTrue( $closes->invoke( null ), 'admin-ajax add to cart' );
		unset( $_REQUEST['action'] );
		$_POST['woocommerce-process-checkout-nonce'] = 'x';
		$this->assertTrue( $closes->invoke( null ), 'the checkout form' );
		unset( $_POST['woocommerce-process-checkout-nonce'] );
		// A person and another mode: not closed.
		$this->request(
			'Mozilla/5.0 Chrome/126.0',
			'/',
			array(
				'HTTP_ACCEPT'          => 'text/html',
				'HTTP_ACCEPT_LANGUAGE' => 'en',
				'HTTP_SEC_FETCH_MODE'  => 'navigate',
				'HTTP_SEC_FETCH_SITE'  => 'none',
			)
		);
		$this->assertNull( ( new Bot_Guard() )->guard_rest( null, null, $this->store_route( '/wc/store/v1/cart/add-item' ) ), 'a person adds to the cart' );
		$this->server_says( 'flag' );
		$this->request( 'curl/8.4.0' );
		$this->assertNull( ( new Bot_Guard() )->guard_rest( null, null, $this->store_route( '/wc/store/v1/cart/add-item' ) ), 'flag mode does not close it' );
	}

	public function test_the_quantity_field_shows_no_stock_to_a_bad_bot() {
		$this->server_says( 'flag' );
		$this->request( 'curl/8.4.0' );
		$guard = new Bot_Guard();
		$this->assertSame( -1, $guard->quantity_max( 2, $this->fake_product( 5, '10', '' ) ) );
		$args = $guard->quantity_args(
			array(
				'max_value' => 2,
				'min_value' => 1,
			),
			$this->fake_product( 5, '10', '' )
		);
		$this->assertSame( '', $args['max_value'] );
		$this->assertSame( 1, $args['min_value'] );
		$this->assertSame( 1, $guard->quantity_max( 1, $this->fake_product( 5, '10', '', 0, array(), true ) ), 'sold one at a time says nothing about the stock' );
		$this->request(
			'Mozilla/5.0 Chrome/126.0',
			'/',
			array(
				'HTTP_ACCEPT'          => 'text/html',
				'HTTP_ACCEPT_LANGUAGE' => 'en',
				'HTTP_SEC_FETCH_MODE'  => 'navigate',
				'HTTP_SEC_FETCH_SITE'  => 'none',
			)
		);
		$this->assertSame( 2, ( new Bot_Guard() )->quantity_max( 2, $this->fake_product( 5, '10', '' ) ), 'a visitor still sees the limit' );
	}

	public function test_the_factor_of_a_product_cannot_be_worked_out_without_the_secret_of_the_site() {
		$factors = array();
		foreach ( array( 'salt-one', 'salt-two', 'salt-three' ) as $salt ) {
			Functions\when( 'wp_salt' )->alias( fn() => $salt );
			Bot_Guard::reset();
			$factors[ $salt ] = array();
			for ( $id = 1; $id <= 40; $id++ ) {
				$factors[ $salt ][ $id ] = Bot_Guard::substitute_price( 100, $id );
			}
		}
		$same = count( array_filter( array_keys( $factors['salt-one'] ), fn( $id ) => $factors['salt-one'][ $id ] === $factors['salt-two'][ $id ] ) );
		$this->assertLessThan( 10, $same, 'another secret gives other factors' );
		$this->assertGreaterThan( 5, count( array_unique( $factors['salt-one'] ) ), 'and the factors differ between products' );
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-bot-guard.php' );
		$this->assertStringContainsString( "hash_hmac( 'sha256'", $source );
		$this->assertStringContainsString( "wp_salt( 'auth' )", $source );
		$this->assertStringNotContainsString( 'crc32', $source, 'no unkeyed checksum decides a factor' );
	}

	public function test_a_page_with_nothing_to_clean_is_returned_as_it_is() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$plain = '<html><p>Hello</p></html>';
		$this->assertSame( $plain, ( new Bot_Guard() )->clean_page( $plain ) );
		$this->assertSame( '', ( new Bot_Guard() )->clean_page( '' ) );
	}

	public function test_the_buffer_is_started_only_for_a_bad_bot_that_the_module_acts_on() {
		$this->server_says( 'substitute' );
		$level = ob_get_level();
		$this->request(
			'Mozilla/5.0 Chrome/126.0',
			'/',
			array(
				'HTTP_ACCEPT'          => 'text/html',
				'HTTP_ACCEPT_LANGUAGE' => 'en',
			)
		);
		( new Bot_Guard() )->start_buffer();
		$this->assertSame( $level, ob_get_level(), 'a person: no buffer' );
		$this->request( 'curl/8.4.0' );
		( new Bot_Guard() )->start_buffer();
		$this->assertSame( $level + 1, ob_get_level(), 'a bad bot: one buffer' );
		ob_end_clean();
		$this->assertGreaterThanOrEqual( 1, $this->nocache, 'the page is marked uncacheable' );
	}

	// Twice is the same as once ----------------------------------------------------------------------------------------

	public function test_the_api_scrub_run_twice_gives_the_same_answer_as_run_once() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$guard = new Bot_Guard();
		$route = $this->store_route( '/wc/store/v1/products/24' );
		$once  = $guard->store_api( $this->store_api_response( $this->real_product() ), null, $route )->data;
		$twice = $guard->store_api( $this->store_api_response( $this->real_product() ), null, $route );
		$twice = $guard->store_api( $twice, null, $route )->data;
		$this->assertEquals( $once, $twice );
		$this->assertStringNotContainsString( 'socialscore', wp_json_encode( $twice ), 'nothing in the answer says it was changed' );
		$this->assertSame( 9999, ( $guard->store_api( $this->store_api_response( array( 'add_to_cart' => (object) array( 'maximum' => 9999 ) ) ), null, $route )->data )['add_to_cart']->maximum );
	}

	public function test_a_batch_keeps_the_prices_its_sub_requests_already_substituted_and_loses_quantities() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$guard = new Bot_Guard();
		$inner = $guard->store_api( $this->store_api_response( $this->real_product() ), null, $this->store_route( '/wc/store/v1/products/24' ) )->data;
		$batch = array(
			'responses' => array(
				array(
					'status' => 200,
					'body'   => $inner,
				),
			),
		);
		$out   = $guard->store_api( $this->store_api_response( $batch ), null, $this->store_route( '/wc/store/v1/batch' ) )->data;
		$this->assertSame( $inner['prices']->price, $out['responses'][0]['body']['prices']->price, 'not substituted a second time' );
		$this->assertNull( $out['responses'][0]['body']['low_stock_remaining'] );
	}

	public function test_every_key_of_the_one_list_is_covered_by_the_api_filter() {
		$this->server_says( 'flag' );
		$this->request( 'curl/8.4.0' );
		$data = array(
			'low_stock_remaining' => 2,
			'stock_quantity'      => 12,
			'quantity_in_stock'   => 3,
			'quantity_limits'     => (object) array(
				'minimum' => 1,
				'maximum' => 12,
			),
			'add_to_cart'         => array( 'maximum' => '5' ),
		);
		$out  = ( new Bot_Guard() )->store_api( $this->store_api_response( $data ), null, $this->store_route( '/wc/store/v1/products/24' ) )->data;
		foreach ( Bot_Guard::NULL_KEYS as $key ) {
			$this->assertNull( $out[ $key ], $key );
		}
		$this->assertSame( 9999, $out['quantity_limits']->maximum );
		$this->assertSame( 1, $out['quantity_limits']->minimum );
		$this->assertSame( 9999, $out['add_to_cart']['maximum'] );
	}

	// The page in every dress ------------------------------------------------------------------------------------------

	/** The same product data, as JSON that sits in HTML in each of the forms it is found in. */
	private function dressed( $quote ) {
		$q = $quote;
		return '<div data-x=' . "'" . '{' . $q . 'id' . $q . ':24,' . $q . 'prices' . $q . ':{' . $q . 'price' . $q . ':' . $q . '4747' . $q . ',' . $q . 'regular_price' . $q . ':' . $q . '5000' . $q . ',' . $q . 'price_range' . $q . ':{' . $q . 'min_amount' . $q . ':' . $q . '4747' . $q . ',' . $q . 'max_amount' . $q . ':' . $q . '9999' . $q . '}},'
			. $q . 'low_stock_remaining' . $q . ':2,' . $q . 'add_to_cart' . $q . ':{' . $q . 'minimum' . $q . ':1,' . $q . 'maximum' . $q . ':2}}' . "'" . '></div>';
	}

	public function dresses() {
		return array(
			'plain'       => array( '"' ),
			'escaped'     => array( '\\"' ),
			'double'      => array( '\\\\"' ),
			'entity name' => array( '&quot;' ),
			'entity num'  => array( '&#34;' ),
			'entity hex'  => array( '&#x22;' ),
			'percent'     => array( '%22' ),
			'unicode'     => array( '\\u0022' ),
		);
	}

	/**
	 * @dataProvider dresses
	 */
	public function test_quantities_go_in_every_dress_of_the_json_in_every_mode( $quote ) {
		foreach ( array( 'flag', 'substitute' ) as $mode ) {
			$this->server_says( $mode );
			$this->request( 'curl/8.4.0' );
			$out = ( new Bot_Guard() )->clean_page( $this->dressed( $quote ) );
			$this->assertStringNotContainsString( 'low_stock_remaining' . $quote . ':2', $out, $mode );
			$this->assertStringContainsString( 'low_stock_remaining' . $quote . ':null', $out, $mode );
			$this->assertStringContainsString( 'maximum' . $quote . ':9999', $out, $mode );
		}
	}

	/**
	 * @dataProvider dresses
	 */
	public function test_prices_go_in_every_dress_of_the_json_in_substitute_mode( $quote ) {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$out = ( new Bot_Guard() )->clean_page( $this->dressed( $quote ) );
		foreach ( array( '4747', '5000' ) as $real ) {
			$this->assertStringNotContainsString( $real, $out, $quote );
		}
		$this->assertStringContainsString( 'price' . $quote . ':' . $quote . '0' . $quote, $out );
		$this->assertStringNotContainsString( 'socialscore', $out, 'nothing in the page says it was changed' );
		// A second pass changes nothing.
		$this->assertSame( $out, ( new Bot_Guard() )->clean_page( $out ) );
	}

	public function test_flag_mode_leaves_the_selling_price_in_the_page() {
		$this->server_says( 'flag' );
		$this->request( 'curl/8.4.0' );
		$out = ( new Bot_Guard() )->clean_page( $this->dressed( '"' ) );
		$this->assertStringContainsString( '"price":"4747"', $out );
		$this->assertStringNotContainsString( 'socialscore', $out );
	}

	public function test_it_replaces_real_prices_in_shapes_it_does_not_know() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		// A deeper object, a number without quotes, a price range three levels down, an odd gap between key and value, a
		// decimal figure, braces that never close.
		$page = '<script>window.d={"id":7,"prices":{"a":{"b":{"c":{"price":4747}}},"regular_price" :  "5000","price_range":{"x":{"min_amount":"1234"}}},"stock_quantity" = 9, "quantity_in_stock":  8, "sale_price":"47.47", "oops":{"price":"50.00"';
		$out  = ( new Bot_Guard() )->clean_page( $page );
		foreach ( array( '4747', '5000', '1234', '47.47', '50.00', ':  8', '= 9' ) as $real ) {
			$this->assertStringNotContainsString( $real, $out, $real );
		}
		$this->assertSame( 2, substr_count( $out, 'null' ) );
		$this->assertStringContainsString( '"sale_price":"0"', $out );
	}

	public function test_quantities_go_wherever_the_page_writes_them_in_any_dress_of_the_braces_too() {
		$this->server_says( 'flag' );
		$this->request( 'curl/8.4.0' );
		$page = '<a data-x="add_to_cart&quot;:&#123;&quot;maximum&quot;:2&#x7d;">x</a>'
			. '<i data-y="%7b%22add_to_cart%22:%7b%22maximum%22:3%7d,%22quantity_limits%22:%7b%22maximum%22:4%7d%7d">y</i>'
			. '<script>{"add_to_cart":&lbrace;"maximum":5}</script><b>"quantity_limits"&lbrace;"maximum":6</b>'
			. '<u>"low_stock_remaining&#34;:&#x32;"</u><s>stock_quantity&lbrace;&quot;:12</s>';
		$out  = ( new Bot_Guard() )->clean_page( $page );
		$this->assertSame( 5, substr_count( $out, '9999' ), 'every maximum' );
		foreach ( array( 'maximum&quot;:2', 'maximum%22:3', 'maximum%22:4', '"maximum":5', '"maximum":6' ) as $real ) {
			$this->assertStringNotContainsString( $real, $out, $real );
		}
		$this->assertStringContainsString( 'stock_quantity&lbrace;&quot;:null', $out );
	}

	public function test_every_maximum_in_a_bad_bots_page_is_lifted_not_only_the_cart_limits() {
		$this->server_says( 'flag' );
		$this->request( 'curl/8.4.0' );
		$out = ( new Bot_Guard() )->clean_page( '<script>{"slider":{"maximum":100},"add_to_cart":{"maximum":2}}</script>' );
		$this->assertSame( 2, substr_count( $out, '"maximum":9999' ) );
	}

	public function test_a_page_with_a_word_that_only_looks_like_a_key_is_not_touched() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$page = '<p>The surprice 12 and valid 5 and pid 77, stock_quantity_text 4 is fine</p><script>var id=3;</script>';
		$this->assertSame( $page, ( new Bot_Guard() )->clean_page( $page ) );
	}

	// Nothing tells the bot, and nothing is skipped on the page's word ------------------------------------------------------

	public function test_neither_the_api_answer_nor_the_page_says_it_was_changed() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$guard = new Bot_Guard();
		$api   = $guard->store_api( $this->store_api_response( $this->real_product() ), null, $this->store_route( '/wc/store/v1/products/24' ) )->data;
		$this->assertStringNotContainsString( 'socialscore', strtolower( wp_json_encode( $api ) ) );
		$this->assertStringNotContainsString( 'shown', strtolower( wp_json_encode( $api ) ) );
		$page = $guard->clean_page( $this->hydrated_page() );
		$this->assertStringNotContainsString( 'socialscore', strtolower( $page ) );
	}

	public function test_a_very_long_object_before_prices_and_before_maximum_is_followed_to_its_end() {
		$this->server_says( 'flag' );
		$this->request( 'curl/8.4.0' );
		$long = str_repeat( '"note":"' . str_repeat( 'x', 90 ) . '",', 12 ); // Over a thousand characters.
		$page = '<script>{"add_to_cart":{' . $long . '"maximum":2},"quantity_limits":{' . $long . '"minimum":1,"maximum":5}}</script>';
		$out  = ( new Bot_Guard() )->clean_page( $page );
		$this->assertSame( 2, substr_count( $out, '"maximum":9999' ), 'both limits, however far down' );
		$this->assertStringContainsString( '"minimum":1', $out );
	}

	public function test_unbalanced_limit_objects_lift_every_maximum_in_the_rest_of_the_page() {
		$this->server_says( 'flag' );
		$this->request( 'curl/8.4.0' );
		$out = ( new Bot_Guard() )->clean_page( '<p>{"add_to_cart":{"text":"x","maximum":3 </p><p>{"quantity":{"maximum":4}}</p><script>"maximum":7</script>' );
		$this->assertSame( 3, substr_count( $out, '9999' ) );
		$this->assertStringNotContainsString( '"maximum":3', $out );
	}


	// Whatever is unknown is replaced; what the server produced stays ----------------------------------------------------------

	private function no_real_price_in( $text, array $real ) {
		foreach ( $real as $number ) {
			$this->assertDoesNotMatchRegularExpression( '/(?<![\d.])' . preg_quote( (string) $number, '/' ) . '(?![\d])/', $text, 'the real price ' . $number . ' is still there' );
		}
	}

	public function test_a_product_nobody_recorded_is_replaced_all_the_same() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$out = ( new Bot_Guard() )->clean_page( '<script>{"id":424242,"prices":{"price":"4747","regular_price":"5000","sale_price":"47.47"}}</script>' );
		$this->no_real_price_in( $out, array( '4747', '5000', '47.47' ) );
	}

	public function test_a_price_with_no_id_at_all_is_removed_not_scaled() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$this->assertSame( '<p>"price":"0"</p>', ( new Bot_Guard() )->clean_page( '<p>"price":"4747"</p>' ) );
		$this->assertSame( '0', Bot_Guard::page_value( '4747', null ) );
		$this->assertFalse( method_exists( Bot_Guard::class, 'substitute_price_by_value' ) );
	}

	public function test_two_hundred_and_one_variations_and_more_are_all_replaced() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$page = '<script>{"id":5,"variations":[';
		$real = array();
		for ( $i = 1; $i <= 450; $i++ ) {
			$price  = 1000 + $i * 7;
			$real[] = $price;
			$page  .= '{"id":' . ( 100 + $i ) . ',"prices":{"price":"' . $price . '"}},';
		}
		$out = ( new Bot_Guard() )->clean_page( $page . ']}</script>' );
		preg_match_all( '/"price":"(\d+)"/', $out, $shown );
		$this->assertCount( 450, $shown[1] );
		foreach ( $real as $i => $price ) {
			$this->assertNotSame( $price, (int) $shown[1][ $i ], 'variation ' . ( $i + 1 ) . ' keeps its real price' );
		}
	}

	public function test_when_the_registry_overflows_every_number_is_replaced_and_none_remembered() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$page = '';
		for ( $i = 1; $i <= Bot_Guard::REGISTRY_LIMIT + 50; $i++ ) {
			$page .= '"price":"' . ( 100000 + $i ) . '",';
		}
		$guard = new Bot_Guard();
		$out   = $guard->clean_page( $page );
		preg_match_all( '/"price":"(\d+)"/', $out, $shown );
		$this->assertCount( Bot_Guard::REGISTRY_LIMIT + 50, $shown[1] );
		for ( $i = Bot_Guard::REGISTRY_LIMIT + 1; $i <= Bot_Guard::REGISTRY_LIMIT + 50; $i++ ) {
			$this->assertNotContains( (string) ( 100000 + $i ), $shown[1], 'past the limit too' );
		}
	}

	public function test_a_review_with_fake_ids_and_numbers_cannot_keep_a_real_price_in_place() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$review  = '<p>"id":777 great, "price":"1" "id": 24 "price":4747 "id":0 "id":"4747"</p>';
		$product = '<script>{"id":24,"prices":{"price":"4747","regular_price":"5000"}}</script>';
		$out     = ( new Bot_Guard() )->clean_page( $review . $product );
		$this->assertStringNotContainsString( '"price":"4747"', $out );
		$this->assertStringNotContainsString( '"regular_price":"5000"', $out );
		// A fake id changes the factor, never whether the price is replaced.
		foreach ( array( '<p>"id":999</p>', '<p>"id":"x"</p>', '' ) as $before ) {
			Bot_Guard::reset();
			$this->no_real_price_in( ( new Bot_Guard() )->clean_page( $before . $product ), array( '4747', '5000' ) );
		}
	}

	public function test_what_the_server_produced_stays_and_a_second_pass_changes_nothing() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$guard = new Bot_Guard();
		$page  = '<script>{"id":24,"prices":{"price":"4747","regular_price":"5000","sale_price":"47.47","price_range":{"min_amount":"4747","max_amount":"9999"}}}</script>';
		$once  = $guard->clean_page( $page );
		$this->assertSame( $once, $guard->clean_page( $once ) );
		$this->assertSame( $once, $guard->clean_page( $once ) );
	}

	public function test_the_api_answer_run_twice_is_the_same_as_once_and_leaves_no_real_price() {
		$this->server_says( 'substitute' );
		$this->request( 'curl/8.4.0' );
		$guard = new Bot_Guard();
		$route = $this->store_route( '/wc/store/v1/products/24' );
		$once  = $guard->store_api( $this->store_api_response( $this->real_product() ), null, $route );
		$twice = $guard->store_api( $once, null, $route )->data;
		$this->assertEquals( $once->data, $twice );
		$this->no_real_price_in( wp_json_encode( $twice ), array( '990', '1290', '1990' ) );
	}
}
