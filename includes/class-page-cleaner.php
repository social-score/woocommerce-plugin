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
 * - in substitute mode, every number after a price key that is a REAL price of a product this request showed (the registry in
 *   Bot_Guard, filled on the server from the products themselves) is replaced with the substitute of that product. A number
 *   that is not a real price is left alone: a substitute already put there is not one, so a second pass changes nothing, and
 *   what the text of the page says (an `id` in a review, a figure in a description) decides nothing about which product a
 *   price belongs to or whether it is replaced.
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
	 * Replaces the real prices after a price key. The id written last before a price is only a hint for choosing between
	 * products that share a price.
	 *
	 * @param  string $html Page.
	 * @return string
	 */
	private static function substitute_prices( $html ) {
		$keys = implode( '|', array_map( 'preg_quote', Bot_Guard::PRICE_KEYS ) );
		$last = null;
		return (string) preg_replace_callback(
			'/(?<![a-z_])id' . self::GAP . '(\d+)|((?<![a-z_])(?:' . $keys . ')' . self::GAP . ')(\d+(?:\.\d+)?)/i',
			static function ( $m ) use ( &$last ) {
				if ( ! isset( $m[3] ) || '' === $m[3] ) {
					$last = $m[1];
					return $m[0];
				}
				$shown = Bot_Guard::shown_value( $m[3], $last );
				return null === $shown ? $m[0] : $m[2] . $shown;
			},
			$html
		);
	}
}
