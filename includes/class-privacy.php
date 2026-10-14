<?php
/**
 * WordPress personal data exporter and eraser (Tools > Export / Erase Personal Data).
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Forwards a data request or an erasure request for one email address to the per-customer privacy routes
 * of the SocialScore API. A store that is not connected cannot be asked; the eraser says so, because data sent earlier may still be held.
 */
class Privacy {

	/**
	 * Registers the exporter and the eraser.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Adds our exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['socialscore'] = array(
			'exporter_friendly_name' => __( 'SocialScore', 'socialscore' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Adds our eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['socialscore'] = array(
			'eraser_friendly_name' => __( 'SocialScore', 'socialscore' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * The ids this plugin sent for an email address: the account id, and the id of the guest with that email.
	 *
	 * @param string $email Email address.
	 * @return string[]
	 */
	public static function external_ids( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email ) {
			return array();
		}
		$ids  = array();
		$user = get_user_by( 'email', $email );
		if ( $user && ! empty( $user->ID ) ) {
			$ids[] = Mapper::customer_external_id( (int) $user->ID, $email );
		}
		$ids[] = Mapper::customer_external_id( 0, $email );
		return $ids;
	}

	/**
	 * Exporter callback: what SocialScore holds for the email address.
	 *
	 * @param string $email_address Email address of the data subject.
	 * @param int    $page          Page, always 1 (everything comes in one answer).
	 * @return array{data:array,done:bool}
	 */
	public function export( $email_address, $page = 1 ) {
		unset( $page );
		$out = array();
		if ( Connection::is_connected() ) {
			$client = new Api_Client();
			foreach ( self::external_ids( $email_address ) as $external_id ) {
				$response = $client->export_customer( $external_id );
				if ( is_wp_error( $response ) || 200 !== $response['status'] || ! is_array( $response['data'] ) ) {
					continue; // 404 customer_not_found: SocialScore holds nothing under this id.
				}
				$out = array_merge( $out, self::export_groups( $external_id, $response['data'] ) );
			}
		}
		return array(
			'data' => $out,
			'done' => true,
		);
	}

	/**
	 * Export groups for one customer answer.
	 *
	 * @param string $external_id Our id of the customer.
	 * @param array  $data        Answer of the export route.
	 * @return array
	 */
	public static function export_groups( $external_id, array $data ) {
		$groups = array();

		$customer = isset( $data['customer'] ) && is_array( $data['customer'] ) ? $data['customer'] : array();
		$fields   = array(
			'email'          => __( 'Email', 'socialscore' ),
			'phone'          => __( 'Phone', 'socialscore' ),
			'first_name'     => __( 'First name', 'socialscore' ),
			'last_name'      => __( 'Last name', 'socialscore' ),
			'city'           => __( 'City', 'socialscore' ),
			'postcode'       => __( 'Postcode', 'socialscore' ),
			'country'        => __( 'Country', 'socialscore' ),
			'orders_count'   => __( 'Orders', 'socialscore' ),
			'total_spent'    => __( 'Total spent', 'socialscore' ),
			'currency'       => __( 'Currency', 'socialscore' ),
			'first_order_at' => __( 'First order', 'socialscore' ),
			'last_order_at'  => __( 'Last order', 'socialscore' ),
		);
		$rows     = array();
		foreach ( $fields as $key => $label ) {
			if ( isset( $customer[ $key ] ) && is_scalar( $customer[ $key ] ) && '' !== (string) $customer[ $key ] ) {
				$rows[] = array(
					'name'  => $label,
					'value' => (string) $customer[ $key ],
				);
			}
		}
		$groups[] = array(
			'group_id'          => 'socialscore-customer',
			'group_label'       => __( 'SocialScore customer', 'socialscore' ),
			'group_description' => __( 'What SocialScore holds for this customer.', 'socialscore' ),
			'item_id'           => 'socialscore-customer-' . $external_id,
			'data'              => $rows,
		);

		$orders = isset( $data['orders'] ) && is_array( $data['orders'] ) ? $data['orders'] : array();
		foreach ( $orders as $order ) {
			if ( ! is_array( $order ) ) {
				continue;
			}
			$lines = array();
			foreach ( (array) ( $order['items'] ?? array() ) as $item ) {
				if ( is_array( $item ) ) {
					$lines[] = (string) ( $item['quantity'] ?? 1 ) . ' x ' . (string) ( $item['name'] ?? $item['product_id'] ?? '' );
				}
			}
			$rows = array();
			foreach ( array(
				'external_id'       => __( 'Order', 'socialscore' ),
				'total'             => __( 'Total', 'socialscore' ),
				'currency'          => __( 'Currency', 'socialscore' ),
				'placed_at'         => __( 'Placed', 'socialscore' ),
				'shipping_city'     => __( 'Shipping city', 'socialscore' ),
				'shipping_postcode' => __( 'Shipping postcode', 'socialscore' ),
				'shipping_country'  => __( 'Shipping country', 'socialscore' ),
				'labels'            => __( 'Check labels', 'socialscore' ),
			) as $key => $label ) {
				if ( 'labels' === $key && is_array( $order[ $key ] ?? null ) ) {
					$labels = array_filter( array_filter( $order[ $key ], 'is_scalar' ), 'strlen' );
					if ( $labels ) {
						$rows[] = array(
							'name'  => $label,
							'value' => implode( ', ', array_map( 'strval', $labels ) ),
						);
					}
				} elseif ( isset( $order[ $key ] ) && is_scalar( $order[ $key ] ) && '' !== (string) $order[ $key ] ) {
					$rows[] = array(
						'name'  => $label,
						'value' => (string) $order[ $key ],
					);
				}
			}
			if ( $lines ) {
				$rows[] = array(
					'name'  => __( 'Items', 'socialscore' ),
					'value' => implode( ', ', $lines ),
				);
			}
			$groups[] = array(
				'group_id'          => 'socialscore-orders',
				'group_label'       => __( 'SocialScore orders', 'socialscore' ),
				'group_description' => __( 'Orders SocialScore holds for this customer.', 'socialscore' ),
				'item_id'           => 'socialscore-order-' . (string) ( $order['external_id'] ?? '' ),
				'data'              => $rows,
			);
		}

		$profiles = array();
		if ( ! empty( $data['profile'] ) ) {
			$profiles[] = $data['profile'];
		}
		foreach ( (array) ( $data['earlier_profiles'] ?? array() ) as $earlier ) {
			$profiles[] = $earlier;
		}
		foreach ( $profiles as $index => $profile ) {
			$groups[] = array(
				'group_id'          => 'socialscore-profiles',
				'group_label'       => __( 'SocialScore checks', 'socialscore' ),
				'group_description' => __( 'The profiles built by Checks of this customer.', 'socialscore' ),
				'item_id'           => 'socialscore-profile-' . $external_id . '-' . $index,
				'data'              => array(
					array(
						'name'  => __( 'Profile', 'socialscore' ),
						'value' => (string) wp_json_encode( $profile ),
					),
				),
			);
		}

		return $groups;
	}

	/**
	 * Eraser callback: redacts the customer at SocialScore.
	 *
	 * @param string $email_address Email address of the data subject.
	 * @param int    $page          Page, always 1.
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( $email_address, $page = 1 ) {
		unset( $page );
		$removed  = false;
		$retained = false;
		$messages = array();

		if ( Connection::is_connected() ) {
			$client = new Api_Client();
			foreach ( self::external_ids( $email_address ) as $external_id ) {
				$response = $client->redact_customer( $external_id );
				if ( ! is_wp_error( $response ) && $response['status'] >= 200 && $response['status'] < 300 ) {
					// customers_redacted is 0 when the store had no such customer: nothing was removed.
					if ( is_array( $response['data'] ) && (int) ( $response['data']['customers_redacted'] ?? 0 ) > 0 ) {
						$removed = true;
					}
					continue;
				}
				// A 401 means SocialScore no longer serves this store key (uninstalled); the data is then
				// removed through the shop redact, not here.
				$retained   = true;
				$messages[] = __( 'SocialScore could not be asked to erase this customer. Run the erasure again later.', 'socialscore' );
			}
		} else {
			// Disconnecting keeps the data at SocialScore until a redact, so "not connected" is not "nothing held".
			$retained   = true;
			$messages[] = __( 'This store is not connected to SocialScore, so the customer could not be erased there. SocialScore may still hold data from before the store was disconnected; connect the store again and rerun the erasure, or contact SocialScore support.', 'socialscore' );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => array_values( array_unique( $messages ) ),
			'done'           => true,
		);
	}
}
