<?php
// Pixel: loader on the storefront, switch through the proxy, shop events, and the order to visit link
// for the classic and the block (Store API) checkout. Runs while the store is still connected as st_1.
use SocialScore\Pixel;

function ss_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "ASSERT FAILED: $msg\n" );
		exit( 1 );
	}
	echo "ok - $msg\n";
}
function ss_log() {
	$lines = file_exists( '/shared/api.log' ) ? file( '/shared/api.log', FILE_IGNORE_NEW_LINES ) : array();
	return array_map( fn( $l ) => json_decode( $l, true ), $lines );
}
function ss_get( $url, array $cookies = array() ) {
	$res = wp_remote_get( $url, array( 'timeout' => 60, 'redirection' => 0, 'cookies' => $cookies ) );
	if ( is_wp_error( $res ) ) {
		fwrite( STDERR, 'GET failed: ' . $res->get_error_message() . "\n" );
		exit( 1 );
	}
	return $res;
}
function ss_jar( $res, array $jar ) {
	foreach ( wp_remote_retrieve_cookies( $res ) as $cookie ) {
		$jar[ $cookie->name ] = $cookie;
	}
	return $jar;
}
function ss_run_queue() {
	for ( $i = 0; $i < 20; $i++ ) {
		\ActionScheduler::runner()->run();
		if ( ! as_get_scheduled_actions( array( 'group' => 'socialscore', 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) ) {
			break;
		}
		sleep( 1 );
	}
}

$V4_A = '3f2b8c1e-5d4a-4b7e-9c3d-1a2b3c4d5e6f';
$V4_B = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
$SITE = 'ps_abcdefghijklmnop';

$product = new WC_Product_Simple();
$product->set_name( 'Pixel mug' );
$product->set_regular_price( '12.50' );
$product->set_status( 'publish' );
$product->save();
$url = get_permalink( $product->get_id() );
@unlink( '/shared/pixel-off' );
Pixel::forget();
@unlink( '/shared/api.log' );

// 1. Pixel on: product page has the loader with data-site, the view_item event, the events bundle; the key is nowhere.
$body = wp_remote_retrieve_body( ss_get( $url ) );
ss_assert( 1 === preg_match( '/setAttribute\("data-site","' . $SITE . '"\)/', $body ), 'product page has the loader with data-site' );
ss_assert( false !== strpos( $body, 'https://pixel.socialscore.io/v1/p.js' ) && false === strpos( $body, '{{' ), 'loader points at the Pixel script and no placeholder is left' );
ss_assert( false !== strpos( $body, '"type":"view_item","data":{"item_id":"' . $product->get_id() . '","price":12.5,"currency":"' . get_woocommerce_currency() . '"}' ), 'product page pushes view_item' );
ss_assert( false !== strpos( $body, 'build/pixel-events.js' ), 'the add-to-cart bundle is loaded' );
ss_assert( false === strpos( $body, 'sk_mock' ), 'the store key is not in the page' );
wp_remote_retrieve_body( ss_get( $url ) );
$gets = array_filter( ss_log(), fn( $e ) => 'GET' === $e['method'] && '/api/v2/stores/st_1/pixel' === $e['path'] );
ss_assert( 1 === count( $gets ), 'the Pixel state is read once for two page views (cached): ' . count( $gets ) );

// 2. Switched off through the REST proxy: no script at once.
$cookies = array();
for ( $try = 0; $try < 4 && count( $cookies ) < 2; $try++ ) {
	$login   = wp_remote_post( 'http://wordpress/wp-login.php', array( 'redirection' => 0, 'timeout' => 60, 'cookies' => array( 'wordpress_test_cookie' => 'WP Cookie check' ), 'body' => array( 'log' => 'admin', 'pwd' => 'admin', 'wp-submit' => 'Log In', 'testcookie' => 1 ) ) );
	$cookies = is_wp_error( $login ) ? array() : wp_remote_retrieve_cookies( $login );
}
$nonce = wp_remote_retrieve_body( wp_remote_get( 'http://wordpress/wp-admin/admin-ajax.php?action=rest-nonce', array( 'cookies' => $cookies, 'timeout' => 60 ) ) );
$patch = function ( $enabled ) use ( $cookies, $nonce ) {
	return wp_remote_request( 'http://wordpress/?rest_route=/socialscore/v1/proxy/api/v2/stores/st_1/pixel', array( 'method' => 'PATCH', 'timeout' => 60, 'cookies' => $cookies, 'headers' => array( 'X-WP-Nonce' => $nonce, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'enabled' => $enabled ) ) ) );
};
$res = $patch( false );
ss_assert( 200 === wp_remote_retrieve_response_code( $res ) && false === strpos( wp_remote_retrieve_body( $res ), 'sk_mock' ), 'PATCH pixel through the proxy is allowed' );
$body = wp_remote_retrieve_body( ss_get( $url ) );
ss_assert( false === strpos( $body, 'data-site' ) && false === strpos( $body, 'pixel.socialscore.io' ) && false === strpos( $body, 'pixel-events' ), 'with the Pixel off the page has no Pixel script at all' );
$res = $patch( true );
$body = wp_remote_retrieve_body( ss_get( $url ) );
ss_assert( false !== strpos( $body, 'data-site' ), 'switched on again: the loader is back at once' );
$admin = wp_remote_retrieve_body( wp_remote_get( 'http://wordpress/wp-admin/admin.php?page=socialscore', array( 'cookies' => $cookies, 'timeout' => 60 ) ) );
ss_assert( false === strpos( $admin, 'pixel.socialscore.io' ) && false === strpos( $admin, 'data-site' ), 'the admin has no loader' );

// 3. Classic checkout: the order gets the two ids from the cookies.
wc_load_cart();
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes' ) );
WC()->payment_gateways()->init();
$address = array( 'billing_first_name' => 'Ana', 'billing_last_name' => 'Ivanova', 'billing_email' => 'ana@example.test', 'billing_country' => 'BG', 'billing_address_1' => 'A 1', 'billing_city' => 'Sofia', 'billing_postcode' => '1000', 'billing_phone' => '0888123456', 'payment_method' => 'cod', 'shipping_method' => array() );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $product->get_id(), 1 );
$_COOKIE['ss_pv'] = $V4_A;
$_COOKIE['ss_ps'] = $V4_B;
$id1   = WC()->checkout()->create_order( $address );
$order = wc_get_order( $id1 );
ss_assert( $order && $V4_A === $order->get_meta( '_ss_pixel_visitor' ) && $V4_B === $order->get_meta( '_ss_pixel_session' ), 'classic checkout stores both ids in the order meta (HPOS: ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) . ')' );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $product->get_id(), 1 );
$_COOKIE['ss_pv'] = "' OR 1=1 --";
$_COOKIE['ss_ps'] = '3f2b8c1e-5d4a-1b7e-9c3d-1a2b3c4d5e6f';
$id2    = WC()->checkout()->create_order( $address );
$order2 = wc_get_order( $id2 );
ss_assert( $order2 && '' === $order2->get_meta( '_ss_pixel_visitor' ) && '' === $order2->get_meta( '_ss_pixel_session' ), 'classic checkout ignores a cookie that is not a UUIDv4 (and the order is still made)' );

// 4. Block checkout through the Store API route.
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $product->get_id(), 1 );
$_COOKIE['ss_pv'] = $V4_B;
$_COOKIE['ss_ps'] = $V4_A;
$req = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
$req->set_header( 'Content-Type', 'application/json' );
$req->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
$req->set_body( wp_json_encode( array(
	'billing_address'  => array( 'first_name' => 'Ana', 'last_name' => 'Ivanova', 'email' => 'blocks@example.test', 'country' => 'BG', 'address_1' => 'A 1', 'city' => 'Sofia', 'postcode' => '1000', 'phone' => '0888123456', 'state' => '', 'address_2' => '', 'company' => '' ),
	'shipping_address' => array( 'first_name' => 'Ana', 'last_name' => 'Ivanova', 'country' => 'BG', 'address_1' => 'A 1', 'city' => 'Sofia', 'postcode' => '1000', 'phone' => '', 'state' => '', 'address_2' => '', 'company' => '' ),
	'payment_method'   => 'cod',
) ) );
$out = rest_do_request( $req );
$data = $out->get_data();
ss_assert( 200 === $out->get_status() && ! empty( $data['order_id'] ), 'Store API checkout made an order (HTTP ' . $out->get_status() . ' ' . ( 200 === $out->get_status() ? '' : wp_json_encode( $data ) ) . ')' );
$order3 = wc_get_order( (int) $data['order_id'] );
ss_assert( $V4_B === $order3->get_meta( '_ss_pixel_visitor' ) && $V4_A === $order3->get_meta( '_ss_pixel_session' ), 'block checkout (Store API) stores both ids in the order meta' );

// 5. The ids reach SocialScore in orders:batch, and an order without them sends no empty field.
@unlink( '/shared/api.log' );
foreach ( array( $id1, $id2, (int) $data['order_id'] ) as $oid ) {
	\SocialScore\Sync::queue_unique( \SocialScore\Sync::HOOK_ORDER, array( $oid, 0 ) );
}
ss_run_queue();
$rows = array();
foreach ( ss_log() as $entry ) {
	if ( false !== strpos( $entry['path'], '/orders:batch' ) ) {
		foreach ( $entry['body']['orders'] as $row ) {
			$rows[ $row['external_id'] ] = $row;
		}
	}
}
ss_assert( isset( $rows[ (string) $id1 ] ) && $V4_A === ( $rows[ (string) $id1 ]['pixel_visitor_id'] ?? null ) && $V4_B === ( $rows[ (string) $id1 ]['pixel_session_id'] ?? null ), 'orders:batch carries pixel_visitor_id and pixel_session_id (classic order)' );
ss_assert( isset( $rows[ (string) $data['order_id'] ] ) && $V4_B === ( $rows[ (string) $data['order_id'] ]['pixel_visitor_id'] ?? null ), 'orders:batch carries them for the block order' );
ss_assert( isset( $rows[ (string) $id2 ] ) && ! array_key_exists( 'pixel_visitor_id', $rows[ (string) $id2 ] ) && ! array_key_exists( 'pixel_session_id', $rows[ (string) $id2 ] ), 'an order with an invalid cookie sends neither field' );

// 6. Events over real HTTP: add to cart that reloads the page, checkout start, one purchase per order.
$jar = array();
$res = ss_get( home_url( '/?add-to-cart=' . $product->get_id() ), $jar );
$jar = ss_jar( $res, $jar );
ss_assert( in_array( wp_remote_retrieve_response_code( $res ), array( 200, 301, 302, 303 ), true ) && count( $jar ) > 0, 'non-AJAX add to cart ran and set a cart session (HTTP ' . wp_remote_retrieve_response_code( $res ) . ')' );
// WooCommerce adds on wp_loaded, before the page is built: the same response carries the event (or the next page, after a redirect).
$html = wp_remote_retrieve_body( $res );
if ( false === strpos( $html, '"type":"add_to_cart"' ) ) {
	$page = ss_get( wp_remote_retrieve_header( $res, 'location' ) ? wp_remote_retrieve_header( $res, 'location' ) : $url, $jar );
	$jar  = ss_jar( $page, $jar );
	$html = wp_remote_retrieve_body( $page );
}
ss_assert( 1 === substr_count( $html, '"type":"add_to_cart"' ) && false !== strpos( $html, '"item_id":"' . $product->get_id() . '","quantity":1,"price":12.5' ), 'the page after a reloading add to cart pushes add_to_cart once' );
$html = wp_remote_retrieve_body( ss_get( $url, $jar ) );
ss_assert( false === strpos( $html, '"type":"add_to_cart"' ), 'the next page does not repeat it' );
$html = wp_remote_retrieve_body( ss_get( wc_get_checkout_url(), $jar ) );
ss_assert( 1 === substr_count( $html, '"type":"begin_checkout"' ) && false !== strpos( $html, '"items":1' ), 'checkout page pushes begin_checkout once' );

$thanks = $order->get_checkout_order_received_url();
// As the shop manager: WooCommerce shows a guest order only after an email check, which needs the customer's own session.
$html   = wp_remote_retrieve_body( ss_get( $thanks, $cookies ) );
$post   = get_post( wc_get_page_id( 'checkout' ) );
echo 'checkout page content: ' . substr( (string) $post->post_content, 0, 60 ) . "\n";
ss_assert( 1 === substr_count( $html, '"type":"purchase"' ) && false !== strpos( $html, '"order_external_id":"' . $id1 . '","value":' ), 'thank-you page pushes purchase once (block checkout page; ' . ( false !== stripos( $html, 'verify' ) ? 'email verification shown' : 'order shown' ) . ')' );
preg_match( '#<script id="socialscore-pixel-js-after">(.*?)</script>#s', $html, $inline );
$push = $inline[1] ?? '';
ss_assert( '' !== $push && false === stripos( $push, 'identify' ) && false === strpos( $push, 'ana@example.test' ) && false === strpos( $push, '0888123456' ), 'the Pixel inline script has no identify and no email or phone' );
$html = wp_remote_retrieve_body( ss_get( $thanks, $cookies ) );
ss_assert( false === strpos( $html, '"type":"purchase"' ), 'a second visit to the thank-you page does not push it again' );

// The same thank-you page with the classic checkout shortcode.
wp_update_post( array( 'ID' => wc_get_page_id( 'checkout' ), 'post_content' => '[woocommerce_checkout]' ) );
$html = wp_remote_retrieve_body( ss_get( wc_get_order( $id2 )->get_checkout_order_received_url(), $cookies ) );
ss_assert( 1 === substr_count( $html, '"type":"purchase"' ), 'classic shortcode thank-you page pushes purchase once' );
echo "pixel done\n";
