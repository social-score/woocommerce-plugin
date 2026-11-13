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
 * list the API filter uses.
 *
 * It fails closed. An object is delimited by counting braces; when the braces do not balance within a generous limit, the
 * rule is applied to the whole rest of the page instead. And nothing is written into the page to say it was cleaned: whether
 * a price is already a substitute is known from the registry in Bot_Guard (server side), never from the text, which may come
 * from a review or a product description.
 */
class Page_Cleaner {

	// Between a key and its value: entities, escapes and punctuation, no letters, digits or braces (a brace is a new object).
	// Each token is atomic, so `%22`, `"` and `&#34;` are never split into punctuation and a number.
	const GAP = '(?:(?>&[a-z#0-9x]+;|%[0-9a-f]{2}|\\\\u[0-9a-f]{4}|[^a-z0-9{}])){1,40}?';
	// How far an object is followed to its closing brace.
	const OBJECT_LIMIT = 200000;

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
	 * The most that can be added: in every `add_to_cart` and `quantity_limits` object, however long, a maximum below the
	 * no-limit value becomes the no-limit value. If an object's braces do not balance, every maximum in the rest of the
	 * page is lifted.
	 *
	 * @param  string $html Page.
	 * @return string
	 */
	private static function lift_limits( $html ) {
		$parents = implode( '|', array_map( 'preg_quote', Bot_Guard::LIMIT_PARENTS ) );
		$found   = preg_match_all( '/(?<![a-z_])(?:' . $parents . ')' . self::GAP . '\{/i', $html, $matches, PREG_OFFSET_CAPTURE );
		if ( ! $found ) {
			return $html;
		}
		$out = '';
		$at  = 0;
		foreach ( $matches[0] as $m ) {
			$open = $m[1] + strlen( $m[0] ) - 1;
			if ( $open < $at ) {
				continue;
			}
			$end = self::object_end( $html, $open );
			if ( null === $end ) {
				return $out . substr( $html, $at, $open - $at ) . self::lift( substr( $html, $open ) );
			}
			$out .= substr( $html, $at, $open - $at ) . self::lift( substr( $html, $open, $end - $open ) );
			$at   = $end;
		}
		return $out . substr( $html, $at );
	}

	/**
	 * Lifts every maximum in a piece of text.
	 *
	 * @param  string $piece Text.
	 * @return string
	 */
	private static function lift( $piece ) {
		return (string) preg_replace_callback(
			'/((?<![a-z_])' . preg_quote( Bot_Guard::LIMIT_KEY, '/' ) . self::GAP . ')(\d+)/i',
			static function ( $m ) {
				return (int) $m[2] < Bot_Guard::NO_LIMIT ? $m[1] . Bot_Guard::NO_LIMIT : $m[0];
			},
			$piece
		);
	}

	/**
	 * Every `prices` object: each price in it is replaced with the substitute of the product whose `id` was written last
	 * before it, unless the registry says the page already holds that substitute. The end of an object is found by counting
	 * braces; when they do not balance, everything from the object to the end of the page is treated as the object.
	 *
	 * @param  string $html Page.
	 * @return string
	 */
	private static function substitute_prices( $html ) {
		$found = preg_match_all(
			'/(?<![a-z_])id' . self::GAP . '(\d+)|(?<![a-z_])prices' . self::GAP . '\{/i',
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
			$open = $start + strlen( $m[0][0] ) - 1; // The brace.
			$end  = self::object_end( $html, $open );
			if ( null === $end ) {
				return $out . substr( $html, $at, $open - $at ) . self::replace_prices( substr( $html, $open ), $last );
			}
			$out .= substr( $html, $at, $open - $at ) . self::replace_prices( substr( $html, $open, $end - $open ), $last );
			$at   = $end;
		}
		return $out . substr( $html, $at );
	}

	/**
	 * Where the object that opens at `$open` ends (the position after its closing brace), or null when its braces do not
	 * balance within the limit.
	 *
	 * @param  string $html Page.
	 * @param  int    $open Position of the opening brace.
	 * @return int|null
	 */
	private static function object_end( $html, $open ) {
		$depth = 0;
		$limit = min( strlen( $html ), $open + self::OBJECT_LIMIT );
		for ( $i = $open; $i < $limit; $i++ ) {
			$char = $html[ $i ];
			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char && 0 === --$depth ) {
				return $i + 1;
			}
		}
		return null;
	}

	/**
	 * Replaces the number after every price key in a piece of text, unless it is already a substitute of this request.
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
				if ( Bot_Guard::is_shown( $id, $m[2] ) ) {
					return $m[0];
				}
				$shown = (int) round( Bot_Guard::substitute_price( (int) $m[2], $id ) );
				Bot_Guard::note_shown( $id, (int) $m[2], $shown );
				return $m[1] . $shown;
			},
			$piece
		);
	}
}
