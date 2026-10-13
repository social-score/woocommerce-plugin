<?php
namespace SocialScore\Tests\Unit;

use SocialScore\Connection;
use SocialScore\Crypto;
use SocialScore\Tests\TestCase;

class CryptoConnectionTest extends TestCase {

	public function test_encrypt_round_trip_and_not_plain_text() {
		$stored = Crypto::encrypt( 'sk_live_abc' );
		$this->assertStringNotContainsString( 'sk_live_abc', $stored );
		$this->assertSame( 'sk_live_abc', Crypto::decrypt( $stored ) );
	}

	public function test_decrypt_garbage_returns_empty() {
		$this->assertSame( '', Crypto::decrypt( 'sodium:not-base64!!' ) );
		$this->assertSame( '', Crypto::decrypt( '' ) );
		$this->assertSame( '', Crypto::decrypt( 'sodium:' . base64_encode( 'short' ) ) );
	}

	public function test_connection_saved_with_autoload_off_and_key_encrypted() {
		$this->connect_store( '42', 'sk_test_secret_key' );
		$this->assertSame( 'no', $this->autoload['socialscore_connection'] );
		$this->assertStringNotContainsString( 'sk_test_secret_key', json_encode( $this->options['socialscore_connection'] ) );
		$this->assertTrue( Connection::is_connected() );
		$this->assertSame( '42', Connection::store_id() );
		$this->assertSame( 'sk_test_secret_key', Connection::api_key() );
	}

	public function test_clear_disconnects() {
		$this->connect_store();
		Connection::clear();
		$this->assertFalse( Connection::is_connected() );
		$this->assertSame( '', Connection::api_key() );
	}
}
