<?php
namespace SocialScore\Tests\Unit;

use SocialScore\Bot_Detector;
use SocialScore\Tests\TestCase;

class BotDetectorTest extends TestCase {

	const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

	private function browser_headers( array $over = array() ) {
		return $over + array(
			'HTTP_USER_AGENT'      => self::CHROME,
			'HTTP_ACCEPT'          => 'text/html,application/xhtml+xml',
			'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
			'HTTP_SEC_FETCH_MODE'  => 'navigate',
			'HTTP_SEC_FETCH_SITE'  => 'none',
			'HTTPS'                => 'on',
		);
	}

	public function test_a_plain_browser_is_unknown_never_a_person() {
		$result = Bot_Detector::classify( $this->browser_headers() );
		$this->assertSame( Bot_Detector::UNKNOWN, $result['class'] );
		$this->assertSame( array(), $result['signals'] );
		$this->assertFalse( Bot_Detector::is_not_human( $result['class'] ) );
	}

	/**
	 * @dataProvider scripts
	 */
	public function test_a_script_without_javascript_is_a_bad_bot( $ua, $signal ) {
		$result = Bot_Detector::classify(
			array(
				'HTTP_USER_AGENT' => $ua,
				'HTTP_ACCEPT'     => '*/*',
			)
		);
		$this->assertSame( Bot_Detector::BAD_BOT, $result['class'] );
		$this->assertContains( $signal, $result['signals'] );
	}

	public function scripts() {
		return array(
			'curl'            => array( 'curl/8.4.0', 'http_client_ua' ),
			'python requests' => array( 'python-requests/2.31.0', 'http_client_ua' ),
			'go client'       => array( 'Go-http-client/1.1', 'http_client_ua' ),
			'scrapy'          => array( 'Scrapy/2.11 (+https://scrapy.org)', 'http_client_ua' ),
			'headless chrome' => array( 'Mozilla/5.0 HeadlessChrome/126.0.0.0 Safari/537.36', 'headless_ua' ),
			'playwright'      => array( 'Mozilla/5.0 Playwright/1.45', 'headless_ua' ),
			'sqlmap'          => array( 'sqlmap/1.7#stable (https://sqlmap.org)', 'scanner_ua' ),
			'empty'           => array( '', 'empty_ua' ),
		);
	}

	public function test_a_missing_user_agent_header_is_a_bad_bot() {
		$result = Bot_Detector::classify( array() );
		$this->assertSame( Bot_Detector::BAD_BOT, $result['class'] );
		$this->assertSame( array( 'empty_ua' ), $result['signals'] );
	}

	public function test_a_browser_user_agent_with_no_browser_headers_is_a_bad_bot_but_one_missing_header_is_not() {
		$bare = Bot_Detector::classify( array( 'HTTP_USER_AGENT' => self::CHROME ) );
		$this->assertSame( Bot_Detector::BAD_BOT, $bare['class'] );
		$this->assertContains( 'no_accept_language', $bare['signals'] );
		$this->assertContains( 'no_accept', $bare['signals'] );

		$one = Bot_Detector::classify( $this->browser_headers( array( 'HTTP_ACCEPT_LANGUAGE' => '' ) ) );
		$this->assertSame( Bot_Detector::UNKNOWN, $one['class'] );
	}

	public function test_fetch_metadata_is_only_expected_on_https_and_from_modern_chromium() {
		$http = $this->browser_headers(
			array(
				'HTTPS'                => '',
				'HTTP_SEC_FETCH_MODE'  => '',
				'HTTP_SEC_FETCH_SITE'  => '',
				'HTTP_ACCEPT_LANGUAGE' => '',
			)
		);
		$this->assertSame( Bot_Detector::UNKNOWN, Bot_Detector::classify( $http )['class'] );

		$https  = $this->browser_headers(
			array(
				'HTTP_SEC_FETCH_MODE'  => '',
				'HTTP_SEC_FETCH_SITE'  => '',
				'HTTP_ACCEPT_LANGUAGE' => '',
			)
		);
		$result = Bot_Detector::classify( $https );
		$this->assertSame( Bot_Detector::BAD_BOT, $result['class'] );
		$this->assertSame( array( 'no_accept_language', 'no_fetch_metadata' ), $result['signals'] );

		$old = str_replace( 'Chrome/126', 'Chrome/70', self::CHROME );
		$this->assertSame( Bot_Detector::UNKNOWN, Bot_Detector::classify( array_merge( $https, array( 'HTTP_USER_AGENT' => $old ) ) )['class'] );
	}

	/**
	 * @dataProvider good_bots
	 */
	public function test_a_declared_good_bot_is_an_info_bot( $ua ) {
		$result = Bot_Detector::classify( array( 'HTTP_USER_AGENT' => $ua ) );
		$this->assertSame( Bot_Detector::INFO_BOT, $result['class'] );
		$this->assertSame( array( 'declared_bot' ), $result['signals'] );
	}

	public function good_bots() {
		return array(
			'google'   => array( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ),
			'bing'     => array( 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)' ),
			'facebook' => array( 'facebookexternalhit/1.1' ),
			'uptime'   => array( 'Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)' ),
			'applebot' => array( 'Mozilla/5.0 (Applebot/0.1)' ),
		);
	}

	public function test_an_ai_agent_gets_its_system_and_purpose() {
		$scan = Bot_Detector::classify( array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot' ) );
		$this->assertSame( array( Bot_Detector::AI_AGENT, 'chatgpt', 'scan', array( 'declared_agent' ) ), array( $scan['class'], $scan['agent_system'], $scan['agent_purpose'], $scan['signals'] ) );

		$lookup = Bot_Detector::classify( array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Claude-User/1.0)' ) );
		$this->assertSame( array( 'claude', 'lookup' ), array( $lookup['agent_system'], $lookup['agent_purpose'] ) );

		$extended = Bot_Detector::classify( array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Applebot-Extended/0.1)' ) );
		$this->assertSame( Bot_Detector::AI_AGENT, $extended['class'], 'Applebot-Extended is an AI crawler, not the search Applebot' );
	}

	public function test_a_signature_agent_header_makes_a_shopping_agent_whatever_the_user_agent_says() {
		$result = Bot_Detector::classify( $this->browser_headers( array( 'HTTP_SIGNATURE_AGENT' => '"https://chatgpt.com"' ) ) );
		$this->assertSame( array( Bot_Detector::AI_AGENT, 'shop', array( 'signature_agent' ) ), array( $result['class'], $result['agent_purpose'], $result['signals'] ) );
	}

	public function test_a_declared_bot_is_never_a_bad_bot_even_with_a_script_like_user_agent() {
		$result = Bot_Detector::classify( array( 'HTTP_USER_AGENT' => 'Googlebot-curl/1.0' ) );
		$this->assertSame( Bot_Detector::INFO_BOT, $result['class'] );
	}

	public function test_a_very_long_user_agent_is_cut_and_non_strings_are_ignored() {
		$result = Bot_Detector::classify( array( 'HTTP_USER_AGENT' => str_repeat( 'a', 5000 ) . 'curl/' ) );
		$this->assertSame( Bot_Detector::UNKNOWN, $result['class'], 'the user agent is cut at 512 characters before it is read' );
		$result = Bot_Detector::classify( array( 'HTTP_USER_AGENT' => array( 'curl/' ) ) );
		$this->assertSame( array( 'empty_ua' ), $result['signals'] );
	}

	public function test_it_reads_nothing_but_the_headers_it_is_given() {
		$result = Bot_Detector::classify(
			array(
				'HTTP_USER_AGENT' => 'curl/8',
				'REMOTE_ADDR'     => '203.0.113.9',
			)
		);
		$this->assertSame( array( 'class', 'signals', 'agent_system', 'agent_purpose' ), array_keys( $result ) );
		$this->assertStringNotContainsString( '203.0.113.9', wp_json_encode( $result ) );
	}
}
