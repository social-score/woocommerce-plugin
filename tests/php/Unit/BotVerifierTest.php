<?php
namespace SocialScore\Tests\Unit;

use SocialScore\Bot_Guard;
use SocialScore\Bot_Verifier;
use SocialScore\Tests\TestCase;

class BotVerifierTest extends TestCase {

	const GOOGLE_IP = '66.249.66.1';
	const FAKE_IP   = '203.0.113.50';
	const REAL_IP   = '198.51.100.7';

	/** @var array Lookups made. */
	private $lookups = array();
	/** @var array */
	private $server_before = array();

	protected function setUp(): void {
		parent::setUp();
		$this->server_before = $_SERVER;
		$this->lookups       = array();
		Bot_Guard::reset();
		Bot_Verifier::use_resolver(
			function ( $type, $value ) {
				$this->lookups[] = array( $type, $value );
				$ptr = array(
					self::GOOGLE_IP => 'crawl-66-249-66-1.googlebot.com',
					self::FAKE_IP   => 'ec2-203-0-113-50.compute-1.amazonaws.com',
					self::REAL_IP   => 'forged.googlebot.com', // A PTR the attacker controls: it does not resolve back.
				);
				$fwd = array(
					'crawl-66-249-66-1.googlebot.com'               => array( self::GOOGLE_IP ),
					'ec2-203-0-113-50.compute-1.amazonaws.com'     => array( self::FAKE_IP ),
					'forged.googlebot.com'                         => array( '192.0.2.99' ),
				);
				return 'ptr' === $type ? ( $ptr[ $value ] ?? null ) : ( $fwd[ $value ] ?? array() );
			}
		);
		$this->connect_store();
	}

	protected function tearDown(): void {
		Bot_Verifier::use_resolver( null );
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

	const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

	public function test_the_real_googlebot_is_verified_and_stays_a_known_bot() {
		$verdict = $this->as_bot( self::GOOGLEBOT, self::GOOGLE_IP );
		$this->assertSame( 'info_bot', $verdict['class'] );
		$this->assertSame( array( 'declared_bot' ), $verdict['signals'] );
	}

	public function test_a_scraper_that_says_googlebot_from_a_cloud_host_is_a_bad_bot() {
		$verdict = $this->as_bot( self::GOOGLEBOT, self::FAKE_IP );
		$this->assertSame( 'bad_bot', $verdict['class'] );
		$this->assertContains( 'fake_bot', $verdict['signals'] );
	}

	public function test_a_name_that_does_not_resolve_back_is_unknown_and_fails_open() {
		$verdict = $this->as_bot( self::GOOGLEBOT, self::REAL_IP );
		$this->assertSame( 'info_bot', $verdict['class'] );
		$this->assertContains( 'unverified_bot', $verdict['signals'] );
	}

	public function test_no_answer_from_dns_a_private_address_or_no_address_fail_open() {
		foreach ( array( '198.51.100.200', '10.0.0.5', '127.0.0.1', '' ) as $ip ) {
			$verdict = $this->as_bot( self::GOOGLEBOT, $ip );
			$this->assertSame( 'info_bot', $verdict['class'], $ip );
			$this->assertContains( 'unverified_bot', $verdict['signals'], $ip );
		}
	}

	public function test_bingbot_is_checked_against_search_msn_com() {
		Bot_Verifier::use_resolver(
			fn( $type, $value ) => 'ptr' === $type ? 'msnbot-40-77-167-1.search.msn.com' : array( '198.51.100.7' )
		);
		$ua = 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)';
		$this->assertSame( 'info_bot', $this->as_bot( $ua, '198.51.100.7' )['class'] );
		Bot_Verifier::use_resolver(
			fn( $type, $value ) => 'ptr' === $type ? 'crawl.googlebot.com' : array( '198.51.100.8' )
		);
		$this->assertSame( 'bad_bot', $this->as_bot( $ua, '198.51.100.8' )['class'], 'Google is not Bing' );
	}

	public function test_the_answer_is_kept_for_a_day_per_address() {
		$this->as_bot( self::GOOGLEBOT, self::GOOGLE_IP );
		$first = count( $this->lookups );
		$this->assertGreaterThan( 0, $first );
		$this->as_bot( self::GOOGLEBOT, self::GOOGLE_IP );
		$this->assertCount( $first, $this->lookups );
		$this->as_bot( self::GOOGLEBOT, self::FAKE_IP );
		$this->assertGreaterThan( $first, count( $this->lookups ) );
	}

	public function test_other_declared_bots_and_other_requests_cost_no_lookup() {
		$this->as_bot( 'facebookexternalhit/1.1', self::FAKE_IP );
		$this->as_bot( 'curl/8.4.0', self::FAKE_IP );
		$this->as_bot( 'Mozilla/5.0 Chrome/126.0', self::FAKE_IP );
		$this->assertSame( array(), $this->lookups );
	}

	public function test_a_host_name_must_end_in_the_domain_not_just_contain_it() {
		Bot_Verifier::use_resolver(
			fn( $type, $value ) => 'ptr' === $type ? 'googlebot.com.evil.example' : array( self::FAKE_IP )
		);
		$this->assertSame( 'bad_bot', $this->as_bot( self::GOOGLEBOT, self::FAKE_IP )['class'] );
	}

	public function test_nothing_is_looked_up_for_a_store_that_is_not_connected() {
		unset( $this->options['socialscore_connection'] );
		$this->as_bot( self::GOOGLEBOT, self::FAKE_IP );
		$this->assertSame( array(), $this->lookups );
	}
}
