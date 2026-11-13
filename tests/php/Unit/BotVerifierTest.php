<?php
namespace SocialScore\Tests\Unit;

use Brain\Monkey\Functions;
use SocialScore\Bot_Guard;
use SocialScore\Bot_Verifier;
use SocialScore\Tests\TestCase;

class BotVerifierTest extends TestCase {

	const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
	const BINGBOT   = 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)';
	const GOOGLE_IP = '66.249.66.1';
	const BING_IP   = '157.55.39.12';
	const OTHER_IP  = '203.0.113.50';

	/** @var array */
	private $server_before = array();

	protected function setUp(): void {
		parent::setUp();
		$this->server_before = $_SERVER;
		Bot_Guard::reset();
		$this->options[ Bot_Verifier::OPTION ] = array(
			'google'  => array( '66.249.64.0/19', '2001:4860:4801:10::/64' ),
			'bing'    => array( '157.55.39.0/24', '40.77.167.0/24' ),
			'fetched' => time(),
		);
		Functions\when( 'wp_remote_get' )->alias( fn( $url, $args = array() ) => wp_remote_request( $url, array_merge( $args, array( 'method' => 'GET' ) ) ) );
		$this->connect_store();
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_before;
		Bot_Guard::reset();
		parent::tearDown();
	}

	private function as_bot( $ua, $ip ) {
		$_SERVER['HTTP_USER_AGENT'] = $ua;
		$_SERVER['REMOTE_ADDR']     = $ip;
		Bot_Guard::reset();
		return Bot_Guard::current();
	}

	public function test_an_address_in_a_published_range_is_verified_and_stays_a_known_bot() {
		$verdict = $this->as_bot( self::GOOGLEBOT, self::GOOGLE_IP );
		$this->assertSame( 'info_bot', $verdict['class'] );
		$this->assertSame( array( 'declared_bot' ), $verdict['signals'] );
		$this->assertSame( 'info_bot', $this->as_bot( self::GOOGLEBOT, '2001:4860:4801:10::5' )['class'], 'IPv6' );
		$this->assertSame( 'info_bot', $this->as_bot( self::BINGBOT, self::BING_IP )['signals'] ? 'info_bot' : '' );
	}

	public function test_a_public_address_in_no_range_is_a_fake_and_a_bad_bot() {
		$verdict = $this->as_bot( self::GOOGLEBOT, self::OTHER_IP );
		$this->assertSame( 'bad_bot', $verdict['class'] );
		$this->assertContains( 'fake_bot', $verdict['signals'] );
		$this->assertSame( 'bad_bot', $this->as_bot( self::BINGBOT, self::GOOGLE_IP )['class'], 'a Google address is not Bing' );
		$this->assertSame( 'bad_bot', $this->as_bot( self::GOOGLEBOT, self::BING_IP )['class'] );
	}

	public function test_without_a_list_or_with_a_private_address_it_fails_open() {
		unset( $this->options[ Bot_Verifier::OPTION ] );
		$verdict = $this->as_bot( self::GOOGLEBOT, self::OTHER_IP );
		$this->assertSame( 'info_bot', $verdict['class'] );
		$this->assertContains( 'unverified_bot', $verdict['signals'] );
		$this->options[ Bot_Verifier::OPTION ] = array( 'google' => array( '66.249.64.0/19' ) );
		foreach ( array( '10.0.0.5', '127.0.0.1', '', 'not an address' ) as $ip ) {
			$this->assertSame( 'info_bot', $this->as_bot( self::GOOGLEBOT, $ip )['class'], $ip );
			$this->assertContains( 'unverified_bot', Bot_Guard::current()['signals'], $ip );
		}
		// An engine whose list is missing fails open while the other one is checked.
		$this->assertSame( 'info_bot', $this->as_bot( self::BINGBOT, self::OTHER_IP )['class'] );
	}

	public function test_the_check_can_be_switched_off() {
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value ) => 'socialscore_verify_declared_bots' === $tag ? false : $value );
		$this->assertSame( 'info_bot', $this->as_bot( self::GOOGLEBOT, self::OTHER_IP )['class'] );
	}

	public function test_a_request_does_no_dns_no_download_and_no_write() {
		$before = $this->options;
		$this->as_bot( self::GOOGLEBOT, self::OTHER_IP );
		$this->as_bot( self::GOOGLEBOT, self::GOOGLE_IP );
		$this->assertSame( array(), $this->http, 'no download on the way of a request' );
		$this->assertSame( $before, $this->options, 'nothing written' );
		$this->assertSame( array(), $this->transients, 'no cache to fill' );
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-bot-verifier.php' );
		$this->assertStringNotContainsString( 'gethostbyaddr', $source );
		$this->assertStringNotContainsString( 'dns_get_record', $source );
	}

	public function test_other_declared_bots_and_other_requests_are_not_checked() {
		unset( $this->options[ Bot_Verifier::OPTION ] );
		$this->assertSame( array( 'declared_bot' ), $this->as_bot( 'facebookexternalhit/1.1', self::OTHER_IP )['signals'] );
		$this->assertSame( 'bad_bot', $this->as_bot( 'curl/8.4.0', self::OTHER_IP )['class'] );
	}

	public function test_nothing_is_checked_for_a_store_that_is_not_connected() {
		unset( $this->options['socialscore_connection'] );
		$this->assertSame( array( 'declared_bot' ), $this->as_bot( self::GOOGLEBOT, self::OTHER_IP )['signals'] );
	}

	public function test_ranges_are_matched_on_the_bit_not_the_byte() {
		$this->assertTrue( Bot_Verifier::in_range( '66.249.95.255', '66.249.64.0/19' ) );
		$this->assertFalse( Bot_Verifier::in_range( '66.249.96.0', '66.249.64.0/19' ) );
		$this->assertTrue( Bot_Verifier::in_range( '157.55.39.200', '157.55.39.0/24' ) );
		$this->assertFalse( Bot_Verifier::in_range( '157.55.40.1', '157.55.39.0/24' ) );
		$this->assertTrue( Bot_Verifier::in_range( '1.2.3.4', '0.0.0.0/0' ) );
		$this->assertTrue( Bot_Verifier::in_range( '2001:4860:4801:10:ffff::1', '2001:4860:4801:10::/64' ) );
		$this->assertFalse( Bot_Verifier::in_range( '2001:4860:4801:11::1', '2001:4860:4801:10::/64' ) );
		$this->assertFalse( Bot_Verifier::in_range( '66.249.66.1', '2001:4860:4801:10::/64' ), 'IPv4 against an IPv6 range' );
		foreach ( array( 'junk', '1.2.3.4/40', '1.2.3.4/x', '1.2.3.4', '/24', '' ) as $bad ) {
			$this->assertFalse( Bot_Verifier::in_range( '1.2.3.4', $bad ), $bad );
		}
	}

	public function test_a_published_file_is_read_into_valid_ranges_only() {
		$file = array(
			'creationTime' => '2026-11-13',
			'prefixes'     => array(
				array( 'ipv6Prefix' => '2001:4860:4801:10::/64' ),
				array( 'ipv4Prefix' => '66.249.64.0/27' ),
				array( 'ipv4Prefix' => '999.1.1.1/24' ),
				array( 'ipv4Prefix' => '1.2.3.4/33' ),
				array( 'ipv4Prefix' => 'evil;drop' ),
				array( 'other' => '1.1.1.1/32' ),
				'junk',
			),
		);
		$this->assertSame( array( '2001:4860:4801:10::/64', '66.249.64.0/27' ), Bot_Verifier::parse( $file ) );
		$this->assertSame( array(), Bot_Verifier::parse( 'not json' ) );
		$this->assertSame( array(), Bot_Verifier::parse( array( 'prefixes' => 'x' ) ) );
	}

	private function files( array $by_url ) {
		$this->http_responder = fn( $url ) => isset( $by_url[ $url ] ) ? self::response( 200, $by_url[ $url ] ) : self::response( 404, '' );
	}

	public function test_the_daily_job_downloads_the_published_files_into_one_option() {
		$google = array( 'prefixes' => array( array( 'ipv4Prefix' => '66.249.64.0/19' ) ) );
		$bing   = array( 'prefixes' => array( array( 'ipv4Prefix' => '157.55.39.0/24' ) ) );
		$by_url = array( 'https://www.bing.com/toolbox/bingbot.json' => $bing );
		foreach ( Bot_Verifier::SOURCES['google'] as $i => $url ) {
			$by_url[ $url ] = 0 === $i ? $google : array( 'prefixes' => array( array( 'ipv4Prefix' => '192.0.2.' . $i . '/32' ) ) );
		}
		$this->files( $by_url );
		unset( $this->options[ Bot_Verifier::OPTION ] );

		Bot_Verifier::refresh();

		$stored = $this->options[ Bot_Verifier::OPTION ];
		$this->assertContains( '66.249.64.0/19', $stored['google'] );
		$this->assertCount( 4, $stored['google'], 'the four Google files are one list' );
		$this->assertSame( array( '157.55.39.0/24' ), $stored['bing'] );
		$this->assertSame( 'no', $this->autoload[ Bot_Verifier::OPTION ], 'not autoloaded' );
		$urls = array_column( $this->http, 'url' );
		$this->assertContains( 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json', $urls );
		$this->assertContains( 'https://www.bing.com/toolbox/bingbot.json', $urls );
	}

	public function test_an_engine_whose_download_fails_keeps_the_list_it_had() {
		$this->files( array( 'https://www.bing.com/toolbox/bingbot.json' => array( 'prefixes' => array( array( 'ipv4Prefix' => '40.77.167.0/24' ) ) ) ) );
		Bot_Verifier::refresh();
		$stored = $this->options[ Bot_Verifier::OPTION ];
		$this->assertSame( array( '66.249.64.0/19', '2001:4860:4801:10::/64' ), $stored['google'], 'Google failed: the old list stays' );
		$this->assertSame( array( '40.77.167.0/24' ), $stored['bing'] );
		// An empty or garbled file is a failure too, never an empty list that would turn every Googlebot into a fake.
		$this->files( array( 'https://www.bing.com/toolbox/bingbot.json' => array( 'prefixes' => array() ) ) );
		Bot_Verifier::refresh();
		$this->assertSame( array( '40.77.167.0/24' ), $this->options[ Bot_Verifier::OPTION ]['bing'] );
	}

	public function test_the_job_does_nothing_for_a_store_that_is_not_connected() {
		unset( $this->options['socialscore_connection'] );
		Bot_Verifier::refresh();
		$this->assertSame( array(), $this->http );
	}

	public function test_the_job_is_scheduled_daily_once_and_soon_when_there_is_no_list() {
		$calls = array();
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $time, $recurrence, $hook ) use ( &$calls ) {
				$calls[] = array( $time, $recurrence, $hook );
				return true;
			}
		);
		unset( $this->options[ Bot_Verifier::OPTION ] );
		Bot_Verifier::schedule();
		$this->assertSame( array( 'daily', 'socialscore_refresh_bot_ranges' ), array( $calls[0][1], $calls[0][2] ) );
		$this->assertLessThanOrEqual( time() + 61, $calls[0][0] );
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 100 );
		Bot_Verifier::schedule();
		$this->assertCount( 1, $calls, 'not scheduled twice' );
	}
}
