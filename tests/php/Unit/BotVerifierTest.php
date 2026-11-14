<?php
namespace SocialScore\Tests\Unit;

use Brain\Monkey\Functions;
use SocialScore\Bot_Guard;
use SocialScore\Bot_Settings;
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
			'google_common'  => array( '66.249.64.0/19', '2001:4860:4801:10::/64' ),
			'google_special' => array( '198.51.100.0/27' ),
			'bing'           => array( '157.55.39.0/24', '40.77.167.0/24' ),
			'cloudflare'     => array( '173.245.48.0/20', '104.16.0.0/13', '2606:4700::/32' ),
			'fetched'        => time(),
		);
		Functions\when( 'wp_remote_get' )->alias( fn( $url, $args = array() ) => wp_remote_request( $url, array_merge( $args, array( 'method' => 'GET' ) ) ) );
		$this->connect_store();
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_before;
		Bot_Guard::reset();
		parent::tearDown();
	}

	private function as_bot( $ua, $ip, array $headers = array() ) {
		$_SERVER['HTTP_USER_AGENT'] = $ua;
		$_SERVER['REMOTE_ADDR']     = $ip;
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_FORWARDED'], $_SERVER['HTTP_TRUE_CLIENT_IP'] );
		foreach ( $headers as $name => $value ) {
			$_SERVER[ $name ] = $value;
		}
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
		$this->options[ Bot_Verifier::OPTION ] = array( 'google_common' => array( '66.249.64.0/19' ) );
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
		$by_url = array(
			'https://developers.google.com/static/crawling/ipranges/common-crawlers.json' => array( 'prefixes' => array( array( 'ipv4Prefix' => '66.249.64.0/19' ) ) ),
			'https://developers.google.com/static/crawling/ipranges/special-crawlers.json' => array( 'prefixes' => array( array( 'ipv4Prefix' => '66.249.90.0/27' ) ) ),
			'https://www.bing.com/toolbox/bingbot.json' => array( 'prefixes' => array( array( 'ipv4Prefix' => '157.55.39.0/24' ) ) ),
			'https://www.cloudflare.com/ips-v4'         => "173.245.48.0/20\n103.21.244.0/22\n",
			'https://www.cloudflare.com/ips-v6'         => "2400:cb00::/32\njunk\n",
		);
		$this->files( $by_url );
		unset( $this->options[ Bot_Verifier::OPTION ] );

		Bot_Verifier::refresh();

		$stored = $this->options[ Bot_Verifier::OPTION ];
		$this->assertSame( array( '66.249.64.0/19' ), $stored['google_common'] );
		$this->assertSame( array( '66.249.90.0/27' ), $stored['google_special'], 'each Google file under its own key' );
		$this->assertSame( array( '157.55.39.0/24' ), $stored['bing'] );
		$this->assertSame( array( '173.245.48.0/20', '103.21.244.0/22', '2400:cb00::/32' ), $stored['cloudflare'], 'a plain list, junk lines dropped' );
		$this->assertSame( 'no', $this->autoload[ Bot_Verifier::OPTION ], 'not autoloaded' );
		$urls = array_column( $this->http, 'url' );
		$this->assertContains( 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json', $urls );
		foreach ( $urls as $url ) {
			$this->assertStringNotContainsString( 'user-triggered', $url, 'the files of user-triggered fetchers are never downloaded' );
		}
		$this->assertContains( 'https://www.bing.com/toolbox/bingbot.json', $urls );
	}

	public function test_an_engine_whose_download_fails_keeps_the_list_it_had() {
		$this->files( array( 'https://www.bing.com/toolbox/bingbot.json' => array( 'prefixes' => array( array( 'ipv4Prefix' => '40.77.167.0/24' ) ) ) ) );
		Bot_Verifier::refresh();
		$stored = $this->options[ Bot_Verifier::OPTION ];
		$this->assertSame( array( '66.249.64.0/19', '2001:4860:4801:10::/64' ), $stored['google_common'], 'Google failed: the old list stays' );
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

	// Which file a token may be checked against ---------------------------------------------------------------------

	public function test_googlebot_from_an_address_that_is_only_in_a_file_of_another_crawler_is_a_fake() {
		// 198.51.100.9 is in the special-crawlers file (AdsBot), not in the common crawlers file.
		$this->assertSame( 'bad_bot', $this->as_bot( self::GOOGLEBOT, '198.51.100.9' )['class'] );
		$adsbot = 'AdsBot-Google (+http://www.google.com/adsbot.html)';
		$this->assertSame( 'info_bot', $this->as_bot( $adsbot, '198.51.100.9' )['class'] );
		$this->assertSame( 'bad_bot', $this->as_bot( $adsbot, self::GOOGLE_IP )['class'], 'AdsBot is not in the common file' );
		$this->assertSame( 'info_bot', $this->as_bot( 'Mediapartners-Google', '198.51.100.9' )['class'] );
		$this->assertSame( 'bad_bot', $this->as_bot( self::BINGBOT, '198.51.100.9' )['class'] );
	}

	public function test_an_address_that_is_only_a_user_triggered_fetcher_never_makes_a_verified_bot() {
		// Apps Script and Site Verifier fetch for anyone from addresses Google lists as user-triggered. Those files are not
		// kept, so such an address is in no range: a fake.
		$fetcher = '74.125.210.10';
		$this->assertSame( 'bad_bot', $this->as_bot( self::GOOGLEBOT, $fetcher )['class'] );
		$this->assertContains( 'fake_bot', Bot_Guard::current()['signals'] );
		$this->assertSame( array( 'google_common', 'google_special', 'bing', 'cloudflare' ), array_keys( Bot_Verifier::SOURCES ) );
	}

	// Behind a proxy or a CDN: the shop says which proxies it trusts --------------------------------------------------------

	private function settings( array $over ) {
		$this->options[ Bot_Settings::OPTION ] = $over;
	}

	public function test_cloudflare_is_not_trusted_unless_the_shop_says_it_is_behind_it() {
		// Setting off (the default): a Cloudflare address with the header is unknown, never verified and never a 403.
		$verdict = $this->as_bot( self::GOOGLEBOT, '104.16.20.30', array( 'HTTP_CF_CONNECTING_IP' => self::GOOGLE_IP ) );
		$this->assertSame( 'info_bot', $verdict['class'] );
		$this->assertContains( 'unverified_bot', $verdict['signals'] );
		$this->assertSame( '', Bot_Guard::client_ip() );
		$this->assertSame( 'info_bot', $this->as_bot( self::GOOGLEBOT, '104.16.20.30', array( 'HTTP_CF_CONNECTING_IP' => self::OTHER_IP ) )['class'], 'a header is not believed even when it names a scraper' );
		// The merchant says it is: now the header is the client.
		$this->settings( array( 'behind_cloudflare' => true ) );
		$verdict = $this->as_bot( self::GOOGLEBOT, '104.16.20.30', array( 'HTTP_CF_CONNECTING_IP' => self::GOOGLE_IP ) );
		$this->assertSame( array( 'info_bot', array( 'declared_bot' ) ), array( $verdict['class'], $verdict['signals'] ) );
		$this->assertSame( 'bad_bot', $this->as_bot( self::GOOGLEBOT, '104.16.20.30', array( 'HTTP_CF_CONNECTING_IP' => self::OTHER_IP ) )['class'] );
	}

	public function test_cloudflare_with_the_setting_on_but_no_valid_header_is_unknown() {
		$this->settings( array( 'behind_cloudflare' => true ) );
		foreach ( array( array(), array( 'HTTP_CF_CONNECTING_IP' => 'not an address' ) ) as $headers ) {
			$verdict = $this->as_bot( self::GOOGLEBOT, '104.16.20.30', $headers );
			$this->assertSame( 'info_bot', $verdict['class'] );
			$this->assertContains( 'unverified_bot', $verdict['signals'] );
		}
	}

	public function test_the_setting_can_also_come_from_a_filter() {
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value ) => 'socialscore_behind_cloudflare' === $tag ? true : $value );
		$this->assertSame( 'info_bot', $this->as_bot( self::GOOGLEBOT, '104.16.20.30', array( 'HTTP_CF_CONNECTING_IP' => self::GOOGLE_IP ) )['class'] );
		$this->assertSame( self::GOOGLE_IP, Bot_Guard::client_ip() );
	}

	public function test_a_scraper_with_a_public_address_cannot_make_itself_verified_by_a_header() {
		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_TRUE_CLIENT_IP' ) as $header ) {
			$verdict = $this->as_bot( self::GOOGLEBOT, self::OTHER_IP, array( $header => self::GOOGLE_IP ) );
			$this->assertSame( 'bad_bot', $verdict['class'], $header );
			$this->assertContains( 'fake_bot', $verdict['signals'], $header );
			$this->assertSame( self::OTHER_IP, Bot_Guard::client_ip(), 'the headers are ignored: ' . $header );
		}
	}

	public function test_a_trusted_proxy_from_the_settings_gives_the_client_in_its_header() {
		$this->settings( array( 'trusted_proxies' => array( '198.51.100.0/24' ) ) );
		$verdict = $this->as_bot( self::GOOGLEBOT, '198.51.100.7', array( 'HTTP_X_FORWARDED_FOR' => self::GOOGLE_IP ) );
		$this->assertSame( array( 'info_bot', array( 'declared_bot' ) ), array( $verdict['class'], $verdict['signals'] ) );
		$this->assertSame( 'bad_bot', $this->as_bot( self::GOOGLEBOT, '198.51.100.7', array( 'HTTP_X_FORWARDED_FOR' => self::OTHER_IP ) )['class'] );
	}

	public function test_the_client_is_the_right_most_address_that_is_not_a_trusted_proxy() {
		$this->settings( array( 'trusted_proxies' => array( '198.51.100.0/24', '10.0.0.0/8' ) ) );
		// A client may have written the left of the list; the proxies add on the right.
		$this->as_bot( self::GOOGLEBOT, '198.51.100.7', array( 'HTTP_X_FORWARDED_FOR' => self::GOOGLE_IP . ', ' . self::OTHER_IP . ', 10.1.1.1, 198.51.100.9' ) );
		$this->assertSame( self::OTHER_IP, Bot_Guard::client_ip() );
		$this->as_bot( self::GOOGLEBOT, '198.51.100.7', array( 'HTTP_X_FORWARDED_FOR' => '10.1.1.1, 198.51.100.9' ) );
		$this->assertSame( '', Bot_Guard::client_ip(), 'only proxies listed: unknown' );
		$this->as_bot( self::GOOGLEBOT, '198.51.100.7', array( 'HTTP_X_FORWARDED_FOR' => 'junk, ' . self::GOOGLE_IP ) );
		$this->assertSame( self::GOOGLE_IP, Bot_Guard::client_ip() );
		$this->as_bot( self::GOOGLEBOT, '198.51.100.7', array( 'HTTP_X_FORWARDED_FOR' => self::GOOGLE_IP . ', junk' ) );
		$this->assertSame( '', Bot_Guard::client_ip(), 'a broken entry on the right is not skipped' );
	}

	public function test_a_trusted_proxy_without_the_header_is_unknown_and_never_fake() {
		$this->settings( array( 'trusted_proxies' => array( '198.51.100.0/24' ) ) );
		$verdict = $this->as_bot( self::GOOGLEBOT, '198.51.100.7' );
		$this->assertSame( 'info_bot', $verdict['class'] );
		$this->assertContains( 'unverified_bot', $verdict['signals'] );
	}

	public function test_another_header_name_can_be_chosen_for_a_trusted_proxy() {
		$this->settings(
			array(
				'trusted_proxies' => array( '198.51.100.0/24' ),
				'header'          => 'X-Real-IP',
			)
		);
		$this->as_bot(
			self::GOOGLEBOT,
			'198.51.100.7',
			array(
				'HTTP_X_REAL_IP'       => self::GOOGLE_IP,
				'HTTP_X_FORWARDED_FOR' => self::OTHER_IP,
			)
		);
		$this->assertSame( self::GOOGLE_IP, Bot_Guard::client_ip() );
	}

	public function test_a_private_address_is_unknown_and_never_gets_a_403() {
		foreach ( array( '10.1.2.3', '172.18.0.5', '192.168.1.9', '127.0.0.1' ) as $ip ) {
			$verdict = $this->as_bot( self::GOOGLEBOT, $ip, array( 'HTTP_X_FORWARDED_FOR' => self::OTHER_IP ) );
			$this->assertSame( 'info_bot', $verdict['class'], $ip );
			$this->assertContains( 'unverified_bot', $verdict['signals'], $ip );
		}
	}

	public function test_the_shop_can_name_the_client_address_itself() {
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value ) => 'socialscore_client_ip' === $tag ? self::GOOGLE_IP : $value );
		$this->assertSame( 'info_bot', $this->as_bot( self::GOOGLEBOT, '10.0.0.7', array( 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ) )['class'] );
		$this->assertSame( self::GOOGLE_IP, Bot_Guard::client_ip() );
	}

	// The warning for the merchant -------------------------------------------------------------------------------------

	public function test_a_scraper_cannot_make_the_module_warn_the_merchant_by_writing_headers() {
		$this->as_bot( self::GOOGLEBOT, self::OTHER_IP, array( 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 5.6.7.8' ) );
		$this->as_bot( self::GOOGLEBOT, self::OTHER_IP, array( 'HTTP_CF_CONNECTING_IP' => self::OTHER_IP ) );
		$this->as_bot( self::GOOGLEBOT, '104.16.20.30', array( 'HTTP_CF_CONNECTING_IP' => self::OTHER_IP ) );
		$this->as_bot( self::GOOGLEBOT, '104.16.20.30', array( 'HTTP_CF_CONNECTING_IP' => 'junk' ) );
		$this->as_bot( self::BINGBOT, '104.16.20.30', array( 'HTTP_CF_CONNECTING_IP' => self::GOOGLE_IP ) );
		$this->assertSame( array(), Bot_Settings::hints(), 'a Google address in the header does not make a Bing request plausible' );
		$this->assertSame( array(), $this->transients, 'no lock written either' );
	}

	public function test_googlebot_through_cloudflare_with_its_real_address_makes_the_warning_for_cloudflare() {
		$this->as_bot( self::GOOGLEBOT, '104.16.20.30', array( 'HTTP_CF_CONNECTING_IP' => self::GOOGLE_IP ) );
		$this->assertSame( 1, Bot_Settings::hints()['cloudflare']['count'] );
		$this->assertArrayNotHasKey( 'proxy', Bot_Settings::hints() );
	}

	public function test_a_real_googlebot_address_in_the_headers_of_a_public_proxy_makes_the_warning_for_a_proxy() {
		$this->as_bot( self::GOOGLEBOT, self::OTHER_IP, array( 'HTTP_X_FORWARDED_FOR' => '10.1.1.1, ' . self::GOOGLE_IP ) );
		$this->assertSame( 1, Bot_Settings::hints()['proxy']['count'] );
		// A Bing address does not do for a Google request.
		$this->transients = array();
		$this->as_bot( self::GOOGLEBOT, self::OTHER_IP, array( 'HTTP_X_FORWARDED_FOR' => self::BING_IP ) );
		$this->assertSame( 1, Bot_Settings::hints()['proxy']['count'] );
	}

	public function test_the_warning_is_written_at_most_once_an_hour_for_each_kind() {
		for ( $i = 0; $i < 20; $i++ ) {
			$this->as_bot( self::GOOGLEBOT, '104.16.20.30', array( 'HTTP_CF_CONNECTING_IP' => self::GOOGLE_IP ) );
			$this->as_bot( self::GOOGLEBOT, self::OTHER_IP, array( 'HTTP_X_FORWARDED_FOR' => self::GOOGLE_IP ) );
		}
		$this->assertSame( 1, Bot_Settings::hints()['cloudflare']['count'] );
		$this->assertSame( 1, Bot_Settings::hints()['proxy']['count'] );
		$this->assertSame( 'no', $this->autoload[ Bot_Settings::HINTS ], 'not autoloaded' );
		$this->transients = array(); // An hour later.
		$this->as_bot( self::GOOGLEBOT, '104.16.20.30', array( 'HTTP_CF_CONNECTING_IP' => self::GOOGLE_IP ) );
		$this->assertSame( 2, Bot_Settings::hints()['cloudflare']['count'] );
	}

	public function test_a_plain_scraper_and_a_real_crawl_leave_no_note() {
		$this->as_bot( self::GOOGLEBOT, self::OTHER_IP );
		$this->as_bot( self::GOOGLEBOT, self::GOOGLE_IP );
		$this->assertSame( array(), Bot_Settings::hints() );
	}

	public function test_a_trusted_proxy_range_that_would_switch_the_check_off_is_refused() {
		$refused = array( '0.0.0.0/0', '::/0', '0.0.0.0/8', '1.0.0.0/8', '8.8.0.0/15', '66.249.0.0/12', '2001:db8::/16', '2001:4860::/31', '100.64.0.0/10', '0.0.0.0/16', '10.0.0.0/7', '172.16.0.0/11' );
		foreach ( $refused as $cidr ) {
			$this->assertSame( array(), Bot_Settings::ranges( $cidr ), $cidr );
		}
		foreach ( array( '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '192.168.1.0/24', '127.0.0.1', 'fd00::/8', 'fc00::/7', '198.51.100.0/24', '203.0.113.9', '2a00:1450::/32', '66.249.0.0/16' ) as $cidr ) {
			$this->assertNotSame( array(), Bot_Settings::ranges( $cidr ), $cidr );
		}
		Bot_Settings::save( false, "0.0.0.0/0\n::/0\n198.51.100.0/24", 'X-Forwarded-For' );
		$this->assertSame( array( '198.51.100.0/24' ), Bot_Settings::get()['trusted_proxies'], 'the wide ones are not stored' );
	}

	public function test_a_wide_range_from_the_filter_does_not_get_in_either() {
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value ) => 'socialscore_trusted_proxies' === $tag ? array( '0.0.0.0/0', '198.51.100.0/24' ) : $value );
		$this->assertSame( array( '198.51.100.0/24' ), Bot_Settings::get()['trusted_proxies'] );
		// So a scraper behind no declared proxy is still judged by its own address.
		$this->assertSame( 'bad_bot', $this->as_bot( self::GOOGLEBOT, self::OTHER_IP, array( 'HTTP_X_FORWARDED_FOR' => self::GOOGLE_IP ) )['class'] );
	}

	public function test_the_settings_are_read_back_clean() {
		Bot_Settings::save( true, "198.51.100.0/24, 203.0.113.9\n<script>, 999.1.1.1/8, 10.0.0.0/33", 'X-Real-IP' );
		$s = Bot_Settings::get();
		$this->assertTrue( $s['behind_cloudflare'] );
		$this->assertSame( array( '198.51.100.0/24', '203.0.113.9/32' ), $s['trusted_proxies'] );
		$this->assertSame( 'X-Real-IP', $s['header'] );
		Bot_Settings::save( false, '', 'bad header!' );
		$this->assertSame( 'X-Forwarded-For', Bot_Settings::get()['header'] );
		$this->assertFalse( Bot_Settings::get()['behind_cloudflare'] );
		$this->assertSame( 'no', $this->autoload[ Bot_Settings::OPTION ] );
	}
}
