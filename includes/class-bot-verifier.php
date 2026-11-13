<?php
/**
 * Checks that a request that says it is Googlebot or Bingbot comes from them, by the address ranges they publish.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Any scraper can put "Googlebot" in its user agent. Google and Microsoft publish the address ranges their crawlers use. A
 * background job (`wp-cron`, once a day) downloads them into one option; the check on a request is a comparison with that
 * list: **no DNS, no network, nothing written** on the way of a request.
 *
 * - `verified`: the address is in a published range of the engine. Served as a known bot.
 * - `failed`: the list is there, the address is public and in none of the engine's ranges. A scraper. Treated as a bad bot.
 * - `unknown`: there is no list yet (first day, or the download keeps failing), or the address is private or reserved.
 *   **Fails open**: the request stays a known bot and is only marked `unverified_bot`.
 *
 * A shop behind a proxy or a CDN sees the proxy's address, which is in no range: it must give the real client address
 * through the filter `socialscore_client_ip`, or switch the check off with `socialscore_verify_declared_bots` (return false).
 */
class Bot_Verifier {

	const VERIFIED = 'verified';
	const FAILED   = 'failed';
	const UNKNOWN  = 'unknown';
	const OPTION   = 'socialscore_bot_ranges';
	const HOOK     = 'socialscore_refresh_bot_ranges';
	const MAX_BODY = 2097152;
	const MAX_CIDR = 20000;

	/**
	 * Declared token => engine.
	 *
	 * @var array<string,string>
	 */
	const TOKENS = array(
		'googlebot'             => 'google',
		'googleother'           => 'google',
		'google-inspectiontool' => 'google',
		'storebot-google'       => 'google',
		'adsbot-google'         => 'google',
		'bingbot'               => 'bing',
	);

	/**
	 * Where the ranges are published, per engine (checked 13.11.2026; Google moved its files from /search/apis/ipranges/ to
	 * /crawling/ipranges/ and the old addresses redirect).
	 *
	 * @var array<string,string[]>
	 */
	const SOURCES = array(
		'google' => array(
			'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
			'https://developers.google.com/static/crawling/ipranges/special-crawlers.json',
			'https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers.json',
			'https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers-google.json',
		),
		'bing'   => array( 'https://www.bing.com/toolbox/bingbot.json' ),
	);

	/**
	 * The engine a user agent claims to be, or an empty string for one this class does not check.
	 *
	 * @param  string $user_agent User agent.
	 * @return string google, bing or empty.
	 */
	public static function engine_for( $user_agent ) {
		$low = strtolower( (string) $user_agent );
		foreach ( self::TOKENS as $token => $engine ) {
			if ( false !== strpos( $low, $token ) ) {
				return $engine;
			}
		}
		return '';
	}

	/**
	 * Verifies an address against the stored ranges of an engine. Reads one option, nothing else.
	 *
	 * @param  string $ip     Client address.
	 * @param  string $engine google or bing.
	 * @return string verified, failed or unknown.
	 */
	public static function verify( $ip, $engine ) {
		if ( ! (bool) apply_filters( 'socialscore_verify_declared_bots', true ) || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return self::UNKNOWN;
		}
		$stored = get_option( self::OPTION, array() );
		$ranges = is_array( $stored ) && isset( $stored[ $engine ] ) && is_array( $stored[ $engine ] ) ? $stored[ $engine ] : array();
		if ( ! $ranges ) {
			return self::UNKNOWN;
		}
		foreach ( $ranges as $cidr ) {
			if ( is_string( $cidr ) && self::in_range( $ip, $cidr ) ) {
				return self::VERIFIED;
			}
		}
		return self::FAILED;
	}

	/**
	 * Whether an address is inside a CIDR range, IPv4 or IPv6.
	 *
	 * @param  string $ip   Address.
	 * @param  string $cidr Range such as 66.249.64.0/19.
	 * @return bool
	 */
	public static function in_range( $ip, $cidr ) {
		$parts = explode( '/', $cidr, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[1] ) ) {
			return false;
		}
		$net  = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed address is no range.
		$addr = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed address is no match.
		if ( false === $net || false === $addr || strlen( $net ) !== strlen( $addr ) ) {
			return false;
		}
		$bits = (int) $parts[1];
		if ( $bits > strlen( $net ) * 8 ) {
			return false;
		}
		$full = intdiv( $bits, 8 );
		if ( substr( $net, 0, $full ) !== substr( $addr, 0, $full ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $net[ $full ] ) & $mask ) === ( ord( $addr[ $full ] ) & $mask );
	}

	/**
	 * The ranges out of a published file: the `ipv4Prefix` and `ipv6Prefix` values, each checked as a range.
	 *
	 * @param  mixed $json Decoded file.
	 * @return string[]
	 */
	public static function parse( $json ) {
		$out = array();
		if ( ! is_array( $json ) || ! isset( $json['prefixes'] ) || ! is_array( $json['prefixes'] ) ) {
			return $out;
		}
		foreach ( $json['prefixes'] as $row ) {
			foreach ( array( 'ipv4Prefix', 'ipv6Prefix' ) as $key ) {
				if ( is_array( $row ) && isset( $row[ $key ] ) && is_string( $row[ $key ] ) && self::valid_cidr( $row[ $key ] ) ) {
					$out[] = $row[ $key ];
				}
			}
			if ( count( $out ) >= self::MAX_CIDR ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Whether a text is a range: an address and a prefix length that fits it.
	 *
	 * @param  string $cidr Text.
	 * @return bool
	 */
	private static function valid_cidr( $cidr ) {
		$parts = explode( '/', $cidr, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[1] ) || false === filter_var( $parts[0], FILTER_VALIDATE_IP ) ) {
			return false;
		}
		return (int) $parts[1] <= ( false !== strpos( $parts[0], ':' ) ? 128 : 32 );
	}

	/**
	 * The daily job: downloads the published ranges into the option. An engine whose download fails keeps the list it had.
	 * Never runs on the way of a visitor's request.
	 *
	 * @return void
	 */
	public static function refresh() {
		if ( ! Connection::is_connected() ) {
			return;
		}
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		foreach ( self::SOURCES as $engine => $urls ) {
			$ranges = array();
			$ok     = true;
			foreach ( $urls as $url ) {
				$answer = wp_remote_get(
					$url,
					array(
						'timeout'             => 15,
						'redirection'         => 3,
						'limit_response_size' => self::MAX_BODY,
					)
				);
				if ( is_wp_error( $answer ) || 200 !== (int) wp_remote_retrieve_response_code( $answer ) ) {
					$ok = false;
					break;
				}
				$parsed = self::parse( json_decode( (string) wp_remote_retrieve_body( $answer ), true ) );
				if ( ! $parsed ) {
					$ok = false;
					break;
				}
				$ranges = array_merge( $ranges, $parsed );
			}
			if ( $ok ) {
				$stored[ $engine ] = array_values( array_unique( $ranges ) );
			}
		}
		$stored['fetched'] = time();
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Makes sure the daily job is scheduled while the store is connected, and runs it soon when there is no list yet.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! Connection::is_connected() || ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		$stored = get_option( self::OPTION, array() );
		wp_schedule_event( time() + ( empty( $stored ) ? 60 : 3600 ), 'daily', self::HOOK );
	}
}
