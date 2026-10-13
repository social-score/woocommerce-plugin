<?php
/**
 * Risk and business-buyer labels from Check results.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Stores labels in order/user meta (HPOS safe) and writes order notes.
 */
class Labels {

	const REVIEW   = 'review_before_shipping';
	const BUSINESS = 'possible_business_buyer';

	/**
	 * Label keys the plugin understands.
	 *
	 * @return string[]
	 */
	public static function known() {
		return array( self::REVIEW, self::BUSINESS );
	}

	/**
	 * Human text of a label.
	 *
	 * @param string $label Label key.
	 * @return string
	 */
	public static function text( $label ) {
		switch ( $label ) {
			case self::REVIEW:
				return __( 'Review before shipping', 'socialscore' );
			case self::BUSINESS:
				return __( 'Possible business buyer', 'socialscore' );
		}
		return '';
	}

	/**
	 * Keeps only known labels, without duplicates.
	 *
	 * @param mixed $labels Labels from the API.
	 * @return string[]
	 */
	public static function normalize( $labels ) {
		if ( ! is_array( $labels ) ) {
			return array();
		}
		return array_values( array_unique( array_intersect( array_map( 'strval', $labels ), self::known() ) ) );
	}

	/**
	 * Labels saved on an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return string[]
	 */
	public static function for_order( $order ) {
		return self::normalize( $order->get_meta( Config::META_LABELS ) );
	}

	/**
	 * Saves labels on an order and adds a note for each new one.
	 *
	 * @param \WC_Order $order  Order.
	 * @param array     $labels Labels from the check response.
	 * @return void
	 */
	public static function apply_to_order( $order, $labels ) {
		$labels   = self::normalize( $labels );
		$previous = self::for_order( $order );

		foreach ( array_diff( $labels, $previous ) as $label ) {
			/* translators: %s: label such as "Review before shipping". */
			$order->add_order_note( sprintf( __( 'SocialScore: %s', 'socialscore' ), self::text( $label ) ) );
		}

		$order->update_meta_data( Config::META_LABELS, $labels );
		$order->update_meta_data( Config::META_CHECKED, 'done' );

		Sync::pause();
		$order->save();
		Sync::resume();
	}

	/**
	 * Applies a check response to the order or customer it was run for.
	 *
	 * @param string $kind        order or customer.
	 * @param string $external_id Our id.
	 * @param mixed  $data        Decoded response body.
	 * @return void
	 */
	public static function apply_from_check( $kind, $external_id, $data ) {
		$labels = ( is_array( $data ) && isset( $data['labels'] ) ) ? $data['labels'] : array();
		if ( 'order' === $kind ) {
			$order = wc_get_order( (int) $external_id );
			if ( $order ) {
				self::apply_to_order( $order, $labels );
			}
			return;
		}
		if ( ctype_digit( (string) $external_id ) ) {
			update_user_meta( (int) $external_id, Config::META_LABELS, self::normalize( $labels ) );
			update_user_meta( (int) $external_id, Config::META_CHECKED, 'done' );
		}
	}
}
