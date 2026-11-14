<?php
/**
 * Data feed: first import and real-time sync through Action Scheduler.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing here runs in the checkout request: hooks only queue actions.
 */
class Sync {

	const HOOK_ORDER     = 'socialscore_sync_order';
	const NO_SAVE_FILTER = 'woocommerce_orders_table_datastore_should_save_after_meta_change';
	const HOOK_CUSTOMER  = 'socialscore_sync_customer';
	const HOOK_IMPORT    = 'socialscore_import';
	const MAX_ATTEMPTS   = 6;
	const BACKOFF        = array( 60, 300, 1800, 7200, 21600, 43200 );

	/**
	 * While true the queue hooks ignore events caused by our own saves.
	 *
	 * @var bool
	 */
	private static $paused = false;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_new_order', array( $this, 'queue_order' ), 10, 1 );
		add_action( 'woocommerce_update_order', array( $this, 'queue_order' ), 10, 1 );
		add_action( 'woocommerce_created_customer', array( $this, 'queue_new_customer' ), 10, 1 );
		add_action( 'profile_update', array( $this, 'queue_customer' ), 10, 1 );

		add_action( self::HOOK_ORDER, array( $this, 'handle_order' ), 10, 2 );
		add_action( self::HOOK_CUSTOMER, array( $this, 'handle_customer' ), 10, 3 );
		add_action( self::HOOK_IMPORT, array( $this, 'handle_import' ), 10, 3 );
	}

	/**
	 * Ignores sync events until resume().
	 *
	 * @return void
	 */
	public static function pause() {
		self::$paused = true;
	}

	/**
	 * Listens for sync events again.
	 *
	 * @return void
	 */
	public static function resume() {
		self::$paused = false;
	}

	/**
	 * Queues an action unless the same one is already waiting.
	 *
	 * @param string $hook Hook.
	 * @param array  $args Arguments.
	 * @return void
	 */
	public static function queue_unique( $hook, array $args ) {
		if ( self::$paused || ! Connection::is_connected() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, Config::ACTION_GROUP ) ) {
			return;
		}
		as_enqueue_async_action( $hook, $args, Config::ACTION_GROUP );
	}

	/**
	 * Order created or updated.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function queue_order( $order_id ) {
		self::queue_unique( self::HOOK_ORDER, array( (int) $order_id, 0 ) );
	}

	/**
	 * Account created at registration or checkout.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function queue_new_customer( $user_id ) {
		self::queue_unique( self::HOOK_CUSTOMER, array( (int) $user_id, true, 0 ) );
	}

	/**
	 * Profile saved.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function queue_customer( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! in_array( 'customer', (array) $user->roles, true ) ) {
			return; // Admins and shop managers are not customers.
		}
		self::queue_unique( self::HOOK_CUSTOMER, array( (int) $user_id, false, 0 ) );
	}

	/**
	 * Decides what to do with an API answer. Returns true on success. On a retryable failure
	 * (network error, 429, 5xx) it schedules the same action again with backoff (429 honours Retry-After).
	 *
	 * @param array|\WP_Error $response  Answer from Api_Client.
	 * @param string          $hook      Action hook to retry.
	 * @param array           $base_args Arguments without the attempt counter.
	 * @param int             $attempt   Attempts made so far.
	 * @return bool
	 */
	public static function settle( $response, $hook, array $base_args, $attempt ) {
		if ( ! is_wp_error( $response ) && $response['status'] >= 200 && $response['status'] < 300 ) {
			return true;
		}

		$delay = self::retry_delay( $response, (int) $attempt );
		if ( null !== $delay && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, $hook, array_merge( $base_args, array( (int) $attempt + 1 ) ), Config::ACTION_GROUP );
		} else {
			self::log( 'Giving up on ' . $hook . ': ' . ( is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $response['status'] ) );
		}
		return false;
	}

	/**
	 * Seconds to wait before the next attempt, or null when retrying is pointless.
	 *
	 * @param array|\WP_Error $response Answer from Api_Client.
	 * @param int             $attempt  Attempts made so far.
	 * @return int|null
	 */
	public static function retry_delay( $response, $attempt ) {
		if ( $attempt + 1 >= self::MAX_ATTEMPTS ) {
			return null;
		}
		$backoff = self::BACKOFF[ min( $attempt, count( self::BACKOFF ) - 1 ) ];
		if ( is_wp_error( $response ) ) {
			return $backoff;
		}
		$status = $response['status'];
		if ( 429 === $status ) {
			return max( (int) $response['retry_after'], $backoff );
		}
		if ( $status >= 500 ) {
			return $backoff;
		}
		// The first request with this idempotency key is still running: try again in a moment.
		if ( 409 === $status && 'idempotency_in_progress' === self::error_code( $response ) ) {
			return $backoff;
		}
		return null;
	}

	/**
	 * The `detail` code of an API error answer, empty when there is none.
	 *
	 * @param array|\WP_Error $response Answer from Api_Client.
	 * @return string
	 */
	public static function error_code( $response ) {
		$detail = is_array( $response ) && is_array( $response['data'] ?? null ) ? ( $response['data']['detail'] ?? null ) : null;
		if ( is_array( $detail ) ) {
			$detail = $detail['code'] ?? null;
		}
		return is_string( $detail ) ? $detail : '';
	}

	/**
	 * Whether a batch answer set any row aside. Used for single-row pushes, where it means that row failed.
	 *
	 * @param array|\WP_Error $response Answer from Api_Client.
	 * @return bool
	 */
	public static function has_rejected( $response ) {
		return ! is_wp_error( $response ) && is_array( $response['data'] ?? null ) && ! empty( $response['data']['rejected'] ) && is_array( $response['data']['rejected'] );
	}

	/**
	 * Logs the items of a batch answer the API set aside (index, id and reason only, no personal data).
	 *
	 * @param array|\WP_Error $response Answer from Api_Client.
	 * @return void
	 */
	public static function log_rejected( $response ) {
		if ( is_wp_error( $response ) || ! is_array( $response['data'] ?? null ) || empty( $response['data']['rejected'] ) || ! is_array( $response['data']['rejected'] ) ) {
			return;
		}
		foreach ( $response['data']['rejected'] as $item ) {
			if ( is_array( $item ) ) {
				self::log( sprintf( 'API rejected item %s (index %s): %s', (string) ( $item['external_id'] ?? '?' ), (string) ( $item['index'] ?? '?' ), (string) ( $item['reason'] ?? '?' ) ) );
			}
		}
	}

	/**
	 * Writes to the WooCommerce log.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'socialscore' ) );
		}
	}

	/**
	 * Keeps the SocialScore id of every order a batch stored, in the order's meta. An `order.created` event
	 * carries that id, not the shop's order id, so this is how the shop finds its own order again.
	 *
	 * @param array|\WP_Error $response Answer of orders:batch.
	 * @return void
	 */
	public static function record_order_ids( $response ) {
		if ( is_wp_error( $response ) || $response['status'] < 200 || $response['status'] > 299 || ! is_array( $response['data'] ?? null ) || ! is_array( $response['data']['ids'] ?? null ) ) {
			return;
		}
		foreach ( $response['data']['ids'] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['external_id'], $row['id'] ) || ! ctype_digit( (string) $row['external_id'] ) || (int) $row['id'] < 1 ) {
				continue;
			}
			$order = wc_get_order( (int) $row['external_id'] );
			if ( ! $order || (int) $order->get_meta( Config::META_ORDER_ID ) === (int) $row['id'] ) {
				continue;
			}
			self::write_order_id( $order, (int) $row['id'] );
		}
	}

	/**
	 * Writes the SocialScore id straight through the order's data store. $order->save() (and save_meta_data(), which
	 * fires the same event) would stamp date_modified and run woocommerce_update_order, and with it every webhook and
	 * listener of other plugins, once per historical order of an import. With HPOS the data store saves the order
	 * after a meta write too (WooCommerce 8.8+), unless this filter says no, so the filter is held back for this write only.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $id    SocialScore order id.
	 * @return void
	 */
	private static function write_order_id( $order, $id ) {
		$store = $order->get_data_store();
		add_filter( self::NO_SAVE_FILTER, '__return_false', PHP_INT_MAX );
		self::store_order_id( $store, $order, $id );
		remove_filter( self::NO_SAVE_FILTER, '__return_false', PHP_INT_MAX );
	}

	/**
	 * Updates or adds the id meta row through the data store.
	 *
	 * @param object    $store Order data store.
	 * @param \WC_Order $order Order.
	 * @param int       $id    SocialScore order id.
	 * @return void
	 */
	private static function store_order_id( $store, $order, $id ) {
		foreach ( $order->get_meta_data() as $meta ) {
			if ( Config::META_ORDER_ID === $meta->key ) {
				$store->update_meta(
					$order,
					(object) array(
						'id'    => $meta->id,
						'key'   => Config::META_ORDER_ID,
						'value' => $id,
					)
				);
				return;
			}
		}
		$store->add_meta(
			$order,
			(object) array(
				'key'   => Config::META_ORDER_ID,
				'value' => $id,
			)
		);
	}

	/**
	 * The shop order for a SocialScore order id (the `order_id` of an `order.created` event).
	 *
	 * @param int $socialscore_id Id from the event.
	 * @return \WC_Order|null
	 */
	public static function order_for_socialscore_id( $socialscore_id ) {
		$orders = wc_get_orders(
			array(
				'meta_key'   => Config::META_ORDER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => (string) (int) $socialscore_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'limit'      => 1,
			)
		);
		return $orders ? reset( $orders ) : null;
	}

	/**
	 * Sends rows in batches of at most 500; stops at the first failure.
	 *
	 * @param callable      $push     Takes one chunk, returns an API answer.
	 * @param array         $rows     Rows.
	 * @param callable|null $on_batch Called with each successful answer.
	 * @return array|\WP_Error|null Last answer, null when there was nothing to send.
	 */
	public static function push_in_batches( $push, array $rows, $on_batch = null ) {
		$result = null;
		foreach ( array_chunk( $rows, Config::BATCH_SIZE ) as $chunk ) {
			$result = $push( $chunk );
			if ( is_wp_error( $result ) || $result['status'] < 200 || $result['status'] > 299 ) {
				return $result;
			}
			self::log_rejected( $result );
			if ( $on_batch ) {
				$on_batch( $result );
			}
		}
		return $result;
	}

	/**
	 * Real-time sync of one order, with its customer first.
	 *
	 * @param int $order_id Order id.
	 * @param int $attempt  Attempts made so far.
	 * @return void
	 */
	public function handle_order( $order_id, $attempt = 0 ) {
		if ( ! Connection::is_connected() ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order || ! ( $order instanceof \WC_Order ) || in_array( $order->get_status(), array( 'auto-draft', 'checkout-draft', 'trash' ), true ) ) {
			return;
		}

		$client   = new Api_Client();
		$customer = Mapper::customer_from_order( $order );
		$result   = null;
		if ( $customer ) {
			$result = self::push_in_batches( array( $client, 'push_customers' ), array( $customer ) );
		}
		if ( null === $result || ( ! is_wp_error( $result ) && $result['status'] < 300 ) ) {
			$result = $client->push_orders( array( Mapper::order( $order ) ) );
			self::log_rejected( $result );
			self::record_order_ids( $result );
		}

		// A 200 batch answer can still set this order aside (for example customer_not_found after its customer
		// was rejected). The order is not in SocialScore then, so a check for it would only 404.
		if ( self::settle( $result, self::HOOK_ORDER, array( (int) $order_id ), (int) $attempt ) && ! self::has_rejected( $result ) ) {
			Enrichment::after_order_sync( $order );
			Not_Human::after_order_sync( $order );
		}
	}

	/**
	 * Real-time sync of one customer account.
	 *
	 * @param int  $user_id Account id.
	 * @param bool $created True when the account was just created.
	 * @param int  $attempt Attempts made so far.
	 * @return void
	 */
	public function handle_customer( $user_id, $created = false, $attempt = 0 ) {
		if ( ! Connection::is_connected() ) {
			return;
		}
		$customer = new \WC_Customer( (int) $user_id );
		if ( ! $customer->get_id() ) {
			return;
		}
		$result = ( new Api_Client() )->push_customers( array( Mapper::customer( $customer ) ) );
		self::log_rejected( $result );
		if ( self::settle( $result, self::HOOK_CUSTOMER, array( (int) $user_id, (bool) $created ), (int) $attempt ) && ! self::has_rejected( $result ) ) {
			Enrichment::after_customer_sync( (int) $user_id, (bool) $created );
		}
	}

	/**
	 * Starts the background import of existing customers and orders.
	 *
	 * @return void
	 */
	public static function start_import() {
		update_option(
			Config::OPTION_SYNC,
			array(
				'status'     => 'running',
				'customers'  => 0,
				'orders'     => 0,
				'started_at' => time(),
			),
			false
		);
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_IMPORT, array( 'customers', 1, 0 ), Config::ACTION_GROUP );
		}
	}

	/**
	 * One page of the import.
	 *
	 * @param string $phase   customers or orders.
	 * @param int    $page    Page number from 1.
	 * @param int    $attempt Attempts made so far.
	 * @return void
	 */
	public function handle_import( $phase, $page, $attempt = 0 ) {
		if ( ! Connection::is_connected() ) {
			return;
		}
		$client = new Api_Client();
		$page   = max( 1, (int) $page );
		$state  = get_option( Config::OPTION_SYNC, array() );
		$state  = is_array( $state ) ? $state : array();

		if ( 'customers' === $phase ) {
			$ids  = get_users(
				array(
					'role'    => 'customer',
					'number'  => Config::BATCH_SIZE,
					'paged'   => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'fields'  => 'ID',
				)
			);
			$rows = array();
			foreach ( $ids as $id ) {
				$rows[] = Mapper::customer( new \WC_Customer( (int) $id ) );
			}
			$result    = self::push_in_batches( array( $client, 'push_customers' ), $rows );
			$count     = count( $ids );
			$count_key = 'customers';
		} else {
			$orders = wc_get_orders(
				array(
					'type'    => 'shop_order',
					'status'  => self::importable_statuses(),
					'limit'   => Config::BATCH_SIZE,
					'paged'   => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
			$result = self::push_in_batches( array( $client, 'push_customers' ), Mapper::customers_from_orders( $orders ) );
			if ( null === $result || ( ! is_wp_error( $result ) && $result['status'] < 300 ) ) {
				$rows   = array_map( array( Mapper::class, 'order' ), $orders );
				$result = self::push_in_batches( array( $client, 'push_orders' ), $rows, array( __CLASS__, 'record_order_ids' ) );
			}
			$count     = count( $orders );
			$count_key = 'orders';
		}

		if ( ! self::settle( $result, self::HOOK_IMPORT, array( $phase, $page ), (int) $attempt ) ) {
			if ( null === self::retry_delay( $result, (int) $attempt ) ) {
				$state['status'] = 'failed';
				update_option( Config::OPTION_SYNC, $state, false );
			}
			return;
		}

		$state[ $count_key ] = ( isset( $state[ $count_key ] ) ? (int) $state[ $count_key ] : 0 ) + $count;
		if ( Config::BATCH_SIZE === $count ) {
			$next = array( $phase, $page + 1, 0 );
		} elseif ( 'customers' === $phase ) {
			$next = array( 'orders', 1, 0 );
		} else {
			$next            = null;
			$state['status'] = 'done';
		}
		update_option( Config::OPTION_SYNC, $state, false );
		if ( $next && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_IMPORT, $next, Config::ACTION_GROUP );
		}
	}

	/**
	 * Order statuses the import reads. WooCommerce Blocks adds `checkout-draft` (abandoned block checkouts)
	 * to wc_get_order_statuses(), and the default query returns it too, so it is removed here.
	 *
	 * @return string[]
	 */
	public static function importable_statuses() {
		return array_values( array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft', 'wc-auto-draft' ) ) );
	}

	/**
	 * Removes every queued action of the plugin.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), Config::ACTION_GROUP );
		}
	}
}
