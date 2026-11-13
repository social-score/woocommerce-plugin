<?php
/**
 * Where the shop's visitors really come from: the proxy settings that decide whose address the Googlebot check looks at.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * A shop behind a proxy or a CDN sees the proxy's address, and the client's address only in a header the proxy writes. A
 * header is the sender's word: from an address the shop does not trust, it is ignored. The shop says which proxies it trusts:
 *
 * - `behind_cloudflare`: Cloudflare's published ranges are trusted and its `CF-Connecting-IP` is the client. Off by default.
 *   It is safe only when the server accepts requests from Cloudflare alone (a firewall, or Authenticated Origin Pulls): else
 *   anyone can send requests through a Cloudflare zone of their own to the shop and write that header.
 * - `trusted_proxies`: a list of ranges (addresses or CIDR); from them the client is the right-most address in `header` (by
 *   default `X-Forwarded-For`) that is not itself a trusted proxy.
 *
 * The filters `socialscore_behind_cloudflare` and `socialscore_trusted_proxies` override the stored values.
 */
class Bot_Settings {

	const OPTION         = 'socialscore_bot_settings';
	const HINTS          = 'socialscore_proxy_hints';
	const DEFAULT_HEADER = 'X-Forwarded-For';
	const MAX_PROXIES    = 100;

	/**
	 * The settings now.
	 *
	 * @return array{behind_cloudflare:bool,trusted_proxies:string[],header:string}
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$cf     = ! empty( $stored['behind_cloudflare'] );
		$list   = isset( $stored['trusted_proxies'] ) && is_array( $stored['trusted_proxies'] ) ? $stored['trusted_proxies'] : array();
		$header = isset( $stored['header'] ) && is_string( $stored['header'] ) ? $stored['header'] : self::DEFAULT_HEADER;

		$list = apply_filters( 'socialscore_trusted_proxies', $list );
		return array(
			'behind_cloudflare' => (bool) apply_filters( 'socialscore_behind_cloudflare', $cf ),
			'trusted_proxies'   => self::ranges( is_array( $list ) ? $list : array() ),
			'header'            => 1 === preg_match( '/^[A-Za-z0-9-]{1,40}$/D', $header ) ? $header : self::DEFAULT_HEADER,
		);
	}

	/**
	 * Valid ranges out of a list or a text: an address is a range of one.
	 *
	 * @param  string[]|string $input List, or text split at spaces, commas and lines.
	 * @return string[]
	 */
	public static function ranges( $input ) {
		$items = is_array( $input ) ? $input : preg_split( '/[\s,;]+/', (string) $input );
		$out   = array();
		foreach ( (array) $items as $item ) {
			$item = is_string( $item ) ? trim( $item ) : '';
			if ( '' === $item ) {
				continue;
			}
			if ( false === strpos( $item, '/' ) && false !== filter_var( $item, FILTER_VALIDATE_IP ) ) {
				$item .= false !== strpos( $item, ':' ) ? '/128' : '/32';
			}
			if ( Bot_Verifier::is_cidr( $item ) ) {
				$out[ $item ] = $item;
			}
			if ( count( $out ) >= self::MAX_PROXIES ) {
				break;
			}
		}
		return array_values( $out );
	}

	/**
	 * Saves what the merchant typed.
	 *
	 * @param  bool   $behind_cloudflare The checkbox.
	 * @param  string $proxies           The ranges, as text.
	 * @param  string $header            The header name.
	 * @return void
	 */
	public static function save( $behind_cloudflare, $proxies, $header ) {
		update_option(
			self::OPTION,
			array(
				'behind_cloudflare' => (bool) $behind_cloudflare,
				'trusted_proxies'   => self::ranges( $proxies ),
				'header'            => 1 === preg_match( '/^[A-Za-z0-9-]{1,40}$/D', trim( (string) $header ) ) ? trim( (string) $header ) : self::DEFAULT_HEADER,
			),
			false
		);
	}

	/**
	 * Notes that a declared search engine came through what looks like a proxy the shop has not described: the merchant
	 * is told on the reports page, before the search engines are turned away.
	 *
	 * @param  string $kind cloudflare or proxy.
	 * @return void
	 */
	public static function hint( $kind ) {
		$hints = get_option( self::HINTS, array() );
		$hints = is_array( $hints ) ? $hints : array();
		$kind  = 'cloudflare' === $kind ? 'cloudflare' : 'proxy';

		$hints[ $kind ] = array(
			'count' => ( isset( $hints[ $kind ]['count'] ) ? (int) $hints[ $kind ]['count'] : 0 ) + 1,
			'last'  => time(),
		);
		update_option( self::HINTS, $hints, false );
	}

	/**
	 * The notes: kind => array( count, last ).
	 *
	 * @return array
	 */
	public static function hints() {
		$hints = get_option( self::HINTS, array() );
		return is_array( $hints ) ? $hints : array();
	}
}
