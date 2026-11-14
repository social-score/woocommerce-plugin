<?php
namespace SocialScore\Tests\Unit;

use Brain\Monkey\Functions;
use SocialScore\Identity;
use SocialScore\Pixel;
use SocialScore\Tests\TestCase;

/**
 * Level 2 identity events (C4). Off by default: Level 1 sends nothing personal.
 */
class IdentityTest extends TestCase {

	const SITE = 'ps_abcdefghijklmnop';

	/** @var array Events queued: hook => list of args. */
	private $queued = array();
	/** @var bool Whether the visitor consents. */
	private $consent = false;
	/** @var bool Level 2 on at SocialScore. */
	private $server_level2 = false;
	/** @var bool Plugin switch. */
	private $plugin_switch = false;
	/** @var bool What wp_has_consent answers. */
	private $consent_api = false;

	protected function setUp(): void {
		parent::setUp();
		$this->queued        = array();
		$this->consent       = false;
		$this->server_level2 = false;
		$this->plugin_switch = false;
		Functions\when( 'as_enqueue_async_action' )->alias(
			function ( $hook, $args ) {
				$this->queued[ $hook ][] = $args;
				return 1;
			}
		);
		// The Consent API answer; a test that needs another one changes this. The filter starts from it.
		$this->consent_api = false;
		Functions\when( 'wp_has_consent' )->alias( fn() => $this->consent_api );
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				if ( 'socialscore_level2_enabled' === $tag ) {
					return $this->plugin_switch;
				}
				if ( 'socialscore_level2_consent' === $tag ) {
					return $this->consent ? true : $value;
				}
				return $value;
			}
		);
		Functions\when( 'get_userdata' )->alias(
			fn( $id ) => (object) array(
				'ID'         => $id,
				'user_email' => ' Ivan.Petrov@Example.COM ',
			)
		);
		$this->connect_store();
		$this->http_responder = fn() => self::response(
			200,
			array(
				'enabled'     => true,
				'site_id'     => self::SITE,
				'snippet_url' => 'x',
				'level2'      => $this->server_level2,
			)
		);
	}

	private function user() {
		return (object) array(
			'ID'         => 3,
			'user_email' => 'Ivan.Petrov@example.com',
		);
	}

	private function order() {
		return new \WC_Order(
			array(
				'id'            => 9,
				'billing_email' => 'ivan.petrov@example.com',
				'billing_phone' => '+359 88 123 4567',
			)
		);
	}

	public function test_the_hash_is_the_sha256_of_the_normalised_value_and_nothing_else() {
		$this->assertSame( hash( 'sha256', 'ivan.petrov@example.com' ), Identity::hash_email( "  Ivan.Petrov@Example.COM \n" ) );
		$this->assertSame( hash( 'sha256', '359881234567' ), Identity::hash_phone( '+359 (88) 123-4567' ) );
		$this->assertSame( '', Identity::hash_email( 'not an email' ) );
		$this->assertSame( '', Identity::hash_phone( '12' ) );
		$this->assertSame( '', Identity::hash_phone( str_repeat( '1', 16 ) ) );
	}

	public function test_level_one_by_default_sends_and_queues_nothing_personal() {
		( new Identity() )->on_login( 'ivan', $this->user() );
		( new Identity() )->on_register( 3 );
		Functions\when( 'wc_get_order' )->justReturn( $this->order() );
		( new Identity() )->on_order( 9 );
		$this->assertSame( array(), $this->queued );
		$this->assertSame( array(), $this->http, 'not even a lookup of the Pixel state' );
	}

	public function test_each_of_the_three_conditions_is_needed() {
		Functions\when( 'wc_get_order' )->justReturn( $this->order() );
		$cases = array(
			array( true, true, false, 'level 2 is off at SocialScore' ),
			array( true, false, true, 'the visitor has not consented' ),
			array( false, true, true, 'the plugin switch is off' ),
		);
		foreach ( $cases as $case ) {
			$this->plugin_switch = $case[0];
			$this->server_level2 = $case[1];
			$this->consent       = $case[2];
			$this->transients    = array();
			( new Identity() )->on_login( 'ivan', $this->user() );
			( new Identity() )->on_order( 9 );
			$this->assertSame( array(), $this->queued, $case[3] );
		}
	}

	public function test_with_level_two_on_and_consent_login_register_and_order_queue_hashes_only() {
		$this->plugin_switch = true;
		$this->server_level2 = true;
		$this->consent       = true;
		Functions\when( 'wc_get_order' )->justReturn( $this->order() );

		( new Identity() )->on_login( 'ivan', $this->user() );
		( new Identity() )->on_register( 3 );
		( new Identity() )->on_order( 9 );

		$events = $this->queued[ Identity::HOOK_SEND ];
		$this->assertCount( 3, $events );
		$email = hash( 'sha256', 'ivan.petrov@example.com' );
		$this->assertSame( array( 'login', $email ), array( $events[0][0]['type'], $events[0][0]['email_sha256'] ) );
		$this->assertSame( 'register', $events[1][0]['type'] );
		$this->assertSame( array( 'order', $email, hash( 'sha256', '359881234567' ), '9' ), array( $events[2][0]['type'], $events[2][0]['email_sha256'], $events[2][0]['phone_sha256'], $events[2][0]['order_external_id'] ) );
		$json = json_encode( $this->queued );
		$this->assertStringNotContainsString( 'example.com', $json );
		$this->assertStringNotContainsString( '4567', $json );
		$this->assertStringNotContainsString( 'ivan', strtolower( $json ) );
	}

	public function test_the_wp_consent_api_answer_is_the_default_and_the_filter_may_replace_it() {
		$this->plugin_switch = true;
		$this->server_level2 = true;

		$this->consent_api = false;
		( new Identity() )->on_login( 'ivan', $this->user() );
		$this->assertSame( array(), $this->queued, 'the Consent API says no' );

		$this->consent_api = true;
		( new Identity() )->on_login( 'ivan', $this->user() );
		$this->assertCount( 1, $this->queued[ Identity::HOOK_SEND ], 'the Consent API says yes' );

		$this->queued      = array();
		$this->consent_api = false;
		$this->consent     = true; // A banner without Consent API support says yes through the filter.
		( new Identity() )->on_login( 'ivan', $this->user() );
		$this->assertCount( 1, $this->queued[ Identity::HOOK_SEND ] );
	}

	public function test_nothing_is_queued_when_the_store_is_not_connected() {
		$this->plugin_switch = true;
		$this->server_level2 = true;
		$this->consent       = true;
		unset( $this->options['socialscore_connection'] );
		( new Identity() )->on_login( 'ivan', $this->user() );
		$this->assertSame( array(), $this->queued );
	}

	public function test_a_queued_event_goes_to_the_identity_route_with_the_hashes_in_the_body() {
		$this->plugin_switch = true;
		$this->server_level2 = true;
		$event               = array(
			'type'         => 'login',
			'at'           => '2026-11-13T10:00:00+00:00',
			'email_sha256' => str_repeat( 'a', 64 ),
		);
		( new Identity() )->send( $event );
		$this->assertSame( 'POST', $this->http[ count( $this->http ) - 1 ]['args']['method'] );
		$this->assertStringEndsWith( '/api/v2/stores/42/pixel/identity', $this->http[ count( $this->http ) - 1 ]['url'] );
		$this->assertSame( array( 'events' => array( $event ) ), json_decode( $this->http[ count( $this->http ) - 1 ]['args']['body'], true ) );
	}

	public function test_a_queued_event_is_dropped_when_level_two_was_switched_off_while_it_waited() {
		$event = array(
			'type'         => 'login',
			'at'           => '2026-11-13T10:00:00+00:00',
			'email_sha256' => str_repeat( 'a', 64 ),
		);
		foreach ( array( array( false, true ), array( true, false ) ) as $case ) {
			$this->plugin_switch = $case[0];
			$this->server_level2 = $case[1];
			$this->transients    = array();
			( new Identity() )->send( $event );
		}
		unset( $this->options['socialscore_connection'] );
		$this->plugin_switch = true;
		$this->server_level2 = true;
		( new Identity() )->send( $event );
		$this->assertSame( array(), array_filter( $this->http, fn( $r ) => 'POST' === $r['args']['method'] ), 'nothing was posted' );
	}

	public function test_an_event_of_another_type_is_not_sent() {
		( new Identity() )->send( array( 'type' => 'page_view' ) );
		( new Identity() )->send( 'junk' );
		$this->assertSame( array(), $this->http );
	}

	public function test_the_pixel_state_carries_level2_false_unless_socialscore_says_true() {
		$this->assertFalse( Pixel::state()['level2'] );
		$this->transients    = array();
		$this->server_level2 = true;
		$this->assertTrue( Pixel::state()['level2'] );
	}

	public function test_level_one_pixel_events_still_carry_no_user_id() {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-pixel.php' );
		$this->assertStringNotContainsString( 'email', $source );
		$this->assertStringNotContainsString( 'sha256', $source );
	}
}
