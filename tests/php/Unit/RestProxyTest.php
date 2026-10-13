<?php
namespace SocialScore\Tests\Unit;

use Brain\Monkey\Functions;
use SocialScore\Rest_Proxy;
use SocialScore\Tests\TestCase;

class RestProxyTest extends TestCase {

	private function request( $method, $path, array $extra = array() ) {
		$r = new \WP_REST_Request();
		$r->method = $method;
		$r->params['path'] = ltrim( $path, '/' );
		foreach ( $extra as $k => $v ) {
			$r->$k = $v;
		}
		return $r;
	}

	public function test_permission_requires_manage_woocommerce() {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( false );
		$this->assertFalse( ( new Rest_Proxy() )->permission() );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );
		$this->assertTrue( ( new Rest_Proxy() )->permission() );
	}

	public function test_route_registered_with_permission_callback() {
		Functions\expect( 'register_rest_route' )->once()->andReturnUsing(
			function ( $ns, $route, $args ) {
				$this->assertSame( 'socialscore/v1', $ns );
				$this->assertStringStartsWith( '/proxy/', $route );
				$this->assertIsCallable( $args['permission_callback'] );
				$this->assertNotContains( 'DELETE', $args['methods'] );
			}
		);
		( new Rest_Proxy() )->register_routes();
	}

	/**
	 * @dataProvider allowed_provider
	 */
	public function test_allow_list( $method, $path, $expected ) {
		$this->assertSame( $expected, Rest_Proxy::is_allowed( $method, $path, '42' ) );
	}

	public function allowed_provider() {
		return array(
			'settings get'        => array( 'GET', '/api/v2/stores/42/settings', true ),
			'settings patch'      => array( 'PATCH', '/api/v2/stores/42/settings', true ),
			'estimate'            => array( 'GET', '/api/v2/stores/42/settings/estimate', true ),
			'overview'            => array( 'GET', '/api/v2/stores/42/overview', true ),
			'customers list'      => array( 'GET', '/api/v2/stores/42/customers', true ),
			'customer profile'    => array( 'GET', '/api/v2/stores/42/customers/guest-abc123/profile', true ),
			'order profile'       => array( 'GET', '/api/v2/stores/42/orders/1001/profile', true ),
			'check'               => array( 'POST', '/api/v2/stores/42/checks', true ),
			'segments'            => array( 'GET', '/api/v2/segments', true ),
			'other store'         => array( 'GET', '/api/v2/stores/43/settings', false ),
			'delete store'        => array( 'DELETE', '/api/v2/stores/42', false ),
			'batch is server-only' => array( 'POST', '/api/v2/stores/42/customers:batch', false ),
			'hooks'               => array( 'POST', '/api/v2/hooks', false ),
			'traversal'           => array( 'GET', '/api/v2/stores/42/customers/../../keys/profile', false ),
			'wrong method'        => array( 'POST', '/api/v2/stores/42/overview', false ),
			'unknown'             => array( 'GET', '/api/v2/admin/users', false ),
			'trailing newline'    => array( 'GET', "/api/v2/stores/42/settings\n", false ),
			'newline in segments' => array( 'GET', "/api/v2/segments\n", false ),
			'newline in id'       => array( 'GET', "/api/v2/stores/42/orders/1\n/profile", false ),
		);
	}

	public function test_encoded_guest_id_from_the_browser_is_forwarded_decoded() {
		$this->connect_store();
		$this->http_responder = fn() => self::response( 200, array( 'ok' => true ) );
		$res = ( new Rest_Proxy() )->handle( $this->request( 'GET', '/api/v2/stores/42/customers/guest%3Aabc/profile' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertStringEndsWith( '/stores/42/customers/guest:abc/profile', $this->http[0]['url'] );
	}

	public function test_encoded_traversal_is_still_refused() {
		$this->connect_store();
		$res = ( new Rest_Proxy() )->handle( $this->request( 'GET', '/api/v2/stores/42/customers/%2e%2e/%2e%2e/keys/profile' ) );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( array(), $this->http );
	}

	public function test_not_connected_is_409_and_sends_nothing() {
		$res = ( new Rest_Proxy() )->handle( $this->request( 'GET', '/api/v2/segments' ) );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( array(), $this->http );
	}

	public function test_blocked_path_is_403_and_sends_nothing() {
		$this->connect_store();
		$res = ( new Rest_Proxy() )->handle( $this->request( 'GET', '/api/v2/stores/99/settings' ) );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( array(), $this->http );
	}

	public function test_forwards_with_server_side_key_and_never_returns_it() {
		$this->connect_store( '42', 'sk_test_secret_key' );
		$this->http_responder = fn() => self::response( 200, array( 'settings' => array( 'manual_only' => false ) ) );
		$res = ( new Rest_Proxy() )->handle(
			$this->request( 'GET', '/api/v2/stores/42/settings', array( 'query' => array( 'rest_route' => '/x', '_wpnonce' => 'n', 'page' => '2' ) ) )
		);
		$this->assertSame( 'sk_test_secret_key', $this->http[0]['args']['headers']['X-API-KEY'] );
		$this->assertSame( 'https://api.socialscore.io/api/v2/stores/42/settings?page=2', $this->http[0]['url'] );
		$this->assertSame( 200, $res->get_status() );
		$this->assertStringNotContainsString( 'sk_test_secret_key', json_encode( array( $res->get_data(), $res->headers ) ) );
	}

	public function test_a_key_echoed_nowhere_even_on_upstream_error() {
		$this->connect_store( '42', 'sk_test_secret_key' );
		$this->http_responder = fn() => new \WP_Error( 'http_request_failed', 'cURL error' );
		$res = ( new Rest_Proxy() )->handle( $this->request( 'GET', '/api/v2/segments' ) );
		$this->assertSame( 502, $res->get_status() );
		$this->assertStringNotContainsString( 'sk_test_secret_key', json_encode( $res->get_data() ) );
	}

	public function test_passes_status_error_code_and_retry_after() {
		$this->connect_store();
		$this->http_responder = fn() => self::response( 429, array( 'detail' => array( 'code' => 'rate_limited' ) ), array( 'retry-after' => '12' ) );
		$res = ( new Rest_Proxy() )->handle( $this->request( 'GET', '/api/v2/segments' ) );
		$this->assertSame( 429, $res->get_status() );
		$this->assertSame( '12', $res->headers['Retry-After'] );
	}

	public function test_forwards_valid_idempotency_key_only() {
		$this->connect_store();
		$this->http_responder = fn() => self::response( 200, array( 'labels' => array() ) );
		Functions\when( 'wc_get_order' )->justReturn( false );
		$proxy = new Rest_Proxy();
		$ok = $this->request( 'POST', '/api/v2/stores/42/checks', array( 'json' => array( 'customer_external_id' => '5' ), 'hdrs' => array( 'idempotency_key' => 'abc-12345678' ) ) );
		$proxy->handle( $ok );
		$this->assertSame( 'abc-12345678', $this->http[0]['args']['headers']['Idempotency-Key'] );

		$bad = $this->request( 'POST', '/api/v2/stores/42/checks', array( 'json' => array( 'customer_external_id' => '5' ), 'hdrs' => array( 'idempotency_key' => "bad key\r\nX: y" ) ) );
		$proxy->handle( $bad );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $this->http[1]['args']['headers'] );
	}

	public function test_manual_order_check_applies_labels_to_the_order() {
		$this->connect_store();
		$order = new \WC_Order( array( 'id' => 1001 ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->http_responder = fn() => self::response( 200, array( 'profile' => array(), 'labels' => array( 'review_before_shipping', 'bogus' ) ) );
		$res = ( new Rest_Proxy() )->handle(
			$this->request( 'POST', '/api/v2/stores/42/checks', array( 'json' => array( 'order_external_id' => '1001' ), 'hdrs' => array( 'idempotency_key' => 'k-12345678' ) ) )
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'review_before_shipping' ), $order->meta['_socialscore_labels'] );
	}
}
