<?php
/**
 * Auto-enrichment: runs Check on the events the store owner chose.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * The server decides about charging and refuses on Free; the plugin only triggers.
 */
class Enrichment {

	const HOOK_CHECK = 'socialscore_run_check';
	const MAX_AGE    = 172800; // Orders older than two days are not enriched on update.

	/**
	 * Registers the action handler.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK_CHECK, array( $this, 'handle_check' ), 10, 3 );
	}

	/**
	 * Trigger settings from the server, cached for five minutes. Null when unknown, so no check runs.
	 *
	 * @return array|null
	 */
	public static function trigger_settings() {
		$cached = get_transient( Config::TRANSIENT_CONF );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = ( new Api_Client() )->get_settings();
		if ( is_wp_error( $response ) || 200 !== $response['status'] || ! is_array( $response['data'] ) || ! isset( $response['data']['settings'] ) || ! is_array( $response['data']['settings'] ) ) {
			return null;
		}
		$s        = $response['data']['settings'];
		$settings = array(
			'on_new_account'     => ! empty( $s['on_new_account'] ),
			'every_account'      => ! empty( $s['every_account'] ),
			'order_new_customer' => ! empty( $s['order_new_customer'] ),
			'order_any_customer' => ! empty( $s['order_any_customer'] ),
			'manual_only'        => ! empty( $s['manual_only'] ),
		);
		set_transient( Config::TRANSIENT_CONF, $settings, 300 );
		return $settings;
	}

	/**
	 * Queues a check for a fresh order when the settings ask for it.
	 *
	 * @param \WC_Order $order Order that was just synced.
	 * @return void
	 */
	public static function after_order_sync( $order ) {
		if ( $order->get_meta( Config::META_CHECKED ) ) {
			return;
		}
		$created = $order->get_date_created();
		if ( ! $created || ( time() - $created->getTimestamp() ) > self::MAX_AGE ) {
			return;
		}
		$settings = self::trigger_settings();
		if ( ! $settings || $settings['manual_only'] ) {
			return;
		}
		if ( $settings['order_any_customer'] || ( $settings['order_new_customer'] && self::is_new_customer( $order ) ) ) {
			Sync::queue_unique( self::HOOK_CHECK, array( 'order', (string) $order->get_id(), 0 ) );
		}
	}

	/**
	 * Queues a check for an account when the settings ask for it.
	 *
	 * @param int  $user_id Account id.
	 * @param bool $created True for a just-created account.
	 * @return void
	 */
	public static function after_customer_sync( $user_id, $created ) {
		if ( get_user_meta( $user_id, Config::META_CHECKED, true ) ) {
			return;
		}
		$settings = self::trigger_settings();
		if ( ! $settings || $settings['manual_only'] ) {
			return;
		}
		if ( $settings['every_account'] || ( $created && $settings['on_new_account'] ) ) {
			Sync::queue_unique( self::HOOK_CHECK, array( 'customer', (string) $user_id, 0 ) );
		}
	}

	/**
	 * True when this is the only order with the billing email.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_new_customer( $order ) {
		$ids = wc_get_orders(
			array(
				'billing_email' => $order->get_billing_email(),
				'limit'         => 2,
				'return'        => 'ids',
			)
		);
		return count( $ids ) <= 1;
	}

	/**
	 * Idempotency key: one per store and subject, so retries never charge twice.
	 *
	 * @param string $kind        order or customer.
	 * @param string $external_id Our id.
	 * @return string
	 */
	public static function idempotency_key( $kind, $external_id ) {
		return 'wc:' . Connection::store_id() . ':' . $kind . ':' . $external_id;
	}

	/**
	 * Runs one check.
	 *
	 * @param string $kind        order or customer.
	 * @param string $external_id Our id.
	 * @param int    $attempt     Attempts made so far.
	 * @return void
	 */
	public function handle_check( $kind, $external_id, $attempt = 0 ) {
		if ( ! Connection::is_connected() ) {
			return;
		}
		$response = ( new Api_Client() )->run_check( $kind, $external_id, self::idempotency_key( $kind, $external_id ) );

		if ( Sync::settle( $response, self::HOOK_CHECK, array( $kind, (string) $external_id ), (int) $attempt ) ) {
			Labels::apply_from_check( $kind, $external_id, $response['data'] );
			return;
		}
		// 402 plan_required (Free plan): remember it so updates do not ask again.
		if ( ! is_wp_error( $response ) && 402 === $response['status'] ) {
			if ( 'order' === $kind ) {
				$order = wc_get_order( (int) $external_id );
				if ( $order ) {
					$order->update_meta_data( Config::META_CHECKED, 'plan_required' );
					Sync::pause();
					$order->save();
					Sync::resume();
				}
			} else {
				update_user_meta( (int) $external_id, Config::META_CHECKED, 'plan_required' );
			}
		}
	}
}
