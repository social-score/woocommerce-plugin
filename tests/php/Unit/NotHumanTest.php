<?php
namespace SocialScore\Tests\Unit;

use Brain\Monkey\Functions;
use SocialScore\Bot_Guard;
use SocialScore\Check_Panels;
use SocialScore\Not_Human;
use SocialScore\Tests\TestCase;

class NotHumanTest extends TestCase {

	/** @var array */
	private $orders = array();
	/** @var array Single actions scheduled: array( time, hook, args ). */
	private $scheduled = array();
	/** @var array */
	private $server_before = array();

	protected function setUp(): void {
		parent::setUp();
		$this->server_before = $_SERVER;
		Bot_Guard::reset();
		$this->orders    = array();
		$this->scheduled = array();
		Functions\when( 'wc_get_order' )->alias( fn( $id ) => $this->orders[ (int) $id ] ?? false );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $time, $hook, $args ) {
				$this->scheduled[] = array( $time, $hook, $args );
				return 1;
			}
		);
		$this->connect_store();
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_before;
		Bot_Guard::reset();
		parent::tearDown();
	}

	private function order( $id = 5 ) {
		$order               = new \WC_Order( array( 'id' => $id ) );
		$this->orders[ $id ] = $order;
		return $order;
	}

	private function api_says( $status, $body ) {
		$this->http_responder = fn() => self::response( $status, $body );
	}

	// The class of the visit from SocialScore -------------------------------------------------------------------------

	public function test_an_order_synced_to_socialscore_asks_for_its_visit_after_two_minutes() {
		$before = time();
		Not_Human::after_order_sync( $this->order( 5 ) );
		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( Not_Human::HOOK_SESSION, $this->scheduled[0][1] );
		$this->assertSame( array( 5, 0 ), $this->scheduled[0][2] );
		$this->assertGreaterThanOrEqual( $before + 120, $this->scheduled[0][0] );
	}

	public function test_an_order_whose_visit_was_read_is_not_asked_again() {
		$order = $this->order();
		$order->update_meta_data( Not_Human::META_VISIT, 'human' );
		Not_Human::after_order_sync( $order );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_a_bad_bot_visit_flags_the_order_names_the_signals_and_adds_one_note() {
		$order = $this->order( 5 );
		$this->api_says(
			200,
			array(
				'class'        => 'bad_bot',
				'signals'      => array( 'webdriver', 'page_burst', 'Bad Code', 7 ),
				'device_farm'  => true,
				'last_seen_at' => '2026-11-13T10:00:00Z',
				'pages'        => 9,
			)
		);

		( new Not_Human() )->handle_session( 5, 0 );

		$this->assertStringContainsString( '/api/v2/stores/42/orders/5/session', $this->http[0]['url'] );
		$this->assertSame( 'GET', $this->http[0]['args']['method'] );
		$flag = Not_Human::for_order( $order );
		$this->assertSame( array( 'bad_bot', array( 'webdriver', 'page_burst', 'device_farm' ), 'pixel' ), array( $flag['class'], $flag['signals'], $flag['source'] ) );
		$this->assertSame( 'bad_bot', $order->get_meta( Not_Human::META_VISIT ) );
		$this->assertSame( array( 'SocialScore: not from a human (Bad bot). Check this order.' ), $order->notes );

		( new Not_Human() )->handle_session( 5, 0 );
		$this->assertCount( 1, $order->notes, 'asked twice, noted once' );
	}

	public function test_saving_the_flag_does_not_wake_the_order_sync() {
		$order = $this->order( 5 );
		$this->api_says(
			200,
			array(
				'class'   => 'ai_agent',
				'signals' => array( 'declared_agent' ),
			)
		);
		Functions\expect( 'as_enqueue_async_action' )->never();
		( new Not_Human() )->handle_session( 5, 0 );
		$this->assertSame( 1, $order->saves );
		$this->assertSame( 'ai_agent', Not_Human::for_order( $order )['class'] );
	}

	public function test_a_person_gets_no_flag_and_is_not_asked_again() {
		$order = $this->order( 5 );
		$this->api_says(
			200,
			array(
				'class'   => 'human',
				'signals' => array(),
			)
		);
		( new Not_Human() )->handle_session( 5, 0 );
		$this->assertNull( Not_Human::for_order( $order ) );
		$this->assertSame( 'human', $order->get_meta( Not_Human::META_VISIT ) );
		$this->assertSame( array(), $order->notes );
	}

	public function test_a_later_look_that_finds_a_person_removes_the_flag_the_pixel_set() {
		$order = $this->order( 5 );
		$this->api_says(
			200,
			array(
				'class'   => 'bad_bot',
				'signals' => array( 'webdriver' ),
			)
		);
		( new Not_Human() )->handle_session( 5, 0 );
		$this->api_says(
			200,
			array(
				'class'   => 'lurker',
				'signals' => array(),
			)
		);
		( new Not_Human() )->handle_session( 5, 1 );
		$this->assertNull( Not_Human::for_order( $order ) );
	}

	public function test_the_flag_the_module_wrote_itself_survives_a_visit_the_pixel_saw_as_human() {
		$order = $this->order( 5 );
		$order->update_meta_data( Not_Human::META_CLASS, 'bad_bot' );
		$order->update_meta_data( Not_Human::META_SOURCE, 'server' );
		$this->api_says(
			200,
			array(
				'class'   => 'human',
				'signals' => array(),
			)
		);
		( new Not_Human() )->handle_session( 5, 0 );
		$this->assertSame( 'bad_bot', Not_Human::for_order( $order )['class'] );
	}

	public function test_a_visit_not_there_yet_is_asked_for_again_later_and_given_up_after_the_last_delay() {
		$this->order( 5 );
		$this->api_says( 404, array( 'detail' => 'session_not_found' ) );
		( new Not_Human() )->handle_session( 5, 0 );
		$this->assertSame( array( 5, 1 ), $this->scheduled[0][2] );
		( new Not_Human() )->handle_session( 5, 2 );
		$this->assertSame( array( 5, 3 ), $this->scheduled[1][2] );
		( new Not_Human() )->handle_session( 5, 3 );
		$this->assertCount( 2, $this->scheduled, 'attempt 3 is the last' );
	}

	public function test_any_other_404_ends_it_and_a_server_error_retries_with_the_sync_backoff() {
		$this->order( 5 );
		$this->api_says( 404, array( 'detail' => 'pixel_not_enabled' ) );
		( new Not_Human() )->handle_session( 5, 0 );
		$this->assertSame( array(), $this->scheduled );

		$this->api_says( 503, '' );
		( new Not_Human() )->handle_session( 5, 0 );
		$this->assertSame( array( 5, 1 ), $this->scheduled[0][2] );
	}

	public function test_an_answer_with_a_class_this_version_does_not_know_changes_nothing() {
		$order = $this->order( 5 );
		$this->api_says(
			200,
			array(
				'class'   => 'quantum_bot',
				'signals' => array(),
			)
		);
		( new Not_Human() )->handle_session( 5, 0 );
		$this->assertSame( '', $order->get_meta( Not_Human::META_VISIT ) );
		$this->assertNull( Not_Human::for_order( $order ) );
	}

	public function test_nothing_is_asked_when_the_store_is_not_connected() {
		unset( $this->options['socialscore_connection'] );
		$this->order( 5 );
		( new Not_Human() )->handle_session( 5, 0 );
		$this->assertSame( array(), $this->http );
	}

	// The flag from the request ---------------------------------------------------------------------------------------

	public function test_a_person_checkout_writes_nothing_on_the_order() {
		$_SERVER['HTTP_USER_AGENT']      = 'Mozilla/5.0 Chrome/126.0';
		$_SERVER['HTTP_ACCEPT']          = 'text/html';
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';
		Bot_Guard::reset();
		$order = $this->order( 5 );
		( new Not_Human() )->flag_classic( $order, array() );
		( new Not_Human() )->flag_block( $order, new \WP_REST_Request() );
		$this->assertSame( array(), $order->meta );
	}

	public function test_the_block_checkout_flags_a_bot_the_same_way() {
		$_SERVER['HTTP_USER_AGENT'] = 'Go-http-client/1.1';
		Bot_Guard::reset();
		$order = $this->order( 5 );
		( new Not_Human() )->flag_block( $order, new \WP_REST_Request() );
		$this->assertSame( 'bad_bot', Not_Human::for_order( $order )['class'] );
	}

	public function test_a_declared_agent_order_is_flagged_as_an_agent_not_a_bad_bot() {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; ChatGPT-User/1.0)';
		Bot_Guard::reset();
		$order = $this->order( 5 );
		( new Not_Human() )->flag_classic( $order, array() );
		$this->assertSame( 'ai_agent', Not_Human::for_order( $order )['class'] );
		$this->assertStringContainsString( 'AI agent', Check_Panels::column_text( $order ) );
	}

	public function test_no_flag_is_written_for_a_store_that_is_not_connected() {
		unset( $this->options['socialscore_connection'] );
		$_SERVER['HTTP_USER_AGENT'] = 'curl/8';
		Bot_Guard::reset();
		$order = $this->order( 5 );
		( new Not_Human() )->flag_classic( $order, array() );
		$this->assertSame( array(), $order->meta );
	}

	// What the merchant sees ------------------------------------------------------------------------------------------

	public function test_the_orders_list_cell_puts_the_flag_before_the_labels() {
		$order = $this->order( 5 );
		$this->assertSame( '–', Check_Panels::column_text( $order ) );
		$order->update_meta_data( '_socialscore_labels', array( 'review_before_shipping' ) );
		$this->assertSame( 'Review before shipping', Check_Panels::column_text( $order ) );
		$order->update_meta_data( Not_Human::META_CLASS, 'bad_bot' );
		$this->assertSame( 'Not human: Bad bot, Review before shipping', Check_Panels::column_text( $order ) );
	}

	public function test_the_order_screen_notice_is_escaped_and_lists_the_reasons() {
		$order = $this->order( 5 );
		$this->assertSame( '', Not_Human::notice_html( $order ) );
		$order->update_meta_data( Not_Human::META_CLASS, 'bad_bot' );
		$order->update_meta_data( Not_Human::META_SIGNALS, array( 'headless_ua', 'x_unknown_code', '<script>' ) );
		$html = Not_Human::notice_html( $order );
		$this->assertStringContainsString( 'Bad bot', $html );
		$this->assertStringContainsString( 'Headless browser', $html );
		$this->assertStringContainsString( 'x unknown code', $html, 'a code the version does not know shows as words' );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_a_flag_value_that_is_not_one_of_the_classes_is_not_a_flag() {
		$order = $this->order( 5 );
		$order->update_meta_data( Not_Human::META_CLASS, 'human' );
		$this->assertNull( Not_Human::for_order( $order ) );
		$order->update_meta_data( Not_Human::META_CLASS, '<b>bad_bot</b>' );
		$this->assertNull( Not_Human::for_order( $order ) );
	}
}
