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
 * here looks for a quote: a key is found as a word, and the value as the first number after it across any run of
 * punctuation or entities. The keys come from the one list in Bot_Guard (NULL_KEYS, LIMIT_PARENTS, PRICE_KEYS), the same
 * list the API filter uses. It fails closed: a number that follows a key is always replaced, whatever the shape around it.
 */
class Page_Cleaner {

	// Between a key and its value: entities, escapes and punctuation, no letters, digits or braces (a brace is a new object).
	// Each token is atomic, so `%22`, `\\u0022` and `&#34;` are never split into punctuation and a number.
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
	 * The most that can be added: below the no-limit value it becomes the no-limit value.
	 *
	 * @param  string $html Page.
	 * @return string
	 */
	private static function lift_limits( $html ) {
		$parents = implode( '|', array_map( 'preg_quote', Bot_Guard::LIMIT_PARENTS ) );
		return (string) preg_replace_callback(
			'/((?<![a-z_])(?:' . $parents . ')(?:(?!\}).){0,400}?(?<![a-z_])' . preg_quote( Bot_Guard::LIMIT_KEY, '/' ) . self::GAP . ')(\d+)/is',
			static function ( $m ) {
				return (int) $m[2] < Bot_Guard::NO_LIMIT ? $m[1] . Bot_Guard::NO_LIMIT : $m[0];
			},
			$html
		);
	}

	/**
	 * Every `prices` object: each price in it is replaced with the substitute of the product whose `id` was written last
	 * before it, and the object is marked as shown, so a second pass leaves it alone. The end of an object is found by
	 * counting braces, so a nested range or an odd shape does not hide a price.
	 *
	 * @param  string $html Page.
	 * @return string
	 */
	private static function substitute_prices( $html ) {
		$found = preg_match_all(
			'/(?<![a-z_])id' . self::GAP . '(\d+)|(?<![a-z_])prices(' . self::GAP . ')\{/i',
			$html,
			$matches,
			PREG_OFFSET_CAPTURE | PREG_SET_ORDER
		);
		if ( ! $found ) {
			return $html;
		}
		$out  = '';
		$at   = 0;
		$last = 'page';
		foreach ( $matches as $m ) {
			$start = $m[0][1];
			if ( $start < $at ) {
				continue; // Inside an object already handled.
			}
			if ( isset( $m[1] ) && '' !== $m[1][0] ) {
				$last = $m[1][0];
				continue;
			}
			$open  = $start + strlen( $m[0][0] ) - 1; // The brace.
			$end   = self::object_end( $html, $open );
			$piece = substr( $html, $open, $end - $open );
			$out  .= substr( $html, $at, $open - $at );
			$at    = $end;
			if ( false !== strpos( $piece, Bot_Guard::SHOWN_FLAG ) ) {
				$out .= $piece;
				continue;
			}
			$out .= self::flag( self::replace_prices( $piece, $last ), isset( $m[2] ) ? $m[2][0] : '' );
		}
		return $out . substr( $html, $at );
	}

	/**
	 * Where the object that opens at `$open` ends (the position after its closing brace); a long or unbalanced one ends at a
	 * limit, so what follows is still cleaned.
	 *
	 * @param  string $html Page.
	 * @param  int    $open Position of the opening brace.
	 * @return int
	 */
	private static function object_end( $html, $open ) {
		$depth = 0;
		$limit = min( strlen( $html ), $open + 6000 );
		for ( $i = $open; $i < $limit; $i++ ) {
			$char = $html[ $i ];
			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char && 0 === --$depth ) {
				return $i + 1;
			}
		}
		return $limit;
	}

	/**
	 * Replaces the number after every price key in a piece of text.
	 *
	 * @param  string $piece Text.
	 * @param  string $id    Product id.
	 * @return string
	 */
	private static function replace_prices( $piece, $id ) {
		$keys = implode( '|', array_map( 'preg_quote', Bot_Guard::PRICE_KEYS ) );
		return (string) preg_replace_callback(
			'/((?<![a-z_])(?:' . $keys . ')' . self::GAP . ')(\d+)/i',
			static function ( $m ) use ( $id ) {
				return $m[1] . (int) round( Bot_Guard::substitute_price( (int) $m[2], $id ) );
			},
			$piece
		);
	}

	/**
	 * Writes the shown mark into the object, in the quoting the object itself uses.
	 *
	 * @param  string $piece Object text, starting with its brace.
	 * @param  string $gap   The text between the key `prices` and the brace.
	 * @return string
	 */
	private static function flag( $piece, $gap ) {
		if ( 1 !== preg_match( '/^(.*?)(?::|&#0*58;|&colon;|%3A)/is', $gap, $m ) ) {
			return $piece;
		}
		$quote = ltrim( $m[1] ) === $m[1] ? $m[1] : ltrim( $m[1] );
		return '{' . $quote . Bot_Guard::SHOWN_FLAG . $quote . ':true,' . substr( $piece, 1 );
	}
}
