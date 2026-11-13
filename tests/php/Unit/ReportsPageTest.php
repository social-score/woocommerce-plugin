<?php
namespace SocialScore\Tests\Unit;

use Brain\Monkey\Functions;
use SocialScore\Bot_Stats;
use SocialScore\Not_Human;
use SocialScore\Pixel;
use SocialScore\Reports_Page;
use SocialScore\Tests\TestCase;

class ReportsPageTest extends TestCase {

	const SITE = 'ps_abcdefghijklmnop';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( fn( $n ) => number_format( (float) $n, 0, '.', ',' ) );
		Functions\when( 'wc_get_orders' )->justReturn( array() );
		$this->connect_store();
	}

	private function report( array $over = array() ) {
		return $over + array(
			'group_by'   => 'class',
			'range'      => array( 'from' => '2026-10-14', 'to' => '2026-11-12' ),
			'totals'     => array( 'sessions' => 1200, 'humans' => 900, 'pages' => 5000, 'add_to_cart' => 80, 'begin_checkout' => 40, 'orders' => 12, 'revenue' => array( 'EUR' => 1234.5, 'bad code' => 5 ) ),
			'series'     => array(
				array( 'key' => 'human', 'sessions' => 800, 'orders' => 11 ),
				array( 'key' => 'bad_bot', 'sessions' => 150, 'orders' => 1 ),
				array( 'key' => '<script>alert(1)</script>', 'sessions' => 1, 'orders' => 0 ),
			),
			'comparison' => array( 'totals' => array( 'sessions' => 1000, 'humans' => 0, 'pages' => 4000, 'add_to_cart' => 100, 'begin_checkout' => 40, 'orders' => 10, 'revenue' => array() ) ),
		);
	}

	private function render() {
		ob_start();
		( new Reports_Page() )->render();
		return ob_get_clean();
	}

	public function test_it_asks_the_report_api_with_the_period_the_class_grouping_and_the_comparison() {
		$this->http_responder = fn() => self::response( 200, $this->report() );
		$report               = Reports_Page::fetch( 'last_7_days', 'class' );
		$this->assertSame( 1200, $report['totals']['sessions'] );
		$this->assertStringContainsString( '/api/v2/stores/42/pixel/report?period=last_7_days&group_by=class&compare=previous_period', $this->http[0]['url'] );
		$this->assertSame( 'sk_test_secret_key', $this->http[0]['args']['headers']['X-API-KEY'] );
		Reports_Page::fetch( 'last_7_days', 'class' );
		$this->assertCount( 1, $this->http, 'the answer is cached for five minutes' );
		Reports_Page::fetch( 'today', 'class' );
		$this->assertCount( 2, $this->http );
	}

	public function test_an_unknown_period_is_the_default() {
		$this->assertSame( 'last_30_days', Reports_Page::period( 'forever' ) );
		$this->assertSame( 'last_30_days', Reports_Page::period( array( 'x' ) ) );
		$this->assertSame( 'yesterday', Reports_Page::period( 'yesterday' ) );
	}

	public function test_errors_of_the_api_become_plain_texts() {
		foreach ( array( 'pixel_not_enabled' => array( 404, 'not available' ), 'permission_required' => array( 403, 'may not read results' ), 'missing_scope' => array( 403, 'Connect the store again' ) ) as $code => list( $status, $text ) ) {
			$this->transients     = array();
			$this->http_responder = fn() => self::response( $status, array( 'detail' => $code ) );
			$error                = Reports_Page::fetch( 'last_30_days', 'class' );
			$this->assertInstanceOf( \WP_Error::class, $error );
			$this->assertSame( $code, $error->code );
			$this->assertStringContainsString( $text, Reports_Page::error_text( $error ) );
		}
		$this->transients     = array();
		$this->http_responder = fn() => self::response( 500, '' );
		$this->assertSame( 'http_500', Reports_Page::fetch( 'last_30_days', 'class' )->code );
	}

	public function test_totals_show_the_change_and_a_dash_when_there_is_nothing_to_compare_with() {
		$html = Reports_Page::totals_html( $this->report() );
		$this->assertStringContainsString( '1,200', $html );
		$this->assertStringContainsString( '+20%', $html, 'sessions 1200 against 1000' );
		$this->assertStringContainsString( '-20%', $html, 'add to cart 80 against 100' );
		$this->assertStringContainsString( '+20%', $html );
		$this->assertStringContainsString( 'EUR 1 234.50', $html );
		$this->assertStringNotContainsString( 'bad code', $html, 'only ISO currency codes are shown' );
		$this->assertSame( '–', Reports_Page::delta( 5, 0 ) );
	}

	public function test_a_series_is_escaped_and_uses_the_class_names() {
		$html = Reports_Page::series_html( $this->report(), 'Kind', Reports_Page::class_labels() );
		$this->assertStringContainsString( 'People (active buyers)', $html );
		$this->assertStringContainsString( 'Bad bots', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'No visits were counted', Reports_Page::series_html( array( 'series' => array() ), 'Kind' ) );
	}

	public function test_the_bots_this_server_caught_are_listed_without_ids() {
		$this->options[ Bot_Stats::OPTION ] = array( gmdate( 'Y-m-d' ) => array( 'bad_bot' => 4, 'info_bot' => 9 ) );
		$html = Reports_Page::server_html();
		$this->assertStringContainsString( 'Bad bots', $html );
		$this->assertStringContainsString( '>4<', $html );
		$this->assertStringContainsString( '>9<', $html );
		$this->assertStringContainsString( 'no cookie and no JavaScript', $html );
	}

	public function test_flagged_orders_are_listed_as_warnings_with_their_reasons() {
		$order = new class( array( 'id' => 31 ) ) extends \WC_Order {
			public function get_edit_order_url() {
				return 'https://shop.test/wp-admin/post.php?post=31&action=edit';
			}
			public function get_order_number() {
				return '31';
			}
		};
		$order->update_meta_data( Not_Human::META_CLASS, 'bad_bot' );
		$order->update_meta_data( Not_Human::META_SIGNALS, array( 'http_client_ua' ) );
		Functions\when( 'wc_get_orders' )->alias(
			function ( $args ) use ( $order ) {
				$this->assertSame( Not_Human::META_CLASS, $args['meta_key'] );
				$this->assertSame( 'EXISTS', $args['meta_compare'] );
				return array( $order );
			}
		);
		$html = Reports_Page::warnings_html();
		$this->assertStringContainsString( '#31', $html );
		$this->assertStringContainsString( 'Bad bot', $html );
		$this->assertStringContainsString( 'Script or HTTP library', $html );

		Functions\when( 'wc_get_orders' )->justReturn( array() );
		$this->assertStringContainsString( 'No order is flagged', Reports_Page::warnings_html() );
	}

	public function test_the_page_needs_the_capability_and_a_connection() {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'denied' );
			}
		);
		$level = ob_get_level();
		try {
			$this->render();
			$this->fail( 'the page must refuse a user without the capability' );
		} catch ( \RuntimeException $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			$this->assertSame( 'denied', $e->getMessage() );
		}
	}

	public function test_not_connected_the_page_points_to_the_connect_screen_and_asks_nothing() {
		unset( $this->options['socialscore_connection'] );
		$html = $this->render();
		$this->assertStringContainsString( 'Connect SocialScore to see the reports', $html );
		$this->assertSame( array(), $this->http );
	}

	public function test_the_page_shows_traffic_the_mode_form_and_the_warnings_when_the_pixel_is_on() {
		$this->http_responder = fn( $url ) => false !== strpos( $url, '/pixel/report' )
			? self::response( 200, $this->report() )
			: self::response( 200, array( 'enabled' => true, 'site_id' => self::SITE, 'snippet_url' => 'x', 'bot_mode' => 'block' ) );
		$html = $this->render();
		$this->assertStringContainsString( 'By kind of visitor', $html );
		$this->assertStringContainsString( 'By channel', $html );
		$this->assertStringContainsString( 'name="bot_mode" value="block" checked="checked"', $html );
		$this->assertStringContainsString( 'name="bot_mode" value="flag" />', $html );
		$this->assertStringContainsString( 'Warnings', $html );
	}

	public function test_without_the_pixel_the_mode_form_is_not_offered() {
		$this->http_responder = fn( $url ) => false !== strpos( $url, '/pixel/report' )
			? self::response( 403, array( 'detail' => 'permission_required' ) )
			: self::response( 200, array( 'enabled' => false, 'site_id' => '', 'snippet_url' => 'x' ) );
		$html = $this->render();
		$this->assertStringNotContainsString( 'name="bot_mode"', $html );
		$this->assertStringContainsString( 'may not read results', $html );
	}

	// Saving the mode ------------------------------------------------------------------------------------------------

	private function save( $mode ) {
		$_POST['bot_mode'] = $mode;
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$to = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$to ) {
				$to = $url;
				throw new \RuntimeException( 'exit' );
			}
		);
		try {
			( new Reports_Page() )->save_mode();
		} catch ( \RuntimeException $e ) {
			unset( $_POST['bot_mode'] );
			return $to;
		}
		return null;
	}

	public function test_saving_a_mode_patches_socialscore_where_the_decision_is_kept_and_drops_the_cached_state() {
		$this->http_responder = function ( $url, $args ) {
			return 'PATCH' === $args['method']
				? self::response( 200, array( 'enabled' => true, 'site_id' => self::SITE, 'snippet_url' => 'x', 'bot_mode' => 'block' ) )
				: self::response( 200, array( 'enabled' => true, 'site_id' => self::SITE, 'snippet_url' => 'x', 'bot_mode' => 'flag' ) );
		};
		$to    = $this->save( 'block' );
		$patch = array_values( array_filter( $this->http, fn( $r ) => 'PATCH' === $r['args']['method'] ) );
		$this->assertCount( 1, $patch );
		$this->assertStringEndsWith( '/api/v2/stores/42/pixel', $patch[0]['url'] );
		$this->assertSame( array( 'enabled' => true, 'bot_mode' => 'block' ), json_decode( $patch[0]['args']['body'], true ) );
		$this->assertStringContainsString( 'socialscore_mode=saved', $to );
		$this->assertArrayNotHasKey( Pixel::TRANSIENT . md5( '42' ), $this->transients );
	}

	public function test_a_mode_that_is_not_one_of_the_three_is_refused_before_any_request() {
		$this->http_responder = fn() => self::response( 200, array( 'enabled' => true, 'site_id' => self::SITE, 'snippet_url' => 'x' ) );
		$this->transients[ Pixel::TRANSIENT . md5( '42' ) ] = array( 'enabled' => true, 'site_id' => self::SITE, 'bot_mode' => 'flag', 'level2' => false );
		$to = $this->save( 'ban' );
		$this->assertStringContainsString( 'socialscore_mode=invalid', $to );
		$this->assertSame( array(), $this->http );
	}

	public function test_saving_is_refused_while_the_pixel_is_off_and_a_403_from_socialscore_is_shown() {
		$this->transients[ Pixel::TRANSIENT . md5( '42' ) ] = array( 'enabled' => false, 'site_id' => '', 'bot_mode' => 'flag', 'level2' => false );
		$this->assertStringContainsString( 'socialscore_mode=off', $this->save( 'block' ) );

		$this->transients[ Pixel::TRANSIENT . md5( '42' ) ] = array( 'enabled' => true, 'site_id' => self::SITE, 'bot_mode' => 'flag', 'level2' => false );
		$this->http_responder = fn() => self::response( 403, array( 'detail' => 'permission_required' ) );
		$this->assertStringContainsString( 'socialscore_mode=denied', $this->save( 'block' ) );
	}

	public function test_the_form_carries_a_nonce_and_posts_to_admin_post() {
		$html = Reports_Page::mode_form_html( 'flag' );
		$this->assertStringContainsString( 'admin-post.php', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="nonce123"', $html );
		$this->assertStringContainsString( 'name="action" value="socialscore_bot_mode"', $html );
	}
}
