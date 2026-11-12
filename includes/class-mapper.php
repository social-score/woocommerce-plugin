<?php
/**
 * Maps WooCommerce objects to the public API v2 batch rows.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Pure mapping, no I/O.
 */
class Mapper {

	/**
	 * Our id of a customer: the user id, or a hash of the email for guests. The guest id has only
	 * letters, digits and a dash so it survives URL encoding unchanged.
	 *
	 * @param int    $user_id  User id, 0 for guests.
	 * @param string $email    Billing email.
	 * @param int    $order_id Order id; a guest without email is then identified by the order.
	 * @return string Empty when the customer cannot be identified.
	 */
	public static function customer_external_id( $user_id, $email, $order_id = 0 ) {
		if ( (int) $user_id > 0 ) {
			return (string) (int) $user_id;
		}
		$email = strtolower( trim( (string) $email ) );
		if ( '' !== $email ) {
			return 'guest-' . sha1( $email );
		}
		return (int) $order_id > 0 ? 'guest-order-' . (int) $order_id : '';
	}

	/**
	 * ISO 8601 UTC time of a WC_DateTime or null.
	 *
	 * @param mixed $date WC_DateTime or null.
	 * @return string|null
	 */
	private static function iso( $date ) {
		return ( $date && method_exists( $date, 'getTimestamp' ) ) ? gmdate( 'c', $date->getTimestamp() ) : null;
	}

	const MAX_ORDER_LINES = 200;

	/**
	 * Shortens a value to the length the API accepts; it answers 422 for the whole batch otherwise.
	 *
	 * @param mixed $value Value.
	 * @param int   $limit Maximum characters.
	 * @return string
	 */
	private static function cut( $value, $limit ) {
		$value = (string) $value;
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit, 'UTF-8' ) : substr( $value, 0, $limit );
	}

	/**
	 * Two-letter country or empty (the API accepts nothing else).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function country( $value ) {
		return 2 === strlen( (string) $value ) ? (string) $value : '';
	}

	/**
	 * Three-letter currency or empty.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function currency( $value ) {
		return 3 === strlen( (string) $value ) ? (string) $value : '';
	}

	/**
	 * Removes empty values so the API only sees what we know.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function compact_row( array $row ) {
		return array_filter(
			$row,
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);
	}

	/**
	 * Row for customers:batch from a registered customer.
	 *
	 * @param \WC_Customer $customer Customer.
	 * @return array
	 */
	public static function customer( $customer ) {
		$last = $customer->get_last_order();
		return self::compact_row(
			array(
				'external_id'   => self::customer_external_id( $customer->get_id(), $customer->get_email() ),
				'email'         => self::cut( trim( $customer->get_email() ), 320 ),
				'phone'         => self::cut( $customer->get_billing_phone(), 64 ),
				'first_name'    => self::cut( $customer->get_first_name(), 256 ),
				'last_name'     => self::cut( $customer->get_last_name(), 256 ),
				'city'          => self::cut( $customer->get_billing_city(), 256 ),
				'postcode'      => self::cut( $customer->get_billing_postcode(), 32 ),
				'country'       => self::country( $customer->get_billing_country() ),
				'orders_count'  => (int) $customer->get_order_count(),
				'total_spent'   => (float) $customer->get_total_spent(),
				'currency'      => self::currency( get_woocommerce_currency() ),
				'last_order_at' => $last ? self::iso( $last->get_date_created() ) : null,
			)
		);
	}

	/**
	 * Row for customers:batch built from an order's billing data (guests and fallback).
	 *
	 * @param \WC_Order $order Order.
	 * @return array Empty when the order cannot be identified.
	 */
	public static function customer_from_order( $order ) {
		$external_id = self::customer_external_id( $order->get_customer_id(), $order->get_billing_email(), $order->get_id() );
		if ( '' === $external_id ) {
			return array();
		}
		return self::compact_row(
			array(
				'external_id' => $external_id,
				'email'       => self::cut( trim( $order->get_billing_email() ), 320 ),
				'phone'       => self::cut( $order->get_billing_phone(), 64 ),
				'first_name'  => self::cut( $order->get_billing_first_name(), 256 ),
				'last_name'   => self::cut( $order->get_billing_last_name(), 256 ),
				'city'        => self::cut( $order->get_billing_city(), 256 ),
				'postcode'    => self::cut( $order->get_billing_postcode(), 32 ),
				'country'     => self::country( $order->get_billing_country() ),
				'currency'    => self::currency( $order->get_currency() ),
			)
		);
	}

	/**
	 * Row for orders:batch. Items carry product data only, no personal data. The visit ids are the Pixel cookies the
	 * server stored when the order was made; a value that is not a UUIDv4 is left out.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	public static function order( $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( count( $items ) >= self::MAX_ORDER_LINES ) {
				break; // The API refuses a whole batch for an order with more lines.
			}
			$quantity = max( 1, (int) $item->get_quantity() );
			$items[]  = array(
				'product_id' => self::cut( $item->get_product_id(), 128 ),
				'name'       => self::cut( $item->get_name(), 512 ),
				'quantity'   => $quantity,
				'price'      => round( (float) $item->get_total() / $quantity, 4 ),
			);
		}

		$ship_city     = $order->get_shipping_city();
		$ship_postcode = $order->get_shipping_postcode();
		$ship_country  = $order->get_shipping_country();
		$ship_city     = '' !== $ship_city ? $ship_city : $order->get_billing_city();
		$ship_postcode = '' !== $ship_postcode ? $ship_postcode : $order->get_billing_postcode();
		$ship_country  = '' !== $ship_country ? $ship_country : $order->get_billing_country();

		return self::compact_row(
			array(
				'external_id'          => (string) $order->get_id(),
				'customer_external_id' => self::customer_external_id( $order->get_customer_id(), $order->get_billing_email(), $order->get_id() ),
				'total'                => (float) $order->get_total(),
				'currency'             => self::currency( $order->get_currency() ),
				'placed_at'            => self::iso( $order->get_date_created() ),
				'items'                => $items,
				'shipping_city'        => self::cut( $ship_city, 256 ),
				'shipping_postcode'    => self::cut( $ship_postcode, 32 ),
				'shipping_country'     => self::country( $ship_country ),
			)
		);
	}

	/**
	 * Unique customer rows for a list of orders.
	 *
	 * @param \WC_Order[] $orders Orders.
	 * @return array
	 */
	public static function customers_from_orders( array $orders ) {
		$rows = array();
		foreach ( $orders as $order ) {
			$row = self::customer_from_order( $order );
			if ( $row && ! isset( $rows[ $row['external_id'] ] ) ) {
				$rows[ $row['external_id'] ] = $row;
			}
		}
		return array_values( $rows );
	}
}
