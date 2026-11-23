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
 * Which files count: each token is matched only against the file of the crawler it names. Googlebot, GoogleOther,
 * Google-InspectionTool and Storebot-Google against `common-crawlers.json`; AdsBot-Google and Mediapartners-Google against
 * `special-crawlers.json`; Bingbot against `bingbot.json`. The user-triggered fetchers files (Apps Script, Site Verifier and
 * others fetch from there for any user, with any user agent) are NOT downloaded and make nobody a verified bot.
 *
 * The address checked is the client's, not a proxy's: see Bot_Guard::client_ip() and Bot_Settings. Cloudflare's published ranges,
 * which this job also keeps, are only used to recognise its addresses; they are trusted only when the shop says it is behind it.
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
		'googlebot'             => 'google_common',
		'googleother'           => 'google_common',
		'google-inspectiontool' => 'google_common',
		'storebot-google'       => 'google_common',
		'adsbot-google'         => 'google_special',
		'mediapartners-google'  => 'google_special',
		'bingbot'               => 'bing',
	);

	/**
	 * Where the ranges are published, per engine (checked 13.11.2026; Google moved its files from /search/apis/ipranges/ to
	 * /crawling/ipranges/ and the old addresses redirect).
	 *
	 * @var array<string,string[]>
	 */
	const SOURCES = array(
		'google_common'  => array( 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json' ),
		'google_special' => array( 'https://developers.google.com/static/crawling/ipranges/special-crawlers.json' ),
		'bing'           => array( 'https://www.bing.com/toolbox/bingbot.json' ),
		// Not an engine: the ranges of Cloudflare (plain text, one range a line), to tell its address from a client's.
		'cloudflare'     => array( 'https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6' ), // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Two lists of addresses read by the server, not content loaded into a page.
	);

	/**
	 * The engine a user agent claims to be, or an empty string for one this class does not check.
	 *
	 * @param  string $user_agent User agent.
	 * @return string google_common, google_special, bing or empty.
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
	 * @param  string $engine google_common, google_special or bing.
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
	 * Whether an address belongs to Cloudflare, by its published ranges. False when the list is not there.
	 *
	 * @param  string $ip Address.
	 * @return bool
	 */
	public static function is_cloudflare( $ip ) {
		$stored = get_option( self::OPTION, array() );
		$ranges = is_array( $stored ) && isset( $stored['cloudflare'] ) && is_array( $stored['cloudflare'] ) ? $stored['cloudflare'] : array();
		foreach ( $ranges as $cidr ) {
			if ( is_string( $cidr ) && self::in_range( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
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
		if ( is_string( $json ) ) { // A plain list, one range a line (Cloudflare).
			foreach ( preg_split( '/\s+/', trim( $json ) ) as $line ) {
				if ( self::is_cidr( $line ) ) {
					$out[] = $line;
				}
			}
			return array_slice( $out, 0, self::MAX_CIDR );
		}
		if ( ! is_array( $json ) || ! isset( $json['prefixes'] ) || ! is_array( $json['prefixes'] ) ) {
			return $out;
		}
		foreach ( $json['prefixes'] as $row ) {
			foreach ( array( 'ipv4Prefix', 'ipv6Prefix' ) as $key ) {
				if ( is_array( $row ) && isset( $row[ $key ] ) && is_string( $row[ $key ] ) && self::is_cidr( $row[ $key ] ) ) {
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
	public static function is_cidr( $cidr ) {
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
						'user-agent'          => 'SocialScore/' . SOCIALSCORE_VERSION, // WordPress would add this site's address.
						'redirection'         => 3,
						'limit_response_size' => self::MAX_BODY,
					)
				);
				if ( is_wp_error( $answer ) || 200 !== (int) wp_remote_retrieve_response_code( $answer ) ) {
					$ok = false;
					break;
				}
				$body   = (string) wp_remote_retrieve_body( $answer );
				$parsed = self::parse( 'cloudflare' === $engine ? $body : json_decode( $body, true ) );
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
