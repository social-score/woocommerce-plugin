<?php
/**
 * Daily counts of the bots the server saw, for the module's reports page.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Counts requests, not visitors: without a cookie or a stored id there is nothing to tell one bot from another across
 * requests. Only a count per day and class is kept, never an address or a user agent. A lost update under two requests
 * at the same moment is accepted, the figure is for a trend.
 */
class Bot_Stats {


	const OPTION    = 'socialscore_bot_hits';
	const KEEP_DAYS = 31;

	/**
	 * Adds one request of a class to today.
	 *
	 * @param  string $kind Class from Bot_Detector.
	 * @return void
	 */
	public static function record( $kind ) {
		if ( ! Bot_Detector::is_not_human( $kind ) ) {
			return;
		}
		$days = get_option( self::OPTION, array() );
		$days = is_array( $days ) ? $days : array();
		$day  = gmdate( 'Y-m-d' );

		$days[ $day ][ $kind ] = ( isset( $days[ $day ][ $kind ] ) ? (int) $days[ $day ][ $kind ] : 0 ) + 1;
		krsort( $days );
		$days = array_slice( $days, 0, self::KEEP_DAYS, true );
		update_option( self::OPTION, $days, false );
	}

	/**
	 * Totals of the last days, per class.
	 *
	 * @param  int $days How many days back, today included.
	 * @return array<string,int> Class => requests.
	 */
	public static function totals( $days ) {
		$all    = get_option( self::OPTION, array() );
		$all    = is_array( $all ) ? $all : array();
		$from   = gmdate( 'Y-m-d', time() - ( max( 1, (int) $days ) - 1 ) * 86400 );
		$totals = array(
			Bot_Detector::BAD_BOT  => 0,
			Bot_Detector::AI_AGENT => 0,
			Bot_Detector::INFO_BOT => 0,
		);
		foreach ( $all as $day => $classes ) {
			if ( ! is_array( $classes ) || (string) $day < $from ) {
				continue;
			}
			foreach ( $totals as $class => $n ) {
				$totals[ $class ] = $n + ( isset( $classes[ $class ] ) ? (int) $classes[ $class ] : 0 );
			}
		}
		return $totals;
	}
}
