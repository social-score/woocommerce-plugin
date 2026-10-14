<?php
namespace SocialScore\Tests\Unit;

use Brain\Monkey\Functions;
use SocialScore\Api_Client;
use SocialScore\Connect;
use SocialScore\Connection;
use SocialScore\Tests\TestCase;

class ApiClientTest extends TestCase {

	public function test_a_401_on_a_store_route_forgets_the_key_and_stops_background_work() {
		$this->connect_store();
		Functions\expect( 'as_unschedule_all_actions' )->once()->with( '', array(), 'socialscore' );
		$this->http_responder = fn() => self::response( 401, array( 'detail' => 'Invalid API key' ) );

		$response = ( new Api_Client() )->get_settings();

		$this->assertSame( 401, $response['status'] );
		$this->assertFalse( Connection::is_connected() );
		$this->assertTrue( Connection::was_lost() );
		$this->assertSame( 'no', $this->autoload['socialscore_connection_lost'] ?? 'no' );
	}

	public function test_other_errors_keep_the_connection() {
		$this->connect_store();
		foreach ( array( 402, 403, 404, 429, 500 ) as $status ) {
			$this->http_responder = fn() => self::response( $status, array( 'detail' => 'x' ) );
			( new Api_Client() )->get_settings();
		}
		$this->assertTrue( Connection::is_connected() );
		$this->assertFalse( Connection::was_lost() );
	}

	public function test_connecting_again_clears_the_lost_flag() {
		$this->connect_store();
		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
		Connection::mark_lost();
		$this->assertTrue( Connection::was_lost() );
		$this->connect_store( '43', 'sk_new' );
		$this->assertFalse( Connection::was_lost() );
		$this->assertTrue( Connection::is_connected() );
	}

	public function test_a_deliberate_disconnect_is_not_a_lost_key_even_when_the_uninstall_call_answers_401() {
		$this->connect_store();
		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
		$this->http_responder = fn() => self::response( 401, array( 'detail' => 'Invalid API key' ) );
		Connect::disconnect();
		$this->assertFalse( Connection::is_connected() );
		$this->assertFalse( Connection::was_lost() );
		$this->assertSame( 'DELETE', $this->http[0]['args']['method'] );
	}

	public function test_token_exchange_401_does_not_touch_a_connection() {
		$this->connect_store();
		$this->http_responder = fn() => self::response( 401, array( 'detail' => 'x' ) );
		( new Api_Client() )->exchange_token( 'code', 'verifier', 'https://shop.test/wp-admin/admin-post.php' );
		$this->assertTrue( Connection::is_connected() );
	}
}
