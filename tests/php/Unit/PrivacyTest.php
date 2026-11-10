<?php
namespace SocialScore\Tests\Unit;

use Brain\Monkey\Functions;
use SocialScore\Connection;
use SocialScore\Privacy;
use SocialScore\Tests\TestCase;

class PrivacyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wc_get_orders' )->justReturn( array() );
	}

	private function user( $id ) {
		Functions\when( 'get_user_by' )->alias( fn( $field, $email ) => $id ? (object) array( 'ID' => $id ) : false );
	}

	public function test_registers_exporter_and_eraser_on_the_wordpress_filters() {
		\Brain\Monkey\Filters\expectAdded( 'wp_privacy_personal_data_exporters' )->once();
		\Brain\Monkey\Filters\expectAdded( 'wp_privacy_personal_data_erasers' )->once();
		$privacy = new Privacy();
		$privacy->register();

		$exporters = $privacy->register_exporter( array() );
		$erasers   = $privacy->register_eraser( array() );
		$this->assertSame( array( $privacy, 'export' ), $exporters['socialscore']['callback'] );
		$this->assertSame( array( $privacy, 'erase' ), $erasers['socialscore']['callback'] );
		$this->assertArrayHasKey( 'exporter_friendly_name', $exporters['socialscore'] );
		$this->assertArrayHasKey( 'eraser_friendly_name', $erasers['socialscore'] );
	}

	public function test_external_ids_are_the_account_id_and_the_guest_id() {
		$this->user( 15 );
		$this->assertSame( array( '15', 'guest-' . sha1( 'a@b.bg' ) ), Privacy::external_ids( ' A@B.bg ' ) );
		$this->user( 0 );
		$this->assertSame( array( 'guest-' . sha1( 'a@b.bg' ) ), Privacy::external_ids( 'a@b.bg' ) );
		$this->assertSame( array(), Privacy::external_ids( '' ) );
	}

	public function test_erase_calls_the_per_customer_delete_route_with_the_store_key() {
		$this->connect_store();
		$this->user( 15 );
		$this->http_responder = function ( $url ) {
			return self::response( 200, array( 'store_id' => 42, 'external_id' => '15', 'customers_redacted' => false !== strpos( $url, '/customers/15' ) ? 1 : 0, 'orders_redacted' => 3, 'profile_erased' => true ) );
		};
		$result = ( new Privacy() )->erase( 'a@b.bg', 1 );

		$this->assertCount( 2, $this->http );
		$this->assertSame( 'DELETE', $this->http[0]['args']['method'] );
		$this->assertSame( 'https://api.socialscore.io/api/v2/stores/42/customers/15', $this->http[0]['url'] );
		$this->assertSame( 'https://api.socialscore.io/api/v2/stores/42/customers/guest-' . sha1( 'a@b.bg' ), $this->http[1]['url'] );
		$this->assertSame( 'sk_test_secret_key', $this->http[0]['args']['headers']['X-API-KEY'] );
		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_erase_reports_nothing_removed_when_the_store_had_no_such_customer() {
		$this->connect_store();
		$this->user( 0 );
		$this->http_responder = fn() => self::response( 200, array( 'customers_redacted' => 0, 'orders_redacted' => 0, 'profile_erased' => false ) );
		$result               = ( new Privacy() )->erase( 'a@b.bg' );
		$this->assertFalse( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
	}

	public function test_erase_retains_and_says_so_when_socialscore_fails() {
		$this->connect_store();
		$this->user( 0 );
		$this->http_responder = fn() => self::response( 503, array( 'detail' => 'read_log_unavailable' ) );
		$result               = ( new Privacy() )->erase( 'a@b.bg' );
		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertCount( 1, $result['messages'] );
	}

	public function test_erase_and_export_send_nothing_when_not_connected() {
		$this->user( 15 );
		$privacy = new Privacy();
		$erased  = $privacy->erase( 'a@b.bg' );
		$this->assertFalse( $erased['items_removed'] );
		$this->assertTrue( $erased['items_retained'], 'data sent before a disconnect may still be held' );
		$this->assertCount( 1, $erased['messages'] );
		$this->assertTrue( $erased['done'] );
		$this->assertSame( array( 'data' => array(), 'done' => true ), $privacy->export( 'a@b.bg' ) );
		$this->assertSame( array(), $this->http );
	}

	public function test_export_turns_the_answer_into_wordpress_export_groups() {
		$this->connect_store();
		$this->user( 0 );
		$this->http_responder = fn() => self::response(
			200,
			array(
				'store_id' => 42,
				'customer' => array( 'external_id' => 'guest-x', 'email' => 'a@b.bg', 'first_name' => 'Ivan', 'orders_count' => 3, 'phone' => '' ),
				'orders'   => array( array( 'external_id' => '1001', 'total' => '59.90', 'currency' => 'EUR', 'labels' => array( 'review_before_shipping', 'new_customer' ), 'items' => array( array( 'product_id' => '9', 'name' => 'Boots', 'quantity' => 2, 'price' => '29.95' ) ) ) ),
				'profile'  => array( 'schema_version' => '2.0' ),
			)
		);
		$result = ( new Privacy() )->export( 'a@b.bg', 1 );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 'GET', $this->http[0]['args']['method'] );
		$this->assertStringEndsWith( '/stores/42/customers/guest-' . sha1( 'a@b.bg' ) . '/export', $this->http[0]['url'] );
		$groups = array_column( $result['data'], 'group_id' );
		$this->assertSame( array( 'socialscore-customer', 'socialscore-orders', 'socialscore-profiles' ), $groups );
		$customer = array_column( $result['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'a@b.bg', $customer['Email'] );
		$this->assertSame( 'Ivan', $customer['First name'] );
		$this->assertArrayNotHasKey( 'Phone', $customer, 'empty values are left out' );
		$order = array_column( $result['data'][1]['data'], 'value', 'name' );
		$this->assertSame( '2 x Boots', $order['Items'] );
		$this->assertSame( 'review_before_shipping, new_customer', $order['Check labels'] );
		foreach ( $result['data'] as $group ) {
			foreach ( $group['data'] as $row ) {
				$this->assertIsString( $row['value'] );
			}
		}
	}

	public function test_export_skips_ids_unknown_to_socialscore() {
		$this->connect_store();
		$this->user( 15 );
		$this->http_responder = fn() => self::response( 404, array( 'detail' => 'customer_not_found' ) );
		$this->assertSame( array(), ( new Privacy() )->export( 'a@b.bg' )['data'] );
		$this->assertCount( 2, $this->http );
	}

	public function test_a_401_during_erasure_drops_the_dead_key() {
		$this->connect_store();
		$this->user( 0 );
		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
		$this->http_responder = fn() => self::response( 401, array( 'detail' => 'Invalid API key' ) );
		$result               = ( new Privacy() )->erase( 'a@b.bg' );
		$this->assertTrue( $result['items_retained'] );
		$this->assertFalse( Connection::is_connected() );
		$this->assertTrue( Connection::was_lost() );
	}

	public function test_erase_removes_the_pixel_visit_ids_from_the_orders_of_the_email() {
		$this->user( 0 );
		$with    = new \WC_Order( array( 'id' => 1 ) );
		$with->meta = array( '_ss_pixel_visitor' => 'a', '_ss_pixel_session' => 'b', '_ss_pixel_purchase_sent' => '1', 'other' => 'x' );
		$without = new \WC_Order( array( 'id' => 2 ) );
		$seen    = null;
		Functions\when( 'wc_get_orders' )->alias(
			function ( $args ) use ( &$seen, $with, $without ) {
				$seen = $args;
				return array( $with, $without );
			}
		);
		$result = ( new Privacy() )->erase( ' A@B.bg ' );
		$this->assertSame( 'a@b.bg', $seen['billing_email'] );
		$this->assertSame( array( '_ss_pixel_purchase_sent' => '1', 'other' => 'x' ), $with->meta, 'both visit ids are gone, the rest stays' );
		$this->assertSame( 1, $with->meta_saves );
		$this->assertSame( 0, $without->meta_saves );
		$this->assertTrue( $result['items_removed'], 'a store that is not connected still erased the local ids' );
		$this->assertTrue( $result['items_retained'], 'and still says SocialScore could not be asked' );
	}
}
