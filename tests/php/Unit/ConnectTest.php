<?php
namespace SocialScore\Tests\Unit;

use Brain\Monkey\Functions;
use SocialScore\Connect;
use SocialScore\Connection;
use SocialScore\Tests\TestCase;

class ConnectTest extends TestCase {

	public function test_pkce_challenge_matches_rfc7636_vector() {
		$this->assertSame(
			'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
			Connect::challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' )
		);
	}

	public function test_begin_builds_connect_url_with_state_and_challenge() {
		$url = Connect::begin( 7 );
		$parts = parse_url( $url );
		parse_str( $parts['query'], $q );
		$this->assertSame( 'https://api.socialscore.io/api/v2/stores/connect', $parts['scheme'] . '://' . $parts['host'] . $parts['path'] );
		$this->assertSame( 'woocommerce', $q['platform'] );
		$this->assertSame( 'shop.test', $q['shop'], 'the bare domain, not a URL' );
		$this->assertSame( 'https://shop.test/wp-admin/admin-post.php?action=socialscore_connect_callback', $q['redirect_uri'] );
		$this->assertSame( 'S256', $q['code_challenge_method'] );
		$this->assertGreaterThanOrEqual( 32, strlen( $q['state'] ) );

		$pending = $this->transients[ 'socialscore_pkce_' . hash( 'sha256', $q['state'] ) ];
		$this->assertSame( 7, $pending['user_id'] );
		$this->assertSame( Connect::challenge( $pending['verifier'] ), $q['code_challenge'] );
	}

	public function test_shop_is_the_home_host_and_redirect_uri_comes_from_the_admin_url() {
		Functions\when( 'home_url' )->justReturn( 'https://www.shop.test' );
		Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'https://wp.shop.test/wp-admin/' . $p );
		parse_str( (string) parse_url( Connect::begin( 7 ), PHP_URL_QUERY ), $q );
		$this->assertSame( 'www.shop.test', $q['shop'] );
		$this->assertSame( 'https://wp.shop.test/wp-admin/admin-post.php?action=socialscore_connect_callback', $q['redirect_uri'] );
	}

	public function test_token_request_repeats_the_redirect_uri_stored_at_connect() {
		Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'https://wp.shop.test/wp-admin/' . $p );
		$state = $this->started_state();
		Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'https://changed.test/wp-admin/' . $p );
		$this->http_responder = fn() => self::response( 200, array( 'store_id' => 'st_9', 'api_key' => 'sk_new_key' ) );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );
		$this->assertSame( 'connected', ( new Connect() )->complete( $state, 'c', '', 7 ) );
		$this->assertSame( 'https://wp.shop.test/wp-admin/admin-post.php?action=socialscore_connect_callback', json_decode( $this->http[0]['args']['body'], true )['redirect_uri'] );
	}

	public function test_nothing_is_sent_before_connect() {
		Connect::begin( 1 );
		$this->assertSame( array(), $this->http );
	}

	private function started_state( $user_id = 7 ) {
		parse_str( parse_url( Connect::begin( $user_id ), PHP_URL_QUERY ), $q );
		return $q['state'];
	}

	public function test_callback_rejects_unknown_empty_and_foreign_state() {
		$connect = new Connect();
		$this->assertSame( 'state', $connect->complete( '', 'code', '', 7 ) );
		$this->assertSame( 'state', $connect->complete( 'unknown', 'code', '', 7 ) );
		$state = $this->started_state( 7 );
		$this->assertSame( 'state', $connect->complete( $state, 'code', '', 8 ), 'another user must not finish the flow' );
		$this->assertSame( array(), $this->http );
		$this->assertFalse( Connection::is_connected() );
	}

	public function test_state_is_single_use() {
		$connect = new Connect();
		$state   = $this->started_state();
		$this->http_responder = fn() => self::response( 500, '' );
		$this->assertSame( 'exchange', $connect->complete( $state, 'code', '', 7 ) );
		$this->assertSame( 'state', $connect->complete( $state, 'code', '', 7 ) );
	}

	public function test_denied_by_user_does_not_exchange() {
		$connect = new Connect();
		$this->assertSame( 'denied', $connect->complete( $this->started_state(), '', 'access_denied', 7 ) );
		$this->assertSame( array(), $this->http );
	}

	public function test_successful_exchange_sends_verifier_stores_key_and_starts_import() {
		$state = $this->started_state();
		$verifier = $this->transients[ 'socialscore_pkce_' . hash( 'sha256', $state ) ]['verifier'];
		$this->http_responder = fn() => self::response( 200, array( 'store_id' => 'st_9', 'api_key' => 'sk_new_key' ) );
		Functions\expect( 'as_enqueue_async_action' )->once()->with( 'socialscore_import', array( 'customers', 1, 0 ), 'socialscore' );

		$this->assertSame( 'connected', ( new Connect() )->complete( $state, 'the-code', '', 7 ) );

		$this->assertCount( 1, $this->http );
		$this->assertSame( 'https://api.socialscore.io/api/v2/stores/token', $this->http[0]['url'] );
		$body = json_decode( $this->http[0]['args']['body'], true );
		$this->assertEqualsCanonicalizing( array( 'grant_type', 'code', 'code_verifier', 'redirect_uri' ), array_keys( $body ), 'only the fields the API accepts' );
		$this->assertSame( 'https://shop.test/wp-admin/admin-post.php?action=socialscore_connect_callback', $body['redirect_uri'], 'same redirect_uri as at connect (RFC 6749 4.1.3)' );
		$this->assertSame( 'authorization_code', $body['grant_type'] );
		$this->assertSame( 'the-code', $body['code'] );
		$this->assertSame( $verifier, $body['code_verifier'] );
		$this->assertArrayNotHasKey( 'X-API-KEY', $this->http[0]['args']['headers'] );
		$this->assertSame( 'st_9', Connection::store_id() );
		$this->assertSame( 'sk_new_key', Connection::api_key() );
		$this->assertSame( 'no', $this->autoload['socialscore_connection'] );
	}

	public function test_exchange_with_unexpected_body_fails() {
		$state = $this->started_state();
		$this->http_responder = fn() => self::response( 200, array( 'nope' => 1 ) );
		$this->assertSame( 'exchange', ( new Connect() )->complete( $state, 'c', '', 7 ) );
		$this->assertFalse( Connection::is_connected() );
	}

	public function test_disconnect_deletes_store_and_forgets_key() {
		$this->connect_store( '42', 'sk_x' );
		$this->http_responder = fn() => self::response( 204, '' );
		Functions\expect( 'as_unschedule_all_actions' )->once()->with( '', array(), 'socialscore' );
		Connect::disconnect();
		$this->assertSame( 'DELETE', $this->http[0]['args']['method'] );
		$this->assertSame( 'https://api.socialscore.io/api/v2/stores/42', $this->http[0]['url'] );
		$this->assertFalse( Connection::is_connected() );
	}
}
