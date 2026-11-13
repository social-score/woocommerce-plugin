<?php
/**
 * Server-side detection of bots and agents that do not run JavaScript (requirements C3).
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Reads one request's headers and gives a class plus the signals that explain it, the same words the Pixel uses
 * (contract v4 section 2). It keeps nothing between requests: no cookie, no stored id, no IP. It is a pure function of
 * the headers, so it needs no WordPress to test.
 *
 * It can only say that a request is a bot, never that it is a person: a visitor with no JavaScript and a plain
 * browser stays UNKNOWN. A declared bot (Googlebot) is taken at its word: the plugin does not check the address it
 * comes from, so a bot can pretend to be a good one. That is why a declared bot is never blocked.
 */
class Bot_Detector {


	const UNKNOWN  = 'unknown';
	const INFO_BOT = 'info_bot';
	const AI_AGENT = 'ai_agent';
	const BAD_BOT  = 'bad_bot';
	const MAX_UA   = 512;

	/**
	 * Declared AI crawlers and agents: token => array( system, purpose ). Checked before the info bots, because
	 * "Applebot-Extended" contains "Applebot".
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	const AI_AGENTS = array(
		'oai-searchbot'     => array( 'chatgpt', 'scan' ),
		'gptbot'            => array( 'chatgpt', 'scan' ),
		'chatgpt-user'      => array( 'chatgpt', 'lookup' ),
		'chatgpt agent'     => array( 'chatgpt', 'shop' ),
		'claudebot'         => array( 'claude', 'scan' ),
		'claude-searchbot'  => array( 'claude', 'scan' ),
		'claude-user'       => array( 'claude', 'lookup' ),
		'perplexitybot'     => array( 'perplexity', 'scan' ),
		'perplexity-user'   => array( 'perplexity', 'lookup' ),
		'google-extended'   => array( 'gemini', 'scan' ),
		'applebot-extended' => array( 'other', 'scan' ),
		'ccbot'             => array( 'other', 'scan' ),
		'bytespider'        => array( 'other', 'scan' ),
	);

	/**
	 * Declared legitimate bots (contract v4 section 2), lower case.
	 *
	 * @var string[]
	 */
	const INFO_BOTS = array(
		'googlebot',
		'bingbot',
		'duckduckbot',
		'yandexbot',
		'applebot',
		'baiduspider',
		'facebookexternalhit',
		'facebot',
		'twitterbot',
		'linkedinbot',
		'slackbot',
		'discordbot',
		'whatsapp',
		'telegrambot',
		'pinterestbot',
		'semrushbot',
		'ahrefsbot',
		'uptimerobot',
		'pingdom',
		'statuscake',
		'site24x7',
		'googleother',
		'google-inspectiontool',
		'storebot-google',
		'adsbot-google',
	);

	/**
	 * Tokens of automation frameworks and headless browsers in the user agent.
	 *
	 * @var string[]
	 */
	const HEADLESS = array( 'headlesschrome', 'phantomjs', 'playwright', 'puppeteer', 'selenium', 'webdriver' );

	/**
	 * Tokens of HTTP libraries and scrapers. A real browser never sends these. Libraries that legitimate integrations
	 * use against a shop (a payment gateway's webhook) are reached through paths the guard skips, not through this list.
	 *
	 * @var string[]
	 */
	const HTTP_CLIENTS = array(
		'curl/',
		'wget/',
		'python-requests',
		'python-urllib',
		'python-httpx',
		'aiohttp',
		'go-http-client',
		'libwww-perl',
		'scrapy',
		'node-fetch',
		'axios/',
		'apache-httpclient',
		'postmanruntime',
		'httrack',
		'mechanize',
		'java/',
	);

	/**
	 * Security scanners that say who they are.
	 *
	 * @var string[]
	 */
	const SCANNERS = array( 'sqlmap', 'nikto', 'nmap', 'masscan', 'zgrab', 'acunetix', 'netsparker' );

	/**
	 * Classifies one request.
	 *
	 * @param  array $server The $_SERVER of the request (HTTP_* headers; HTTPS).
	 * @return array{class:string,signals:string[],agent_system:?string,agent_purpose:?string}
	 */
	public static function classify( array $server ) {
		$ua   = self::header( $server, 'HTTP_USER_AGENT' );
		$ua   = substr( $ua, 0, self::MAX_UA );
		$low  = strtolower( $ua );
		$none = array(
			'class'         => self::UNKNOWN,
			'signals'       => array(),
			'agent_system'  => null,
			'agent_purpose' => null,
		);

		if ( '' !== self::header( $server, 'HTTP_SIGNATURE_AGENT' ) ) {
			return array(
				'class'         => self::AI_AGENT,
				'signals'       => array( 'signature_agent' ),
				'agent_system'  => 'other',
				'agent_purpose' => 'shop',
			);
		}
		foreach ( self::AI_AGENTS as $token => $info ) {
			if ( false !== strpos( $low, $token ) ) {
				return array(
					'class'         => self::AI_AGENT,
					'signals'       => array( 'declared_agent' ),
					'agent_system'  => $info[0],
					'agent_purpose' => $info[1],
				);
			}
		}
		foreach ( self::INFO_BOTS as $token ) {
			if ( false !== strpos( $low, $token ) ) {
				return array_merge(
					$none,
					array(
						'class'   => self::INFO_BOT,
						'signals' => array( 'declared_bot' ),
					)
				);
			}
		}

		$signals = array();
		if ( '' === trim( $ua ) ) {
			$signals[] = 'empty_ua';
		}
		if ( self::contains_any( $low, self::HEADLESS ) ) {
			$signals[] = 'headless_ua';
		}
		if ( self::contains_any( $low, self::HTTP_CLIENTS ) ) {
			$signals[] = 'http_client_ua';
		}
		if ( self::contains_any( $low, self::SCANNERS ) ) {
			$signals[] = 'scanner_ua';
		}
		if ( $signals ) {
			return array_merge(
				$none,
				array(
					'class'   => self::BAD_BOT,
					'signals' => $signals,
				)
			);
		}

		// A browser user agent without what every browser sends: two of these together are a script that borrowed
		// the user agent. One alone is not enough (a privacy tool can drop one header).
		if ( 0 === strpos( $low, 'mozilla/' ) ) {
			$weak = array();
			if ( '' === self::header( $server, 'HTTP_ACCEPT_LANGUAGE' ) ) {
				$weak[] = 'no_accept_language';
			}
			if ( '' === self::header( $server, 'HTTP_ACCEPT' ) ) {
				$weak[] = 'no_accept';
			}
			if ( self::modern_chromium( $low ) && self::is_https( $server ) && '' === self::header( $server, 'HTTP_SEC_FETCH_MODE' ) && '' === self::header( $server, 'HTTP_SEC_FETCH_SITE' ) ) {
				$weak[] = 'no_fetch_metadata';
			}
			if ( count( $weak ) >= 2 ) {
				return array_merge(
					$none,
					array(
						'class'   => self::BAD_BOT,
						'signals' => $weak,
					)
				);
			}
		}
		return $none;
	}

	/**
	 * Whether a class means the request is not from a person.
	 *
	 * @param  string $kind Class from classify().
	 * @return bool
	 */
	public static function is_not_human( $kind ) {
		return in_array( $kind, array( self::INFO_BOT, self::AI_AGENT, self::BAD_BOT ), true );
	}

	/**
	 * Chromium 80 or later sends fetch metadata on HTTPS (Chrome 76+, Edge, Opera).
	 *
	 * @param  string $low Lower case user agent.
	 * @return bool
	 */
	private static function modern_chromium( $low ) {
		return 1 === preg_match( '#chrome/(\d+)#', $low, $m ) && (int) $m[1] >= 80;
	}

	/**
	 * Whether the request came over HTTPS (a browser sends fetch metadata only to secure origins).
	 *
	 * @param  array $server Server variables.
	 * @return bool
	 */
	private static function is_https( array $server ) {
		$https = isset( $server['HTTPS'] ) ? strtolower( (string) $server['HTTPS'] ) : '';
		return '' !== $https && 'off' !== $https;
	}

	/**
	 * One header value, trimmed; empty when it is missing or not a string.
	 *
	 * @param  array  $server Server variables.
	 * @param  string $key    Key.
	 * @return string
	 */
	private static function header( array $server, $key ) {
		return isset( $server[ $key ] ) && is_string( $server[ $key ] ) ? trim( $server[ $key ] ) : '';
	}

	/**
	 * Whether the lower case text holds any of the tokens.
	 *
	 * @param  string   $low    Text.
	 * @param  string[] $tokens Tokens.
	 * @return bool
	 */
	private static function contains_any( $low, array $tokens ) {
		foreach ( $tokens as $token ) {
			if ( false !== strpos( $low, $token ) ) {
				return true;
			}
		}
		return false;
	}
}
