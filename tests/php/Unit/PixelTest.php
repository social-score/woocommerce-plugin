<?php
namespace SocialScore\Tests\Unit;

use Brain\Monkey\Functions;
use SocialScore\Mapper;
use SocialScore\Pixel;
use SocialScore\Rest_Proxy;
use SocialScore\Tests\TestCase;

class PixelTest extends TestCase {

	const SITE = 'ps_abcdefghijklmnop';
	const V4_A = '3f2b8c1e-5d4a-4b7e-9c3d-1a2b3c4d5e6f';
	const V4_B = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

	/** @var array Inline scripts added: handle => list of code. */
	private $inline = array();

	protected function setUp(): void {
		parent::setUp();
		unset( $_COOKIE['ss_pv'], $_COOKIE['ss_ps'] );
		$this->inline = array();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_script_is' )->justReturn( true );
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $code ) {
				$this->inline[ $handle ][] = $code;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		unset( $_COOKIE['ss_pv'], $_COOKIE['ss_ps'] );
		parent::tearDown();
	}

	private function pixel_answer( $enabled = true, $site = self::SITE ) {
		$this->http_responder = fn() => self::response( 200, array( 'enabled' => $enabled, 'site_id' => $site, 'snippet_url' => 'https://pixel.socialscore.io/v1/p.js' ) );
	}

	// UUIDs and cookies ---------------------------------------------------------------------------------------

	public function test_only_uuid_v4_passes() {
		$this->assertSame( self::V4_A, Pixel::uuid( self::V4_A ) );
		$this->assertSame( self::V4_A, Pixel::uuid( strtoupper( self::V4_A ) ), 'case is normalised' );
		foreach ( array( '', 'abc', '3f2b8c1e-5d4a-1b7e-9c3d-1a2b3c4d5e6f', '3f2b8c1e-5d4a-4b7e-7c3d-1a2b3c4d5e6f', self::V4_A . "\n", self::V4_A . 'x', '<script>', array( self::V4_A ), null, 12 ) as $bad ) {
			$this->assertSame( '', Pixel::uuid( $bad ), 'rejects ' . json_encode( $bad ) );
		}
	}

	public function test_cookie_uuid_reads_only_valid_strings() {
		$this->assertSame( '', Pixel::cookie_uuid( 'ss_pv' ) );
		$_COOKIE['ss_pv'] = self::V4_A;
		$this->assertSame( self::V4_A, Pixel::cookie_uuid( 'ss_pv' ) );
		$_COOKIE['ss_pv'] = array( self::V4_A );
		$this->assertSame( '', Pixel::cookie_uuid( 'ss_pv' ) );
		$_COOKIE['ss_pv'] = '1; DROP TABLE';
		$this->assertSame( '', Pixel::cookie_uuid( 'ss_pv' ) );
	}

	// Order link: classic and block checkout ---------------------------------------------------------------------

	public function test_classic_checkout_stores_both_ids_in_order_meta() {
		$_COOKIE['ss_pv'] = self::V4_A;
		$_COOKIE['ss_ps'] = self::V4_B;
		$order            = new \WC_Order();
		$this->assertTrue( ( new Pixel() )->link_order( $order ) );
		$this->assertSame( self::V4_A, $order->meta['_ss_pixel_visitor'] );
		$this->assertSame( self::V4_B, $order->meta['_ss_pixel_session'] );
		$this->assertSame( 0, $order->meta_saves, 'the classic hook runs before the order is saved, so it only sets meta' );
	}

	public function test_block_checkout_stores_ids_and_saves_the_meta() {
		$_COOKIE['ss_pv'] = self::V4_A;
		$_COOKIE['ss_ps'] = self::V4_B;
		$order            = new \WC_Order();
		( new Pixel() )->link_order_and_save( $order );
		$this->assertSame( self::V4_A, $order->meta['_ss_pixel_visitor'] );
		$this->assertSame( self::V4_B, $order->meta['_ss_pixel_session'] );
		$this->assertSame( 1, $order->meta_saves );
		( new Pixel() )->link_order_and_save( $order );
		$this->assertSame( 1, $order->meta_saves, 'nothing changed, nothing written again' );
	}

	public function test_invalid_cookie_is_ignored_and_the_other_one_still_counts() {
		$_COOKIE['ss_pv'] = 'not-a-uuid';
		$_COOKIE['ss_ps'] = self::V4_B;
		$order            = new \WC_Order();
		( new Pixel() )->link_order( $order );
		$this->assertArrayNotHasKey( '_ss_pixel_visitor', $order->meta );
		$this->assertSame( self::V4_B, $order->meta['_ss_pixel_session'] );
	}

	public function test_no_cookie_leaves_an_earlier_value_alone() {
		$order                           = new \WC_Order();
		$order->meta['_ss_pixel_visitor'] = self::V4_A;
		$this->assertFalse( ( new Pixel() )->link_order( $order ) );
		$_COOKIE['ss_pv'] = '<b>';
		$this->assertFalse( ( new Pixel() )->link_order( $order ) );
		$this->assertSame( self::V4_A, $order->meta['_ss_pixel_visitor'] );
	}

	public function test_both_hooks_are_registered_also_in_the_admin() {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\expect( 'add_action' )->with( 'woocommerce_checkout_create_order', \Mockery::any(), 10, 1 )->once();
		Functions\expect( 'add_action' )->with( 'woocommerce_store_api_checkout_update_order_from_request', \Mockery::any(), 10, 1 )->once();
		Functions\expect( 'add_action' )->with( 'woocommerce_store_api_checkout_order_processed', \Mockery::any(), 10, 1 )->once();
		Functions\expect( 'add_action' )->with( 'wp_enqueue_scripts', \Mockery::any() )->never();
		( new Pixel() )->register();
		$this->addToAssertionCount( 1 ); // The Mockery expectations above are checked at tear down.
	}

	// Mapper ----------------------------------------------------------------------------------------------------

	public function test_mapper_sends_the_ids_and_leaves_out_bad_ones() {
		$order                            = new \WC_Order( array( 'id' => 5 ) );
		$order->meta['_ss_pixel_visitor'] = self::V4_A;
		$order->meta['_ss_pixel_session'] = 'javascript:alert(1)';
		$row                              = Mapper::order( $order );
		$this->assertSame( self::V4_A, $row['pixel_visitor_id'] );
		$this->assertArrayNotHasKey( 'pixel_session_id', $row );
		$this->assertArrayNotHasKey( 'pixel_visitor_id', Mapper::order( new \WC_Order() ) );
	}

	// State and loader -------------------------------------------------------------------------------------------

	public function test_state_is_off_when_not_connected_and_makes_no_request() {
		$this->assertFalse( Pixel::state()['enabled'] );
		$this->assertSame( array(), $this->http );
	}

	public function test_state_reads_the_api_once_per_ten_minutes() {
		$this->connect_store( 'st_1' );
		$this->pixel_answer();
		$this->assertSame( array( 'enabled' => true, 'site_id' => self::SITE ), Pixel::state() );
		Pixel::state();
		$this->assertCount( 1, $this->http );
		$this->assertSame( 'GET', $this->http[0]['args']['method'] );
		$this->assertStringEndsWith( '/api/v2/stores/st_1/pixel', $this->http[0]['url'] );
		$this->assertSame( 'sk_test_secret_key', $this->http[0]['args']['headers']['X-API-KEY'] );
		Pixel::forget();
		Pixel::state();
		$this->assertCount( 2, $this->http );
	}

	public function test_failed_lookup_means_off_and_is_not_retried_on_every_page() {
		$this->connect_store( 'st_1' );
		$this->http_responder = fn() => self::response( 500, array() );
		$this->assertFalse( Pixel::state()['enabled'] );
		Pixel::state();
		$this->assertCount( 1, $this->http );
	}

	public function test_a_site_id_that_is_not_valid_turns_the_pixel_off() {
		$this->connect_store( 'st_1' );
		$this->pixel_answer( true, 'ps_x");alert(1);//' );
		$this->assertSame( array( 'enabled' => false, 'site_id' => '' ), Pixel::state() );
	}

	public function test_loader_gets_the_site_id_and_an_empty_source() {
		$js = Pixel::render_loader( self::SITE );
		$this->assertStringContainsString( 'setAttribute("data-site","' . self::SITE . '")', $js );
		$this->assertStringContainsString( 'setAttribute("data-consent-source","")', $js );
		$this->assertStringNotContainsString( '{{', $js );
		$this->assertStringContainsString( 'https://pixel.socialscore.io/v1/p.js', $js );
	}

	public function test_loader_is_never_rendered_for_a_value_that_fails_validation() {
		foreach ( array( '', 'ps_ABCDEFGHIJKLMNOP', 'ps_abcdefghijklmno', 'ps_abcdefghijklmnop1', 'ps_abcdefghijklmno1', 'ps_abcdefghijklmnop"', "ps_abcdefghijklmnop\n", 'xs_abcdefghijklmnop' ) as $bad ) {
			$this->assertSame( '', Pixel::render_loader( $bad ), 'site id ' . json_encode( $bad ) );
		}
		foreach ( array( 'x', 'SHOPIFY', 'shopify"', "shopify\n", '");alert(1);//' ) as $bad ) {
			$this->assertSame( '', Pixel::render_loader( self::SITE, $bad ), 'source ' . json_encode( $bad ) );
		}
		$this->assertStringContainsString( 'data-consent-source","shopify"', Pixel::render_loader( self::SITE, 'shopify' ) );
	}

	public function test_the_shipped_loader_still_has_the_two_placeholders() {
		$js = file_get_contents( SOCIALSCORE_DIR . 'assets/pixel/loader.min.js' );
		$this->assertSame( 1, substr_count( $js, '{{SITE_ID}}' ) );
		$this->assertSame( 1, substr_count( $js, '{{CONSENT_SOURCE}}' ) );
	}

	// Storefront output --------------------------------------------------------------------------------------------

	public function test_nothing_is_printed_when_the_pixel_is_off() {
		$this->connect_store( 'st_1' );
		$this->pixel_answer( false );
		Functions\expect( 'wp_register_script' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();
		( new Pixel() )->enqueue();
		$this->assertSame( array(), $this->inline );
	}

	public function test_nothing_is_printed_when_not_connected_or_in_the_admin() {
		Functions\expect( 'wp_register_script' )->never();
		( new Pixel() )->enqueue();
		$this->connect_store( 'st_1' );
		$this->pixel_answer();
		Functions\when( 'is_admin' )->justReturn( true );
		( new Pixel() )->enqueue();
		$this->assertSame( array(), $this->inline );
	}

	public function test_the_loader_is_printed_with_the_site_id_when_the_pixel_is_on() {
		$this->connect_store( 'st_1' );
		$this->pixel_answer();
		( new Pixel() )->enqueue();
		$this->assertStringContainsString( 'data-site","' . self::SITE . '"', $this->inline['socialscore-pixel'][0] );
		$this->assertCount( 1, $this->inline['socialscore-pixel'], 'no events on a plain page' );
	}

	public function test_product_page_pushes_view_item() {
		$this->connect_store( 'st_1' );
		$this->pixel_answer();
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 77 );
		Functions\when( 'wc_get_product' )->justReturn( new class() {
			public function get_id() {
				return 77;
			}
			public function get_price() {
				return '19.90';
			}
		} );
		( new Pixel() )->enqueue();
		$this->assertSame(
			'window.ssPixel=window.ssPixel||[];window.ssPixel.push({"type":"view_item","data":{"item_id":"77","price":19.9,"currency":"EUR"}});',
			$this->inline['socialscore-pixel'][1]
		);
	}

	private function cart( $total, $lines, $empty = false ) {
		return new class( $total, $lines, $empty ) {
			private $t;
			private $l;
			private $e;
			public function __construct( $t, $l, $e ) {
				list( $this->t, $this->l, $this->e ) = array( $t, $l, $e );
			}
			public function is_empty() {
				return $this->e;
			}
			public function get_total() {
				return $this->t;
			}
			public function get_cart() {
				return array_fill( 0, $this->l, 1 );
			}
		};
	}

	public function test_checkout_page_pushes_begin_checkout_for_a_cart_with_items() {
		$this->connect_store( 'st_1' );
		$this->pixel_answer();
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'is_order_received_page' )->justReturn( false );
		Functions\when( 'is_checkout_pay_page' )->justReturn( false );
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $this->cart( 59.9, 2 ), 'session' => null ) );
		( new Pixel() )->enqueue();
		$this->assertStringContainsString( '{"type":"begin_checkout","data":{"value":59.9,"currency":"EUR","items":2}}', $this->inline['socialscore-pixel'][1] );
	}

	public function test_empty_cart_and_thank_you_page_push_no_begin_checkout() {
		$this->connect_store( 'st_1' );
		$this->pixel_answer();
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'is_order_received_page' )->justReturn( false );
		Functions\when( 'is_checkout_pay_page' )->justReturn( false );
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $this->cart( 0, 0, true ), 'session' => null ) );
		( new Pixel() )->enqueue();
		$this->assertCount( 1, $this->inline['socialscore-pixel'] );
	}

	private function session() {
		return new class() {
			public $data = array();
			public function get( $k ) {
				return $this->data[ $k ] ?? null;
			}
			public function set( $k, $v ) {
				$this->data[ $k ] = $v;
			}
		};
	}

	public function test_a_reloading_add_to_cart_is_kept_in_the_session_and_pushed_once_on_the_next_page() {
		$this->connect_store( 'st_1' );
		$this->pixel_answer();
		$session = $this->session();
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => null, 'session' => $session ) );
		Functions\when( 'wc_get_product' )->justReturn( new class() {
			public function get_price() {
				return '5.00';
			}
		} );
		$pixel = new Pixel();
		$pixel->remember_add_to_cart( 'abc', 12, 2, 0 );
		$this->assertSame( 'add_to_cart', $session->data['socialscore_pixel_events'][0]['type'] );
		$this->assertSame( array( 'item_id' => '12', 'quantity' => 2, 'price' => 5.0, 'currency' => 'EUR' ), $session->data['socialscore_pixel_events'][0]['data'] );

		( new Pixel() )->enqueue();
		$this->assertStringContainsString( '"type":"add_to_cart"', $this->inline['socialscore-pixel'][1] );
		$this->assertNull( $session->data['socialscore_pixel_events'], 'drained' );

		$this->inline = array();
		( new Pixel() )->enqueue();
		$this->assertCount( 1, $this->inline['socialscore-pixel'], 'not repeated on the following page' );
	}

	public function test_ajax_and_rest_adds_are_left_to_the_browser() {
		$this->connect_store( 'st_1' );
		$this->pixel_answer();
		$session = $this->session();
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => null, 'session' => $session ) );
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		( new Pixel() )->remember_add_to_cart( 'abc', 12, 1, 0 );
		$this->assertSame( array(), $session->data );
	}

	public function test_nothing_is_queued_while_the_pixel_is_off() {
		$this->connect_store( 'st_1' );
		$this->pixel_answer( false );
		$session = $this->session();
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => null, 'session' => $session ) );
		( new Pixel() )->remember_add_to_cart( 'abc', 12, 1, 0 );
		$this->assertSame( array(), $session->data );
		$this->assertSame( array( 'a' => 1 ), ( new Pixel() )->loop_button_args( array( 'a' => 1 ), new \stdClass() ) );
	}

	public function test_loop_button_carries_the_price_for_the_browser() {
		$this->connect_store( 'st_1' );
		$this->pixel_answer();
		$args = ( new Pixel() )->loop_button_args(
			array( 'attributes' => array( 'data-product_id' => 3 ) ),
			new class() {
				public function get_price() {
					return '7.5';
				}
			}
		);
		$this->assertSame( '7.5', $args['attributes']['data-ss-price'] );
		$this->assertSame( 3, $args['attributes']['data-product_id'] );
	}

	// Purchase ---------------------------------------------------------------------------------------------------

	public function test_purchase_is_pushed_once_per_order_and_holds_no_personal_data() {
		$this->connect_store( 'st_1' );
		$this->pixel_answer();
		$order = new \WC_Order( array( 'id' => 321, 'total' => '120.50', 'currency' => 'EUR', 'billing_email' => 'ana@example.com', 'billing_phone' => '+359888' ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$pixel = new Pixel();
		$pixel->purchase( 321 );
		$pixel->purchase( 321 );
		$this->assertCount( 1, $this->inline['socialscore-pixel'] );
		$this->assertSame(
			'window.ssPixel=window.ssPixel||[];window.ssPixel.push({"type":"purchase","data":{"order_external_id":"321","value":120.5,"currency":"EUR"}});',
			$this->inline['socialscore-pixel'][0]
		);
		$this->assertStringNotContainsString( 'identify', $this->inline['socialscore-pixel'][0] );
		$this->assertStringNotContainsString( 'example.com', $this->inline['socialscore-pixel'][0] );
		$this->assertSame( '1', $order->meta['_ss_pixel_purchase_sent'] );
		$this->assertSame( 1, $order->meta_saves );
	}

	public function test_purchase_before_the_loader_is_enqueued_is_pushed_with_the_loader() {
		$this->connect_store( 'st_1' );
		$this->pixel_answer();
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => null, 'session' => null ) );
		$order = new \WC_Order( array( 'id' => 322, 'total' => '10', 'currency' => 'EUR' ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$pixel = new Pixel();
		$pixel->purchase( 322 );
		$this->assertSame( array(), $this->inline, 'block themes render the page body before wp_enqueue_scripts' );
		$pixel->enqueue();
		$this->assertCount( 2, $this->inline['socialscore-pixel'] );
		$this->assertStringContainsString( '"type":"purchase","data":{"order_external_id":"322"', $this->inline['socialscore-pixel'][1] );
		$pixel->enqueue();
		$this->assertStringNotContainsString( 'purchase', implode( '', array_slice( $this->inline['socialscore-pixel'], 2 ) ) );
	}

	public function test_purchase_is_not_pushed_while_the_pixel_is_off_and_is_not_marked_sent() {
		$this->connect_store( 'st_1' );
		$this->pixel_answer( false );
		$order = new \WC_Order( array( 'id' => 321 ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		( new Pixel() )->purchase( 321 );
		$this->assertSame( array(), $this->inline );
		$this->assertArrayNotHasKey( '_ss_pixel_purchase_sent', $order->meta );
	}

	public function test_event_json_cannot_close_the_script_tag() {
		$this->connect_store( 'st_1' );
		$this->pixel_answer();
		$order = new \WC_Order( array( 'id' => 9, 'currency' => '</script><script>alert(1)</script>' ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		( new Pixel() )->purchase( 9 );
		$this->assertStringNotContainsString( '</script>', $this->inline['socialscore-pixel'][0] );
		$this->assertStringNotContainsString( '<', $this->inline['socialscore-pixel'][0] );
	}

	// Proxy allow-list -------------------------------------------------------------------------------------------

	public function test_proxy_allows_the_pixel_and_session_routes_and_nothing_wider() {
		$this->assertTrue( Rest_Proxy::is_allowed( 'GET', '/api/v2/stores/st_1/pixel', 'st_1' ) );
		$this->assertTrue( Rest_Proxy::is_allowed( 'PATCH', '/api/v2/stores/st_1/pixel', 'st_1' ) );
		$this->assertTrue( Rest_Proxy::is_allowed( 'GET', '/api/v2/stores/st_1/customers/guest-abc/session', 'st_1' ) );
		$this->assertTrue( Rest_Proxy::is_allowed( 'GET', '/api/v2/stores/st_1/orders/42/session', 'st_1' ) );
		$this->assertFalse( Rest_Proxy::is_allowed( 'POST', '/api/v2/stores/st_1/pixel', 'st_1' ) );
		$this->assertFalse( Rest_Proxy::is_allowed( 'PATCH', '/api/v2/stores/st_1/orders/42/session', 'st_1' ) );
		$this->assertFalse( Rest_Proxy::is_allowed( 'GET', '/api/v2/stores/st_2/pixel', 'st_1' ) );
		$this->assertFalse( Rest_Proxy::is_allowed( 'GET', '/api/v2/stores/st_1/pixel/x', 'st_1' ) );
		$this->assertFalse( Rest_Proxy::is_allowed( 'GET', '/api/v2/stores/st_1/orders/4/2/session', 'st_1' ) );
	}

	public function test_switching_the_pixel_through_the_proxy_drops_the_cached_state() {
		$this->connect_store( 'st_1' );
		$this->pixel_answer( false );
		Pixel::state();
		$this->assertCount( 1, $this->transients );
		$this->pixel_answer( true );
		$request         = new \WP_REST_Request();
		$request->method = 'PATCH';
		$request->params = array( 'path' => 'api/v2/stores/st_1/pixel' );
		$request->json   = array( 'enabled' => true );
		( new Rest_Proxy() )->handle( $request );
		$this->assertSame( array(), $this->transients );
		$this->assertTrue( Pixel::state()['enabled'] );
	}
}
