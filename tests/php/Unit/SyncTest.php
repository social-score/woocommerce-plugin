<?php
namespace SocialScore\Tests\Unit;

use Brain\Monkey\Functions;
use SocialScore\Sync;
use SocialScore\Tests\TestCase;

class SyncTest extends TestCase {

	private function ok() {
		return array( 'status' => 200, 'data' => null, 'retry_after' => null );
	}

	public function test_push_in_batches_chunks_at_500() {
		$sizes = array();
		$rows  = array_map( fn( $i ) => array( 'external_id' => (string) $i ), range( 1, 1201 ) );
		$res   = Sync::push_in_batches(
			function ( $chunk ) use ( &$sizes ) {
				$sizes[] = count( $chunk );
				return $this->ok();
			},
			$rows
		);
		$this->assertSame( array( 500, 500, 201 ), $sizes );
		$this->assertSame( 200, $res['status'] );
	}

	public function test_push_in_batches_stops_at_first_failure_and_handles_empty() {
		$calls = 0;
		$res   = Sync::push_in_batches(
			function () use ( &$calls ) {
				++$calls;
				return array( 'status' => 500, 'data' => null, 'retry_after' => null );
			},
			array_fill( 0, 900, array( 'x' => 1 ) )
		);
		$this->assertSame( 1, $calls );
		$this->assertSame( 500, $res['status'] );
		$this->assertNull( Sync::push_in_batches( fn() => $this->ok(), array() ) );
	}

	public function test_retry_delay_backoff_429_and_give_up() {
		$err429 = array( 'status' => 429, 'data' => null, 'retry_after' => 900 );
		$this->assertSame( 900, Sync::retry_delay( $err429, 0 ), '429 honours Retry-After when longer than the backoff' );
		$this->assertSame( 300, Sync::retry_delay( array( 'status' => 429, 'data' => null, 'retry_after' => 1 ), 1 ) );
		$this->assertSame( 60, Sync::retry_delay( array( 'status' => 503, 'data' => null, 'retry_after' => null ), 0 ) );
		$this->assertSame( 1800, Sync::retry_delay( new \WP_Error( 'x', 'down' ), 2 ) );
		$this->assertNull( Sync::retry_delay( array( 'status' => 400, 'data' => null, 'retry_after' => null ), 0 ) );
		$this->assertNull( Sync::retry_delay( array( 'status' => 402, 'data' => null, 'retry_after' => null ), 0 ) );
		$this->assertNull( Sync::retry_delay( array( 'status' => 503, 'data' => null, 'retry_after' => null ), Sync::MAX_ATTEMPTS - 1 ) );
	}

	public function test_idempotency_in_progress_is_retried_other_409_is_not() {
		$busy = array( 'status' => 409, 'data' => array( 'detail' => 'idempotency_in_progress' ), 'retry_after' => null );
		$pass = array( 'status' => 409, 'data' => array( 'detail' => 'pass_through_mode' ), 'retry_after' => null );
		$this->assertSame( 60, Sync::retry_delay( $busy, 0 ) );
		$this->assertNull( Sync::retry_delay( $pass, 0 ) );
	}

	public function test_rejected_items_are_logged_without_personal_data() {
		$lines = array();
		Functions\when( 'wc_get_logger' )->justReturn( new class( $lines ) { private $l; public function __construct( array &$l ) { $this->l = &$l; } public function warning( $m ) { $this->l[] = $m; } } );
		Sync::log_rejected( array( 'status' => 200, 'data' => array( 'created' => 1, 'rejected' => array( array( 'index' => 3, 'external_id' => 'c-9', 'reason' => 'invalid_email' ) ) ), 'retry_after' => null ) );
		$this->assertSame( array( 'API rejected item c-9 (index 3): invalid_email' ), $lines );
	}

	public function test_settle_reschedules_with_attempt_counter() {
		Functions\expect( 'as_schedule_single_action' )->once()->andReturnUsing(
			function ( $ts, $hook, $args, $group ) {
				$this->assertEqualsWithDelta( time() + 60, $ts, 2 );
				$this->assertSame( 'socialscore_sync_order', $hook );
				$this->assertSame( array( 77, 1 ), $args );
				$this->assertSame( 'socialscore', $group );
			}
		);
		$this->assertFalse( Sync::settle( array( 'status' => 500, 'data' => null, 'retry_after' => null ), 'socialscore_sync_order', array( 77 ), 0 ) );
		$this->assertTrue( Sync::settle( $this->ok(), 'socialscore_sync_order', array( 77 ), 0 ) );
	}

	public function test_settle_does_not_reschedule_client_errors() {
		Functions\expect( 'as_schedule_single_action' )->never();
		Functions\when( 'wc_get_logger' )->justReturn( new class { public function warning() {} } );
		$this->assertFalse( Sync::settle( array( 'status' => 422, 'data' => null, 'retry_after' => null ), 'h', array( 1 ), 0 ) );
	}

	public function test_queue_skips_when_not_connected_paused_or_already_queued() {
		Functions\expect( 'as_enqueue_async_action' )->never();
		Sync::queue_unique( 'socialscore_sync_order', array( 1, 0 ) ); // Not connected.

		$this->connect_store();
		Sync::pause();
		Sync::queue_unique( 'socialscore_sync_order', array( 1, 0 ) );
		Sync::resume();
		Functions\when( 'as_has_scheduled_action' )->justReturn( true );
		Sync::queue_unique( 'socialscore_sync_order', array( 1, 0 ) );
		$this->assertTrue( true );
	}

	public function test_queue_enqueues_async_in_group_when_connected() {
		$this->connect_store();
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )->once()->with( 'socialscore_sync_order', array( 5, 0 ), 'socialscore' );
		( new Sync() )->queue_order( 5 );
		$this->assertTrue( true );
	}

	public function test_profile_update_queues_customers_only() {
		$this->connect_store();
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'get_userdata' )->alias( fn( $id ) => (object) array( 'roles' => 1 === $id ? array( 'administrator' ) : array( 'customer' ) ) );
		Functions\expect( 'as_enqueue_async_action' )->once()->with( 'socialscore_sync_customer', array( 2, false, 0 ), 'socialscore' );
		$sync = new Sync();
		$sync->queue_customer( 1 );
		$sync->queue_customer( 2 );
		$this->assertTrue( true );
	}

	public function test_draft_orders_are_not_synced() {
		$this->connect_store();
		Functions\when( 'wc_get_order' )->justReturn( new \WC_Order( array( 'status' => 'checkout-draft' ) ) );
		( new Sync() )->handle_order( 5 );
		$this->assertSame( array(), $this->http );
	}

	public function test_hooks_registered() {
		\Brain\Monkey\Actions\expectAdded( 'woocommerce_new_order' )->once();
		\Brain\Monkey\Actions\expectAdded( 'woocommerce_update_order' )->once();
		\Brain\Monkey\Actions\expectAdded( 'woocommerce_created_customer' )->once();
		\Brain\Monkey\Actions\expectAdded( 'profile_update' )->once();
		\Brain\Monkey\Actions\expectAdded( 'socialscore_sync_order' )->once();
		\Brain\Monkey\Actions\expectAdded( 'socialscore_sync_customer' )->once();
		\Brain\Monkey\Actions\expectAdded( 'socialscore_import' )->once();
		( new Sync() )->register();
		$this->assertTrue( true );
	}

	public function test_order_sync_pushes_customer_then_order_and_enriches() {
		$this->connect_store();
		$order = new \WC_Order( array( 'id' => 9, 'billing_email' => 'a@b.bg', 'total' => 10, 'created' => time() ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->http_responder = fn() => self::response( 200, array() );
		( new Sync() )->handle_order( 9 );
		$this->assertStringEndsWith( '/stores/42/customers:batch', $this->http[0]['url'] );
		$this->assertStringEndsWith( '/stores/42/orders:batch', $this->http[1]['url'] );
		$this->assertSame( 'sk_test_secret_key', $this->http[1]['args']['headers']['X-API-KEY'] );
		$this->assertSame( '9', json_decode( $this->http[1]['args']['body'], true )['orders'][0]['external_id'] );
	}

	private function order_flow( $orders_body ) {
		$this->connect_store();
		$this->transients['socialscore_trigger_settings'] = array(
			'on_new_account' => false, 'every_account' => false, 'order_new_customer' => false, 'order_any_customer' => true, 'manual_only' => false,
		);
		$order = new \WC_Order( array( 'id' => 9, 'billing_email' => 'bad', 'total' => 10, 'created' => time() ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'wc_get_logger' )->justReturn( new class { public function warning() {} } );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		$this->http_responder = function ( $url ) use ( $orders_body ) {
			if ( false !== strpos( $url, 'customers:batch' ) ) {
				return self::response( 200, array( 'created' => 0, 'rejected' => array( array( 'index' => 0, 'external_id' => 'guest:x', 'reason' => 'invalid_email' ) ) ) );
			}
			return self::response( 200, $orders_body );
		};
	}

	public function test_order_rejected_inside_a_200_batch_answer_is_not_checked_or_retried() {
		$this->order_flow( array( 'created' => 0, 'rejected' => array( array( 'index' => 0, 'external_id' => '9', 'reason' => 'customer_not_found' ) ) ) );
		Functions\expect( 'as_enqueue_async_action' )->never();
		Functions\expect( 'as_schedule_single_action' )->never();
		( new Sync() )->handle_order( 9 );
		$this->assertCount( 2, $this->http );
	}

	public function test_order_accepted_in_the_batch_answer_still_queues_its_check() {
		$this->order_flow( array( 'created' => 1, 'rejected' => array() ) );
		Functions\expect( 'as_enqueue_async_action' )->once()->with( 'socialscore_run_check', array( 'order', '9', 0 ), 'socialscore' );
		( new Sync() )->handle_order( 9 );
		$this->assertTrue( true );
	}

	public function test_has_rejected_only_for_a_non_empty_rejected_list() {
		$this->assertTrue( Sync::has_rejected( array( 'status' => 200, 'data' => array( 'rejected' => array( array( 'reason' => 'x' ) ) ) ) ) );
		$this->assertFalse( Sync::has_rejected( array( 'status' => 200, 'data' => array( 'rejected' => array() ) ) ) );
		$this->assertFalse( Sync::has_rejected( array( 'status' => 200, 'data' => null ) ) );
		$this->assertFalse( Sync::has_rejected( new \WP_Error( 'x', 'down' ) ) );
	}

	public function test_order_sync_429_schedules_retry_without_checking() {
		$this->connect_store();
		$order = new \WC_Order( array( 'id' => 9, 'billing_email' => 'a@b.bg', 'created' => time() ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->http_responder = fn() => self::response( 429, '', array( 'retry-after' => '600' ) );
		Functions\expect( 'as_schedule_single_action' )->once()->andReturnUsing(
			function ( $ts, $hook, $args ) {
				$this->assertEqualsWithDelta( time() + 600, $ts, 2 );
				$this->assertSame( array( 9, 1 ), $args );
			}
		);
		( new Sync() )->handle_order( 9 );
		$this->assertCount( 1, $this->http, 'orders are not pushed after the customer batch was refused' );
	}

	/**
	 * Import paging: full page continues, short customers page moves to orders, short orders page finishes.
	 */
	public function test_import_page_transitions() {
		$this->connect_store();
		$this->http_responder = fn() => self::response( 200, array() );
		$enqueued = array();
		Functions\when( 'as_enqueue_async_action' )->alias( function ( $hook, $args ) use ( &$enqueued ) { $enqueued[] = $args; } );
		Functions\when( 'get_users' )->alias(
			function ( $a ) {
				$GLOBALS['ss_customers'] = array();
				$ids = $a['paged'] < 2 ? range( 1, 500 ) : array( 501, 502 );
				foreach ( $ids as $id ) {
					$GLOBALS['ss_customers'][ $id ] = array( 'id' => $id, 'email' => "u$id@x.bg" );
				}
				return $ids;
			}
		);
		$sync = new Sync();
		$sync->handle_import( 'customers', 1, 0 );
		$sync->handle_import( 'customers', 2, 0 );
		$this->assertSame( array( array( 'customers', 2, 0 ), array( 'orders', 1, 0 ) ), $enqueued );
		$this->assertSame( 502, $this->options['socialscore_sync_state']['customers'] );
		$this->assertSame( 'no', $this->autoload['socialscore_sync_state'] );

		$order_args = null;
		Functions\when( 'wc_get_order_statuses' )->justReturn( array( 'wc-pending' => 'Pending', 'wc-processing' => 'Processing', 'wc-completed' => 'Completed', 'wc-checkout-draft' => 'Draft' ) );
		Functions\when( 'wc_get_orders' )->alias(
			function ( $args ) use ( &$order_args ) {
				$order_args = $args;
				return array( new \WC_Order( array( 'id' => 5, 'billing_email' => 'o@x.bg' ) ) );
			}
		);
		$sync->handle_import( 'orders', 1, 0 );
		$this->assertSame( array( 'wc-pending', 'wc-processing', 'wc-completed' ), $order_args['status'] );
		$this->assertNotContains( 'checkout-draft', $order_args['status'], 'drafts are never imported' );
		$this->assertSame( 'done', $this->options['socialscore_sync_state']['status'] );
		$this->assertSame( 1, $this->options['socialscore_sync_state']['orders'] );
		$this->assertCount( 2, $enqueued, 'finished: nothing more queued' );
	}

	public function test_batch_ids_of_orders_are_kept_in_the_order_meta_without_requeueing() {
		$this->connect_store();
		$orders = array( 9 => new \WC_Order( array( 'id' => 9 ) ), 10 => new \WC_Order( array( 'id' => 10 ) ) );
		$orders[10]->update_meta_data( 'x', 1 );
		Functions\when( 'wc_get_order' )->alias( fn( $id ) => $orders[ $id ] ?? false );
		Functions\expect( 'as_enqueue_async_action' )->never();
		$answer = array(
			'status'      => 200,
			'retry_after' => null,
			'data'        => array(
				'created' => 2,
				'ids'     => array(
					array( 'external_id' => '9', 'id' => 501 ),
					array( 'external_id' => '10', 'id' => 502 ),
					array( 'external_id' => '404', 'id' => 503 ), // Not a shop order any more.
					array( 'external_id' => 'guest-x', 'id' => 504 ), // Not an order id.
				),
			),
		);
		Sync::record_order_ids( $answer );
		$this->assertSame( 501, $orders[9]->get_meta( '_socialscore_order_id' ) );
		$this->assertSame( 502, $orders[10]->get_meta( '_socialscore_order_id' ) );
		$this->assertSame( 1, $orders[9]->saves );

		Sync::record_order_ids( $answer );
		$this->assertSame( 1, $orders[9]->saves, 'an unchanged id is not saved again' );
		Sync::record_order_ids( new \WP_Error( 'x', 'down' ) );
		Sync::record_order_ids( array( 'status' => 500, 'data' => $answer['data'], 'retry_after' => null ) );
		$this->assertSame( 1, $orders[9]->saves );
	}

	public function test_single_order_sync_stores_the_id_from_the_batch_answer() {
		$this->connect_store();
		$order = new \WC_Order( array( 'id' => 9, 'billing_email' => 'a@b.bg', 'created' => time() ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->http_responder = function ( $url ) {
			return self::response( 200, false !== strpos( $url, 'orders:batch' ) ? array( 'created' => 1, 'ids' => array( array( 'external_id' => '9', 'id' => 777 ) ) ) : array() );
		};
		( new Sync() )->handle_order( 9 );
		$this->assertSame( 777, $order->get_meta( '_socialscore_order_id' ) );
	}

	public function test_import_batches_report_ids_to_the_callback_and_stop_at_a_failure() {
		$seen = array();
		Sync::push_in_batches(
			fn( $chunk ) => array( 'status' => 200, 'data' => array( 'ids' => array( array( 'external_id' => (string) count( $chunk ), 'id' => 1 ) ) ), 'retry_after' => null ),
			array_fill( 0, 501, array( 'x' => 1 ) ),
			function ( $answer ) use ( &$seen ) {
				$seen[] = $answer['data']['ids'][0]['external_id'];
			}
		);
		$this->assertSame( array( '500', '1' ), $seen );

		$seen = array();
		Sync::push_in_batches( fn() => array( 'status' => 500, 'data' => null, 'retry_after' => null ), array( array( 'x' => 1 ) ), function () use ( &$seen ) { $seen[] = 1; } );
		$this->assertSame( array(), $seen );
	}

	public function test_order_is_found_again_by_the_socialscore_id() {
		$found = new \WC_Order( array( 'id' => 9 ) );
		$query = null;
		Functions\when( 'wc_get_orders' )->alias(
			function ( $args ) use ( &$query, $found ) {
				$query = $args;
				return array( $found );
			}
		);
		$this->assertSame( $found, Sync::order_for_socialscore_id( 501 ) );
		$this->assertSame( '_socialscore_order_id', $query['meta_key'] );
		$this->assertSame( '501', $query['meta_value'] );
		Functions\when( 'wc_get_orders' )->justReturn( array() );
		$this->assertNull( Sync::order_for_socialscore_id( 1 ) );
	}

	public function test_unschedule_all_uses_group() {
		Functions\expect( 'as_unschedule_all_actions' )->once()->with( '', array(), 'socialscore' );
		Sync::unschedule_all();
		$this->assertTrue( true );
	}
}
