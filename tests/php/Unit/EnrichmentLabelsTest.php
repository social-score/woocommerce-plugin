<?php
namespace SocialScore\Tests\Unit;

use Brain\Monkey\Functions;
use SocialScore\Enrichment;
use SocialScore\Labels;
use SocialScore\Tests\TestCase;

class EnrichmentLabelsTest extends TestCase {

	private function settings( array $over = array() ) {
		$this->transients['socialscore_trigger_settings'] = $over + array(
			'on_new_account'     => false,
			'every_account'      => false,
			'order_new_customer' => false,
			'order_any_customer' => false,
			'manual_only'        => false,
		);
	}

	public function test_labels_normalize_text_and_notes_only_for_new_labels() {
		$this->assertSame( array( 'review_before_shipping' ), Labels::normalize( array( 'review_before_shipping', 'review_before_shipping', 'x' ) ) );
		$this->assertSame( array(), Labels::normalize( 'nope' ) );
		$this->assertSame( 'Possible business buyer', Labels::text( 'possible_business_buyer' ) );

		$order = new \WC_Order();
		Labels::apply_to_order( $order, array( 'review_before_shipping' ) );
		Labels::apply_to_order( $order, array( 'review_before_shipping', 'possible_business_buyer' ) );
		$this->assertSame( array( 'SocialScore: Review before shipping', 'SocialScore: Possible business buyer' ), $order->notes );
		$this->assertSame( array( 'review_before_shipping', 'possible_business_buyer' ), Labels::for_order( $order ) );
		$this->assertSame( 2, $order->saves );
	}

	public function test_saving_labels_does_not_requeue_order_sync() {
		$this->connect_store();
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )->never();
		$order = new class() extends \WC_Order {
			public function save() {
				( new \SocialScore\Sync() )->queue_order( 1 );
			}
		};
		Labels::apply_to_order( $order, array( 'review_before_shipping' ) );
		$this->assertTrue( true );
	}

	public function test_no_checks_when_settings_unknown_or_manual_only() {
		$this->connect_store();
		Functions\expect( 'as_enqueue_async_action' )->never();
		$this->http_responder = fn() => self::response( 500, '' );
		Enrichment::after_order_sync( new \WC_Order( array( 'created' => time() ) ) );
		$this->settings(
			array(
				'manual_only'        => true,
				'order_any_customer' => true,
			)
		);
		Enrichment::after_order_sync( new \WC_Order( array( 'created' => time() ) ) );
		$this->assertTrue( true );
	}

	public function test_order_any_customer_queues_a_check() {
		$this->connect_store();
		$this->settings( array( 'order_any_customer' => true ) );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )->once()->with( 'socialscore_run_check', array( 'order', '9', 0 ), 'socialscore' );
		Enrichment::after_order_sync(
			new \WC_Order(
				array(
					'id'      => 9,
					'created' => time(),
				)
			)
		);
		$this->assertTrue( true );
	}

	public function test_order_new_customer_only_for_first_order() {
		$this->connect_store();
		$this->settings( array( 'order_new_customer' => true ) );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'wc_get_orders' )->twice()->andReturn( array( 1, 2 ), array( 1 ) );
		Functions\expect( 'as_enqueue_async_action' )->once();
		Enrichment::after_order_sync(
			new \WC_Order(
				array(
					'id'            => 9,
					'created'       => time(),
					'billing_email' => 'r@x.bg',
				)
			)
		);
		Enrichment::after_order_sync(
			new \WC_Order(
				array(
					'id'            => 10,
					'created'       => time(),
					'billing_email' => 'n@x.bg',
				)
			)
		);
		$this->assertTrue( true );
	}

	public function test_old_or_already_checked_orders_are_skipped() {
		$this->connect_store();
		$this->settings( array( 'order_any_customer' => true ) );
		Functions\expect( 'as_enqueue_async_action' )->never();
		Enrichment::after_order_sync( new \WC_Order( array( 'created' => time() - 10 * 86400 ) ) );
		$checked                               = new \WC_Order( array( 'created' => time() ) );
		$checked->meta['_socialscore_checked'] = 'done';
		Enrichment::after_order_sync( $checked );
		$this->assertTrue( true );
	}

	public function test_account_triggers() {
		$this->connect_store();
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		$queued = array();
		Functions\when( 'as_enqueue_async_action' )->alias(
			function ( $h, $a ) use ( &$queued ) {
				$queued[] = $a;
			}
		);

		$this->settings( array( 'on_new_account' => true ) );
		Enrichment::after_customer_sync( 5, true );
		Enrichment::after_customer_sync( 6, false );
		$this->assertSame( array( array( 'customer', '5', 0 ) ), $queued );

		$queued = array();
		$this->settings( array( 'every_account' => true ) );
		Enrichment::after_customer_sync( 6, false );
		$this->user_meta[7]['_socialscore_checked'] = 'done';
		Enrichment::after_customer_sync( 7, false );
		$this->assertSame( array( array( 'customer', '6', 0 ) ), $queued );
	}

	public function test_check_uses_stable_idempotency_key_per_subject() {
		$this->connect_store( '42' );
		$this->assertSame( 'wc:42:order:9', Enrichment::idempotency_key( 'order', '9' ) );
		$order = new \WC_Order( array( 'id' => 9 ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->http_responder = fn() => self::response( 200, array( 'labels' => array( 'possible_business_buyer' ) ) );
		( new Enrichment() )->handle_check( 'order', '9', 0 );
		( new Enrichment() )->handle_check( 'order', '9', 1 );
		$this->assertSame( 'wc:42:order:9', $this->http[0]['args']['headers']['Idempotency-Key'] );
		$this->assertSame( $this->http[0]['args']['headers']['Idempotency-Key'], $this->http[1]['args']['headers']['Idempotency-Key'] );
		$this->assertSame( array( 'order_external_id' => '9' ), json_decode( $this->http[0]['args']['body'], true ) );
		$this->assertSame( array( 'possible_business_buyer' ), $order->meta['_socialscore_labels'] );
	}

	public function test_plan_required_is_remembered_and_not_retried() {
		$this->connect_store();
		$order = new \WC_Order( array( 'id' => 9 ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'wc_get_logger' )->justReturn( new class() { public function warning() {} } );
		Functions\expect( 'as_schedule_single_action' )->never();
		$this->http_responder = fn() => self::response( 402, array( 'detail' => 'plan_required' ) );
		( new Enrichment() )->handle_check( 'order', '9', 0 );
		$this->assertSame( 'plan_required', $order->meta['_socialscore_checked'] );
		$this->assertArrayNotHasKey( '_socialscore_labels', $order->meta );
	}

	public function test_quota_exhausted_is_not_remembered() {
		$this->connect_store();
		$order = new \WC_Order( array( 'id' => 9 ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'wc_get_logger' )->justReturn( new class() { public function warning() {} } );
		Functions\expect( 'as_schedule_single_action' )->never();
		$this->http_responder = fn() => self::response( 402, array( 'detail' => 'quota_exhausted' ) );
		( new Enrichment() )->handle_check( 'order', '9', 0 );
		$this->assertArrayNotHasKey( '_socialscore_checked', $order->meta );
	}

	public function test_trigger_settings_are_cached_from_the_server() {
		$this->connect_store();
		$this->http_responder = fn() => self::response(
			200,
			array(
				'settings' => array(
					'order_any_customer' => true,
					'manual_only'        => false,
				),
			)
		);
		$s                    = Enrichment::trigger_settings();
		$this->assertTrue( $s['order_any_customer'] );
		Enrichment::trigger_settings();
		$this->assertCount( 1, $this->http, 'second read comes from the transient' );
	}
}
