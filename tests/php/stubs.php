<?php
// Minimal stand-ins for WordPress/WooCommerce classes the plugin touches. The real ones are exercised by the container smoke.
namespace {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_data() {
			return $this->data;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
	class WP_Post {
		public $ID = 0;
	}
	class WP_REST_Response {
		public $data;
		public $status;
		public $headers = array();
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function header( $key, $value ) {
			$this->headers[ $key ] = $value;
		}
		public function get_data() {
			return $this->data;
		}
		public function get_status() {
			return $this->status;
		}
	}
	class WP_REST_Request implements ArrayAccess {
		public $method = 'GET';
		public $params = array();
		public $query  = array();
		public $json   = null;
		public $hdrs   = array();
		public function get_method() {
			return $this->method;
		}
		public function get_query_params() {
			return $this->query;
		}
		public function get_json_params() {
			return $this->json;
		}
		public function get_header( $name ) {
			return $this->hdrs[ $name ] ?? null;
		}
		#[\ReturnTypeWillChange]
		public function offsetExists( $k ) {
			return isset( $this->params[ $k ] );
		}
		#[\ReturnTypeWillChange]
		public function offsetGet( $k ) {
			return $this->params[ $k ] ?? null;
		}
		#[\ReturnTypeWillChange]
		public function offsetSet( $k, $v ) {
			$this->params[ $k ] = $v;
		}
		#[\ReturnTypeWillChange]
		public function offsetUnset( $k ) {
			unset( $this->params[ $k ] );
		}
	}
	class WC_DateTime {
		private $ts;
		public function __construct( $ts ) {
			$this->ts = $ts;
		}
		public function getTimestamp() {
			return $this->ts;
		}
	}
	class WC_Order_Item_Product {
		public $d;
		public function __construct( array $d ) {
			$this->d = $d;
		}
		public function get_product_id() {
			return $this->d['product_id'];
		}
		public function get_name() {
			return $this->d['name'];
		}
		public function get_quantity() {
			return $this->d['quantity'];
		}
		public function get_total() {
			return $this->d['total'];
		}
	}
	/** Data store fake: records meta rows written without a save. */
	class WC_Order_Data_Store_Fake {
		public $added   = 0;
		public $updated = 0;
		public function add_meta( $order, $meta ) {
			++$this->added;
			$order->meta[ $meta->key ] = $meta->value;
		}
		public function update_meta( $order, $meta ) {
			++$this->updated;
			$order->meta[ $meta->key ] = $meta->value;
		}
	}
	/** Order fake: every getter reads from the $d array. */
	class WC_Order {
		public $d;
		public $meta  = array();
		public $notes = array();
		public $saves = 0;
		public $meta_saves = 0;
		public $store;
		public function __construct( array $d = array() ) {
			$this->d = $d + array(
				'id' => 1, 'customer_id' => 0, 'billing_email' => '', 'billing_phone' => '', 'billing_first_name' => '',
				'billing_last_name' => '', 'billing_city' => '', 'billing_postcode' => '', 'billing_country' => '',
				'shipping_city' => '', 'shipping_postcode' => '', 'shipping_country' => '', 'total' => 0, 'currency' => 'EUR',
				'created' => 1700000000, 'items' => array(), 'status' => 'processing',
			);
		}
		public function __call( $name, $args ) {
			if ( 0 === strpos( $name, 'get_' ) ) {
				$key = substr( $name, 4 );
				if ( 'date_created' === $key ) {
					return $this->d['created'] ? new WC_DateTime( $this->d['created'] ) : null;
				}
				if ( 'items' === $key ) {
					return array_map(
						static function ( $i ) {
							return new WC_Order_Item_Product( $i );
						},
						$this->d['items']
					);
				}
				return $this->d[ $key ];
			}
			throw new BadMethodCallException( $name );
		}
		public function get_data_store() {
			$this->store = $this->store ?? new WC_Order_Data_Store_Fake();
			return $this->store;
		}
		public function get_meta_data() {
			$rows = array();
			foreach ( $this->meta as $k => $v ) {
				$rows[] = (object) array( 'id' => 100 + count( $rows ), 'key' => $k, 'value' => $v );
			}
			return $rows;
		}
		public function get_meta( $k ) {
			return $this->meta[ $k ] ?? '';
		}
		public function update_meta_data( $k, $v ) {
			$this->meta[ $k ] = $v;
		}
		public function add_order_note( $n ) {
			$this->notes[] = $n;
		}
		public function save() {
			++$this->saves;
		}
		public function delete_meta_data( $k ) {
			unset( $this->meta[ $k ] );
		}
		public function save_meta_data() {
			++$this->meta_saves;
		}
	}
	class WC_Customer {
		public $d;
		public function __construct( $id_or_data = 0 ) {
			$this->d = is_array( $id_or_data ) ? $id_or_data : ( $GLOBALS['ss_customers'][ $id_or_data ] ?? array( 'id' => 0 ) );
		}
		public function __call( $name, $args ) {
			$key = substr( $name, 4 );
			return $this->d[ $key ] ?? '';
		}
		public function get_last_order() {
			return $this->d['last_order'] ?? false;
		}
	}
	class WooCommerce {}
}

namespace Automattic\WooCommerce\Utilities {
	class FeaturesUtil {
		public static $declared = array();
		public static function declare_compatibility( $feature, $file, $positive ) {
			self::$declared[] = array( $feature, $file, $positive );
		}
	}
	class OrderUtil {
		public static $hpos = false;
		public static function custom_orders_table_usage_is_enabled() {
			return self::$hpos;
		}
	}
}
