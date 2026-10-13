<?php
namespace SocialScore\Tests\Unit;

use SocialScore\Mapper;
use SocialScore\Tests\TestCase;

class MapperTest extends TestCase {

	private function order( array $over = array() ) {
		return new \WC_Order(
			$over + array(
				'id' => 1001, 'customer_id' => 0, 'billing_email' => ' Guest@Example.com ', 'billing_phone' => '+359888', 'billing_first_name' => 'Ana',
				'billing_last_name' => 'Ivanova', 'billing_city' => 'Sofia', 'billing_postcode' => '1000', 'billing_country' => 'BG',
				'total' => '120.50', 'currency' => 'EUR', 'created' => 1700000000,
				'items' => array( array( 'product_id' => 7, 'name' => 'Mug', 'quantity' => 2, 'total' => '40.00' ) ),
			)
		);
	}

	public function test_guest_external_id_is_hash_of_normalized_email() {
		$this->assertSame( 'guest-' . sha1( 'guest@example.com' ), Mapper::customer_external_id( 0, ' Guest@Example.com ' ) );
		$this->assertSame( '', Mapper::customer_external_id( 0, '' ) );
		$this->assertSame( '15', Mapper::customer_external_id( 15, 'x@y.z' ) );
	}

	public function test_guest_ids_survive_url_encoding() {
		$id = Mapper::customer_external_id( 0, 'a@b.bg' );
		$this->assertSame( $id, rawurlencode( $id ) );
		$this->assertSame( 'guest-order-77', Mapper::customer_external_id( 0, '', 77 ) );
	}

	public function test_order_without_account_and_email_still_names_a_customer() {
		$order = $this->order( array( 'billing_email' => '' ) );
		$row   = Mapper::order( $order );
		$this->assertSame( 'guest-order-1001', $row['customer_external_id'] );
		$customer = Mapper::customer_from_order( $order );
		$this->assertSame( 'guest-order-1001', $customer['external_id'] );
	}

	public function test_order_row() {
		$row = Mapper::order( $this->order() );
		$this->assertSame( '1001', $row['external_id'] );
		$this->assertSame( 'guest-' . sha1( 'guest@example.com' ), $row['customer_external_id'] );
		$this->assertSame( 120.5, $row['total'] );
		$this->assertSame( 'EUR', $row['currency'] );
		$this->assertSame( '2023-11-14T22:13:20+00:00', $row['placed_at'] );
		$this->assertSame( array( array( 'product_id' => '7', 'name' => 'Mug', 'quantity' => 2, 'price' => 20.0 ) ), $row['items'] );
		$this->assertSame( 'Sofia', $row['shipping_city'], 'billing is the fallback for shipping' );
		$this->assertSame( 'BG', $row['shipping_country'] );
	}

	public function test_values_are_cut_to_what_the_api_accepts() {
		$items = array();
		for ( $i = 1; $i <= 205; $i++ ) {
			$items[] = array( 'product_id' => $i, 'name' => str_repeat( 'ж', 600 ), 'quantity' => 1, 'total' => 5 );
		}
		$order = $this->order(
			array(
				'items'            => $items,
				'billing_postcode' => str_repeat( '1', 40 ),
				'billing_phone'    => str_repeat( '7', 80 ),
				'shipping_country' => 'Bulgaria',
				'currency'         => 'EU',
			)
		);
		$row = Mapper::order( $order );
		$this->assertCount( 200, $row['items'] );
		$this->assertSame( 512, mb_strlen( $row['items'][0]['name'], 'UTF-8' ) );
		$this->assertSame( 32, strlen( $row['shipping_postcode'] ) );
		$this->assertArrayNotHasKey( 'shipping_country', $row );
		$this->assertArrayNotHasKey( 'currency', $row );
		$customer = Mapper::customer_from_order( $order );
		$this->assertSame( 32, strlen( $customer['postcode'] ) );
		$this->assertSame( 64, strlen( $customer['phone'] ) );
	}

	public function test_order_items_hold_no_personal_data() {
		$json = json_encode( Mapper::order( $this->order() )['items'] );
		$this->assertStringNotContainsString( 'example.com', $json );
		$this->assertStringNotContainsString( 'Ana', $json );
	}

	public function test_customer_from_order_and_unknown_customer() {
		$row = Mapper::customer_from_order( $this->order() );
		$this->assertSame( 'Guest@Example.com', $row['email'] );
		$this->assertSame( 'Ana', $row['first_name'] );
		$this->assertSame( array(), Mapper::customer_from_order( $this->order( array( 'billing_email' => '', 'id' => 0 ) ) ) );
	}

	public function test_customers_from_orders_are_unique() {
		$rows = Mapper::customers_from_orders( array( $this->order(), $this->order( array( 'id' => 1002 ) ), $this->order( array( 'billing_email' => 'b@b.bg' ) ) ) );
		$this->assertCount( 2, $rows );
	}

	public function test_registered_customer_row_has_counts_and_drops_empty_fields() {
		$customer = new \WC_Customer(
			array( 'id' => 15, 'email' => 'c@example.com', 'billing_phone' => '', 'first_name' => 'Cleo', 'last_name' => '', 'billing_city' => 'Plovdiv',
				'billing_postcode' => '4000', 'billing_country' => 'BG', 'order_count' => 3, 'total_spent' => '99.90', 'last_order' => new \WC_Order( array( 'created' => 1700000000 ) ) )
		);
		$row = Mapper::customer( $customer );
		$this->assertSame( '15', $row['external_id'] );
		$this->assertSame( 3, $row['orders_count'] );
		$this->assertSame( 99.9, $row['total_spent'] );
		$this->assertSame( '2023-11-14T22:13:20+00:00', $row['last_order_at'] );
		$this->assertArrayNotHasKey( 'phone', $row );
		$this->assertArrayNotHasKey( 'last_name', $row );
	}
}
