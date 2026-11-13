<?php
/**
 * Checks that a request that says it is Googlebot or Bingbot comes from them, by reverse and forward DNS.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Any scraper can put "Googlebot" in its user agent. The search engines document the check: the address resolves back
 * to a host name under their domain, and that host name resolves to the same address.
 *
 * - `verified`: both lookups agree. Served as a known bot.
 * - `failed`: the address has a consistent host name (reverse, then forward to the same address) that is not the engine's.
 *   A scraper on a cloud host. Treated as a bad bot.
 * - `unknown`: no answer, no host name, an address that is private or reserved, or a host name that does not resolve back
 *   (a proxy or a CDN in front of the shop shows its own address). **Fails open**: the request stays a known bot and is
 *   only marked `unverified_bot`.
 *
 * The answer is kept 24 hours per address and engine (a transient), so a crawl costs the lookups once a day per address.
 * A shop behind a proxy gives the real client address through the filter `socialscore_client_ip`.
 */
class Bot_Verifier {

	const VERIFIED  = 'verified';
	const FAILED    = 'failed';
	const UNKNOWN   = 'unknown';
	const TTL       = 86400;
	const TRANSIENT = 'socialscore_bot_dns_';

	/**
	 * Declared token => the domains the engine's host names end in.
	 *
	 * @var array<string,string[]>
	 */
	const ENGINES = array(
		'googlebot'             => array( 'googlebot.com', 'google.com' ),
		'googleother'           => array( 'googlebot.com', 'google.com' ),
		'google-inspectiontool' => array( 'googlebot.com', 'google.com' ),
		'storebot-google'       => array( 'googlebot.com', 'google.com' ),
		'adsbot-google'         => array( 'googlebot.com', 'google.com' ),
		'bingbot'               => array( 'search.msn.com' ),
	);

	/**
	 * Replacement for the two lookups, for tests: callable( 'ptr'|'forward', string ) => string|string[]|null.
	 *
	 * @var callable|null
	 */
	private static $resolver = null;

	/**
	 * Replaces the DNS lookups (tests only).
	 *
	 * @param callable|null $resolver Resolver, or null for the real DNS.
	 * @return void
	 */
	public static function use_resolver( $resolver ) {
		self::$resolver = $resolver;
	}

	/**
	 * The domains of the engine a user agent claims to be, or an empty list when it is not an engine this class checks.
	 *
	 * @param string $user_agent User agent.
	 * @return string[]
	 */
	public static function domains_for( $user_agent ) {
		$low = strtolower( (string) $user_agent );
		foreach ( self::ENGINES as $token => $domains ) {
			if ( false !== strpos( $low, $token ) ) {
				return $domains;
			}
		}
		return array();
	}

	/**
	 * Verifies an address against the domains of an engine.
	 *
	 * @param string   $ip      Client address.
	 * @param string[] $domains Domains of the engine.
	 * @return string verified, failed or unknown.
	 */
	public static function verify( $ip, array $domains ) {
		if ( ! $domains || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return self::UNKNOWN;
		}
		$key    = self::TRANSIENT . md5( $ip . '|' . implode( ',', $domains ) );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && in_array( $cached, array( self::VERIFIED, self::FAILED, self::UNKNOWN ), true ) ) {
			return $cached;
		}
		$result = self::lookup( $ip, $domains );
		set_transient( $key, $result, self::TTL );
		return $result;
	}

	/**
	 * The two lookups.
	 *
	 * @param string   $ip      Address.
	 * @param string[] $domains Domains.
	 * @return string
	 */
	private static function lookup( $ip, array $domains ) {
		$host = self::resolve( 'ptr', $ip );
		if ( ! is_string( $host ) || '' === $host || $host === $ip ) {
			return self::UNKNOWN;
		}
		$host = strtolower( rtrim( $host, '.' ) );
		$back = self::resolve( 'forward', $host );
		$back = is_array( $back ) ? $back : array();
		$wire = inet_pton( $ip );
		$same = false;
		foreach ( $back as $address ) {
			if ( is_string( $address ) && false !== $wire && @inet_pton( $address ) === $wire ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed answer is not an address.
				$same = true;
				break;
			}
		}
		if ( ! $same ) {
			return self::UNKNOWN;
		}
		foreach ( $domains as $domain ) {
			if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
				return self::VERIFIED;
			}
		}
		return self::FAILED;
	}

	/**
	 * One lookup, through the replacement when there is one.
	 *
	 * @param string $type  ptr or forward.
	 * @param string $value Address or host name.
	 * @return string|string[]|null
	 */
	private static function resolve( $type, $value ) {
		if ( null !== self::$resolver ) {
			return call_user_func( self::$resolver, $type, $value );
		}
		if ( 'ptr' === $type ) {
			$host = gethostbyaddr( $value );
			return false === $host ? null : $host;
		}
		$records = dns_get_record( $value, DNS_A | DNS_AAAA );
		$out     = array();
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			if ( isset( $record['ip'] ) ) {
				$out[] = $record['ip'];
			} elseif ( isset( $record['ipv6'] ) ) {
				$out[] = $record['ipv6'];
			}
		}
		return $out;
	}
}
