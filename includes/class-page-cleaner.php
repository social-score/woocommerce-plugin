<?php
/**
 * The second line against product data in the page of a block theme: a bad bot's page is cleaned on its way out.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Blocks prints Store API product data into the page (script tags, block attributes, the Interactivity API's
 * state and context). The first line is the REST filter in Bot_Guard, which reaches what Blocks asks the API for. What is
 * built without a REST request, or whatever else lands in the HTML, is caught here.
 *
 * JSON in HTML comes in many dresses: plain quotes, `\"`, `\\"`, `&quot;`, `&#34;`, `&#x22;`, `%22`, `"`. So no pattern
 * here looks for a quote, or a brace: a key is found as a word, and the value as the first number after it across any run of
 * punctuation or entities. The rules are the widest ones, applied to the whole page, because the page is a bad bot's:
 *
 * - every `low_stock_remaining`, `stock_quantity` and `quantity_in_stock` with a number gets `null`;
 * - every `maximum` with a number below the no-limit value gets the no-limit value (any other `maximum` in the page too: the
 *   price of that is a slider that stops at 9999);
 * - in substitute mode, every number after a price key is made `0`, unless the data level (the REST filter, the price HTML, the
 *   JSON-LD, the variation data, the Pixel events: Bot_Guard) produced it for the product whose `id` stands before it. The
 *   cleaner never scales a number: text from a review or a description, which a bot can write, is only removed, so there is
 *   no factor to measure. A number the data level produced is kept, a second pass changes nothing; a number that is also a
 *   real number of that product (a promotion that equals the regular price's substitute) is not kept, as nothing can tell the
 *   two apart.
 */
class Page_Cleaner {

	// Between a key and its value: entities, escapes and punctuation, no letters, digits or braces. Each token is atomic, so
	// `%22`, `"` and `&#34;` are never split into punctuation and a number.
	const GAP = '(?:(?>&[a-z#0-9x]+;|%[0-9a-f]{2}|\\\\u[0-9a-f]{4}|[^a-z0-9{}])){1,40}?';

	/**
	 * Cleans a page.
	 *
	 * @param  string $html       Page.
	 * @param  bool   $substitute Whether to replace prices (substitute mode).
	 * @return string
	 */
	public static function clean( $html, $substitute ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		$html = self::null_quantities( $html );
		$html = self::lift_limits( $html );
		return $substitute ? self::substitute_prices( $html ) : $html;
	}

	/**
	 * The remaining stock and the like: whatever number follows the key becomes `null`.
	 *
	 * @param  string $html Page.
	 * @return string
	 */
	private static function null_quantities( $html ) {
		$keys = implode( '|', array_map( 'preg_quote', Bot_Guard::NULL_KEYS ) );
		return (string) preg_replace( '/((?<![a-z_])(?:' . $keys . ')' . self::GAP . ')-?\d+(?:\.\d+)?/i', '${1}null', $html );
	}

	/**
	 * Every `maximum` with a number below the no-limit value gets the no-limit value, wherever it stands in the page.
	 *
	 * @param  string $html Page.
	 * @return string
	 */
	private static function lift_limits( $html ) {
		return (string) preg_replace_callback(
			'/((?<![a-z_])' . preg_quote( Bot_Guard::LIMIT_KEY, '/' ) . self::GAP . ')(\d+)/i',
			static function ( $m ) {
				return (int) $m[2] < Bot_Guard::NO_LIMIT ? $m[1] . Bot_Guard::NO_LIMIT : $m[0];
			},
			$html
		);
	}

	/**
	 * The safety net for prices. Every number after a price key is made `0`, unless the data level produced it for the
	 * product whose `id` stands before it (and it was never also a real number of that product). Nothing here ever scales a
	 * number, so what the page's own text says (a review with a price and an id) can only be removed, and nobody can
	 * measure a factor from it.
	 *
	 * @param  string $html Page.
	 * @return string
	 */
	private static function substitute_prices( $html ) {
		$keys = implode( '|', array_map( 'preg_quote', Bot_Guard::PRICE_KEYS ) );
		$last = null;
		return (string) preg_replace_callback(
			'/(?<![a-z_])(?:variation_)?id' . self::GAP . '(\d+)|((?<![a-z_])(?:' . $keys . ')' . self::GAP . ')(\d+(?:\.\d+)?)/i',
			static function ( $m ) use ( &$last ) {
				if ( ! isset( $m[3] ) || '' === $m[3] ) {
					$last = $m[1];
					return $m[0];
				}
				$replacement = Bot_Guard::page_value( $m[3], $last );
				return null === $replacement ? $m[0] : $m[2] . $replacement;
			},
			$html
		);
	}
}
