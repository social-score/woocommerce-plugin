<?php
namespace SocialScore\Tests\Unit;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Brain\Monkey\Functions;
use SocialScore\Admin_Page;
use SocialScore\Check_Panels;
use SocialScore\Tests\TestCase;

class AdminHposTest extends TestCase {

	public function test_order_screen_id_follows_hpos() {
		Functions\when( 'wc_get_page_screen_id' )->alias( fn( $t ) => 'woocommerce_page_wc-orders' );
		OrderUtil::$hpos = true;
		$this->assertSame( 'woocommerce_page_wc-orders', Check_Panels::order_screen_id() );
		OrderUtil::$hpos = false;
		$this->assertSame( 'shop_order', Check_Panels::order_screen_id() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_order_screen_id_under_hpos_without_the_admin_function() {
		OrderUtil::$hpos = true;
		$this->assertSame( 'woocommerce_page_wc-orders', Check_Panels::order_screen_id() );
		OrderUtil::$hpos = false;
	}

	public function test_meta_box_registered_on_the_right_screen() {
		Functions\when( 'wc_get_page_screen_id' )->alias( fn( $t ) => 'woocommerce_page_wc-orders' );
		$screens = array();
		Functions\when( 'add_meta_box' )->alias(
			function ( $id, $title, $cb, $screen ) use ( &$screens ) {
				$screens[] = $screen;
			}
		);
		OrderUtil::$hpos = true;
		( new Check_Panels() )->add_meta_box();
		OrderUtil::$hpos = false;
		( new Check_Panels() )->add_meta_box();
		$this->assertSame( array( 'woocommerce_page_wc-orders', 'shop_order' ), $screens );
	}

	public function test_meta_box_callback_accepts_wp_post_and_wc_order() {
		$this->connect_store();
		$post     = new \WP_Post();
		$post->ID = 55;
		Functions\expect( 'wc_get_order' )->once()->with( 55 )->andReturn( new \WC_Order( array( 'id' => 55 ) ) );
		Functions\when( 'wp_kses_post' )->returnArg();
		$panel = new Check_Panels();
		ob_start();
		$panel->render_meta_box( $post );
		$legacy = ob_get_clean();
		ob_start();
		$panel->render_meta_box( new \WC_Order( array( 'id' => 77 ) ) );
		$hpos = ob_get_clean();
		$this->assertStringContainsString( 'data-external-id="55"', $legacy );
		$this->assertStringContainsString( 'data-kind="order"', $legacy );
		$this->assertStringContainsString( 'data-external-id="77"', $hpos );
	}

	public function test_panel_markup_escapes_and_never_contains_the_key() {
		$this->connect_store( '42', 'sk_test_secret_key' );
		$html = Check_Panels::panel_markup( 'customer', 'guest:"><script>' );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( 'sk_test_secret_key', $html );
		$this->assertStringContainsString( 'data-nonce="nonce123"', $html );
		$this->assertStringContainsString( 'data-store-id="42"', $html );
	}

	public function test_panel_without_connection_points_to_connect() {
		$html = Check_Panels::panel_markup( 'order', '1' );
		$this->assertStringContainsString( 'page=socialscore', $html );
		$this->assertStringNotContainsString( 'socialscore-panel-root', $html );
	}

	public function test_column_inserted_after_status_and_shows_labels() {
		$cols = ( new Check_Panels() )->add_column(
			array(
				'cb'           => '',
				'order_status' => 'Status',
				'order_total'  => 'Total',
			)
		);
		$this->assertSame( array( 'cb', 'order_status', 'socialscore_risk', 'order_total' ), array_keys( $cols ) );

		$order = new \WC_Order();
		$this->assertSame( '–', Check_Panels::column_text( $order ) );
		$order->meta['_socialscore_labels'] = array( 'review_before_shipping', 'possible_business_buyer' );
		$this->assertSame( 'Review before shipping, Possible business buyer', Check_Panels::column_text( $order ) );
	}

	public function test_result_notices_and_import_status() {
		$this->assertSame( '', Admin_Page::result_notice( 'whatever' )[1] );
		$this->assertSame( 'notice-error', Admin_Page::result_notice( 'state' )[0] );
		$this->assertSame( '', Admin_Page::import_status_text() );
		$this->options['socialscore_sync_state'] = array(
			'status'    => 'running',
			'customers' => 500,
			'orders'    => 0,
		);
		$this->assertStringContainsString( '500 customers', Admin_Page::import_status_text() );
		$this->options['socialscore_sync_state']['status'] = 'done';
		$this->assertStringContainsString( 'Import finished', Admin_Page::import_status_text() );
	}
}

class HposDeclarationTest extends TestCase {
	protected $define_constants = false;

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_main_file_declares_hpos_compatibility() {
		$callbacks = array();
		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 3 ) . '/' );
		Functions\when( 'plugin_dir_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'add_action' )->alias(
			function ( $hook, $cb ) use ( &$callbacks ) {
				$callbacks[ $hook ][] = $cb;
			}
		);
		Functions\when( 'register_deactivation_hook' )->justReturn();

		require dirname( __DIR__, 3 ) . '/socialscore.php';

		$this->assertArrayHasKey( 'before_woocommerce_init', $callbacks );
		$callbacks['before_woocommerce_init'][0]();
		$this->assertSame( array( array( 'custom_order_tables', SOCIALSCORE_FILE, true ) ), FeaturesUtil::$declared );
		$this->assertArrayHasKey( 'plugins_loaded', $callbacks );
	}
}
