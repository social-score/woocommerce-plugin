<?php
// Pixel: loader on the storefront, switch through the proxy, shop events, and no cookie copy onto orders
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
	$res = wp_remote_get(
		$url,
		array(
			'timeout'     => 60,
			'redirection' => 0,
			'cookies'     => $cookies,
		)
	);
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
		if ( ! as_get_scheduled_actions(
			array(
				'group'  => 'socialscore',
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		) ) {
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
ss_assert( false === strpos( $body, 'id=\'wp-data-js' ) && false === strpos( $body, 'id="wp-data-js' ) && false === strpos( $body, 'react-dom-js' ), 'the Pixel does not pull wp-data or React into a product page' );
ss_assert( 1 === preg_match( '#id=[\'"]socialscore-pixel-events-js#', $body ), 'the events bundle script is on the page' );
wp_remote_retrieve_body( ss_get( $url ) );
$gets = array_filter( ss_log(), fn( $e ) => 'GET' === $e['method'] && '/api/v2/stores/st_1/pixel' === $e['path'] );
ss_assert( 1 === count( $gets ), 'the Pixel state is read once for two page views (cached): ' . count( $gets ) );

// 2. Switched off through the REST proxy: no script at once.
$cookies = array();
for ( $try = 0; $try < 4 && count( $cookies ) < 2; $try++ ) {
	$login   = wp_remote_post(
		'http://wordpress/wp-login.php',
		array(
			'redirection' => 0,
			'timeout'     => 60,
			'cookies'     => array( 'wordpress_test_cookie' => 'WP Cookie check' ),
			'body'        => array(
				'log'        => 'admin',
				'pwd'        => 'admin',
				'wp-submit'  => 'Log In',
				'testcookie' => 1,
			),
		)
	);
	$cookies = is_wp_error( $login ) ? array() : wp_remote_retrieve_cookies( $login );
}
$nonce = wp_remote_retrieve_body(
	wp_remote_get(
		'http://wordpress/wp-admin/admin-ajax.php?action=rest-nonce',
		array(
			'cookies' => $cookies,
			'timeout' => 60,
		)
	)
);
$patch = function ( $enabled ) use ( $cookies, $nonce ) {
	return wp_remote_request(
		'http://wordpress/?rest_route=/socialscore/v1/proxy/api/v2/stores/st_1/pixel',
		array(
			'method'  => 'PATCH',
			'timeout' => 60,
			'cookies' => $cookies,
			'headers' => array(
				'X-WP-Nonce'   => $nonce,
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( array( 'enabled' => $enabled ) ),
		)
	);
};
$res   = $patch( false );
ss_assert( 200 === wp_remote_retrieve_response_code( $res ) && false === strpos( wp_remote_retrieve_body( $res ), 'sk_mock' ), 'PATCH pixel through the proxy is allowed' );
$body = wp_remote_retrieve_body( ss_get( $url ) );
ss_assert( false === strpos( $body, 'data-site' ) && false === strpos( $body, 'pixel.socialscore.io' ) && false === strpos( $body, 'pixel-events' ), 'with the Pixel off the page has no Pixel script at all' );
$res  = $patch( true );
$body = wp_remote_retrieve_body( ss_get( $url ) );
ss_assert( false !== strpos( $body, 'data-site' ), 'switched on again: the loader is back at once' );
$admin = wp_remote_retrieve_body(
	wp_remote_get(
		'http://wordpress/wp-admin/admin.php?page=socialscore',
		array(
			'cookies' => $cookies,
			'timeout' => 60,
		)
	)
);
ss_assert( false === strpos( $admin, 'pixel.socialscore.io' ) && false === strpos( $admin, 'data-site' ), 'the admin has no loader' );

// 3. Classic checkout: old Pixel cookies in the request are not copied onto the order.
wc_load_cart();
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes' ) );
WC()->payment_gateways()->init();
$address = array(
	'billing_first_name' => 'Ana',
	'billing_last_name'  => 'Ivanova',
	'billing_email'      => 'ana@example.test',
	'billing_country'    => 'BG',
	'billing_address_1'  => 'A 1',
	'billing_city'       => 'Sofia',
	'billing_postcode'   => '1000',
	'billing_phone'      => '0888123456',
	'payment_method'     => 'cod',
	'shipping_method'    => array(),
);
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $product->get_id(), 1 );
$_COOKIE['ss_pv'] = $V4_A;
$_COOKIE['ss_ps'] = $V4_B;
WC()->session->set( 'socialscore_checkout_ref', $V4_A );
$_POST['ss_checkout_ref'] = $V4_A;
$id1                      = WC()->checkout()->create_order( $address );
$order                    = wc_get_order( $id1 );
ss_assert( $order && '' === $order->get_meta( '_ss_pixel_visitor' ) && '' === $order->get_meta( '_ss_pixel_session' ), 'classic checkout stores no visit or session id in the order meta (HPOS: ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) . ')' );
ss_assert( $V4_A === $order->get_meta( '_ss_checkout_ref' ), 'classic checkout keeps the posted checkout ref that is the one of its own session' );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $product->get_id(), 1 );
$_COOKIE['ss_pv'] = "' OR 1=1 --";
$_COOKIE['ss_ps'] = '3f2b8c1e-5d4a-1b7e-9c3d-1a2b3c4d5e6f';
WC()->session->set( 'socialscore_checkout_ref', $V4_A );
$_POST['ss_checkout_ref'] = $V4_B; // A ref the session never made.
$id2                      = WC()->checkout()->create_order( $address );
$order2                   = wc_get_order( $id2 );
ss_assert( $order2 && '' === $order2->get_meta( '_ss_pixel_visitor' ) && '' === $order2->get_meta( '_ss_pixel_session' ), 'classic checkout with a junk cookie still makes the order and stores no id' );
ss_assert( '' === (string) $order2->get_meta( '_ss_checkout_ref' ), 'classic checkout drops a posted ref that is not the one of its own session' );
unset( $_POST['ss_checkout_ref'] );

// 4. Block checkout through the Store API route.
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $product->get_id(), 1 );
$_COOKIE['ss_pv'] = $V4_B;
$_COOKIE['ss_ps'] = $V4_A;
WC()->session->set( 'socialscore_checkout_ref', $V4_B );
$req = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
$req->set_header( 'Content-Type', 'application/json' );
$req->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
$req->set_body(
	wp_json_encode(
		array(
			'billing_address'  => array(
				'first_name' => 'Ana',
				'last_name'  => 'Ivanova',
				'email'      => 'blocks@example.test',
				'country'    => 'BG',
				'address_1'  => 'A 1',
				'city'       => 'Sofia',
				'postcode'   => '1000',
				'phone'      => '0888123456',
				'state'      => '',
				'address_2'  => '',
				'company'    => '',
			),
			'shipping_address' => array(
				'first_name' => 'Ana',
				'last_name'  => 'Ivanova',
				'country'    => 'BG',
				'address_1'  => 'A 1',
				'city'       => 'Sofia',
				'postcode'   => '1000',
				'phone'      => '',
				'state'      => '',
				'address_2'  => '',
				'company'    => '',
			),
			'payment_method'   => 'cod',
			'extensions'       => array( 'socialscore' => array( 'ss_checkout_ref' => $V4_B ) ),
		)
	)
);
$out  = rest_do_request( $req );
$data = $out->get_data();
ss_assert( 200 === $out->get_status() && ! empty( $data['order_id'] ), 'Store API checkout made an order (HTTP ' . $out->get_status() . ' ' . ( 200 === $out->get_status() ? '' : wp_json_encode( $data ) ) . ')' );
$order3 = wc_get_order( (int) $data['order_id'] );
ss_assert( '' === $order3->get_meta( '_ss_pixel_visitor' ) && '' === $order3->get_meta( '_ss_pixel_session' ), 'block checkout (Store API) stores no visit or session id in the order meta' );
ss_assert( $V4_B === $order3->get_meta( '_ss_checkout_ref' ), 'block checkout keeps the ref posted as Store API extension data' );
ss_assert( false === strpos( wp_json_encode( $data ), 'ss_checkout_ref' ), 'the Store API response does not return the ref' );

// 5. orders:batch carries no visit or session id field for any of the three orders.
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
ss_assert( isset( $rows[ (string) $id1 ]['cart_token'] ) && $V4_A === $rows[ (string) $id1 ]['cart_token'], 'orders:batch row of the classic order carries its checkout ref as cart_token' );
ss_assert( isset( $rows[ (string) $data['order_id'] ]['cart_token'] ) && $V4_B === $rows[ (string) $data['order_id'] ]['cart_token'], 'orders:batch row of the block order carries its checkout ref as cart_token' );
ss_assert( ! array_key_exists( 'cart_token', $rows[ (string) $id2 ] ), 'orders:batch row of the order with a foreign ref has no cart_token' );
foreach ( array( $id1, $id2, (int) $data['order_id'] ) as $oid ) {
	ss_assert( isset( $rows[ (string) $oid ] ) && ! array_key_exists( 'pixel_visitor_id', $rows[ (string) $oid ] ) && ! array_key_exists( 'pixel_session_id', $rows[ (string) $oid ] ), 'orders:batch row of order ' . $oid . ' has neither pixel_visitor_id nor pixel_session_id' );
}

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
ss_assert( 1 === preg_match( '/"type":"begin_checkout","data":\{[^}]*"cart_token":"([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})"/', $html, $ref_match ), 'begin_checkout carries a UUIDv4 cart_token' );
ss_assert( false !== strpos( $html, 'name="ss_checkout_ref" value="' . $ref_match[1] . '"' ) || false !== strpos( $html, '"checkoutRef":"' . $ref_match[1] . '"' ), 'the same ref reaches the checkout (classic hidden field or block config)' );
$html2 = wp_remote_retrieve_body( ss_get( wc_get_checkout_url(), $jar ) );
ss_assert( false !== strpos( $html2, '"cart_token":"' . $ref_match[1] . '"' ), 'a reload of the checkout keeps the ref of the session' );

// A shop manager who opens a thank-you page is not the buyer: no purchase event, and the flag stays for the buyer.
$thanks = $order->get_checkout_order_received_url();
$html   = wp_remote_retrieve_body( ss_get( $thanks, $cookies ) );
ss_assert( false === strpos( $html, '"type":"purchase"' ), 'a shop manager on the thank-you page gets no purchase event' );
ss_assert( '' === (string) wc_get_order( $id1 )->get_meta( '_ss_pixel_purchase_sent' ), 'and the order keeps its one purchase event' );

// The buyer: a customer account with an order of their own. A visitor who is not a shop manager sees store pages
// only when the store is not in coming-soon mode (the default of a new store).
update_option( 'woocommerce_coming_soon', 'no' );
$buyer         = wp_insert_user(
	array(
		'user_login' => 'buyer',
		'user_pass'  => 'buyer-pass',
		'user_email' => 'buyer@example.test',
		'role'       => 'customer',
	)
);
$mk            = function () use ( $buyer, $product ) {
	$o = wc_create_order( array( 'customer_id' => $buyer ) );
	$o->add_product( $product, 1 );
	$o->set_billing_email( 'buyer@example.test' );
	$o->calculate_totals();
	$o->set_status( 'processing' );
	$o->save();
	return $o;
};
$buyer_cookies = array();
for ( $try = 0; $try < 4 && count( $buyer_cookies ) < 2; $try++ ) {
	$login         = wp_remote_post(
		'http://wordpress/wp-login.php',
		array(
			'redirection' => 0,
			'timeout'     => 60,
			'cookies'     => array( 'wordpress_test_cookie' => 'WP Cookie check' ),
			'body'        => array(
				'log'        => 'buyer',
				'pwd'        => 'buyer-pass',
				'wp-submit'  => 'Log In',
				'testcookie' => 1,
			),
		)
	);
	$buyer_cookies = is_wp_error( $login ) ? array() : wp_remote_retrieve_cookies( $login );
}
ss_assert( count( $buyer_cookies ) >= 2, 'the buyer logged in' );
$buy1   = $mk();
$thanks = $buy1->get_checkout_order_received_url();
$html   = wp_remote_retrieve_body( ss_get( $thanks, $buyer_cookies ) );
$post   = get_post( wc_get_page_id( 'checkout' ) );
echo 'checkout page content: ' . substr( (string) $post->post_content, 0, 60 ) . "\n";
ss_assert( 1 === substr_count( $html, '"type":"purchase"' ) && false !== strpos( $html, '"order_external_id":"' . $buy1->get_id() . '","value":' ), 'thank-you page pushes purchase once for the buyer (block checkout page)' );
preg_match( '#<script id="socialscore-pixel-js-after">(.*?)</script>#s', $html, $inline );
$push = $inline[1] ?? '';
ss_assert( '' !== $push && false === stripos( $push, 'identify' ) && false === strpos( $push, 'buyer@example.test' ) && false === strpos( $push, '0888123456' ), 'the Pixel inline script has no identify and no email or phone' );
$html = wp_remote_retrieve_body( ss_get( $thanks, $buyer_cookies ) );
ss_assert( false === strpos( $html, '"type":"purchase"' ), 'a second visit to the thank-you page does not push it again' );

// The same thank-you page with the classic checkout shortcode.
wp_update_post(
	array(
		'ID'           => wc_get_page_id( 'checkout' ),
		'post_content' => '[woocommerce_checkout]',
	)
);
$buy2 = $mk();
$html = wp_remote_retrieve_body( ss_get( $buy2->get_checkout_order_received_url(), $buyer_cookies ) );
ss_assert( 1 === substr_count( $html, '"type":"purchase"' ), 'classic shortcode thank-you page pushes purchase once' );
$html = wp_remote_retrieve_body( ss_get( wc_get_checkout_url(), $jar ) );
ss_assert( 1 === substr_count( $html, '"type":"begin_checkout"' ), 'classic shortcode checkout page pushes begin_checkout once' );
ss_assert( 1 === preg_match( '/setAttribute\("data-site","' . $SITE . '"\)/', $html ), 'classic checkout page has the loader with data-site' );
// 9. Login: the wp-login request itself makes no Pixel lookup, the next storefront page prints one data-less
//    login event, and the page after that prints none.
Pixel::forget();
@unlink( '/shared/api.log' );
$login_cookies = array();
for ( $try = 0; $try < 4 && count( $login_cookies ) < 2; $try++ ) {
	if ( $try ) {
		sleep( 3 );
	}
	$login         = wp_remote_post(
		'http://wordpress/wp-login.php',
		array(
			'redirection' => 0,
			'timeout'     => 60,
			'cookies'     => array( 'wordpress_test_cookie' => 'WP Cookie check' ),
			'body'        => array(
				'log'        => 'admin',
				'pwd'        => 'admin',
				'wp-submit'  => 'Log In',
				'testcookie' => 1,
			),
		)
	);
	$login_cookies = is_wp_error( $login ) ? array() : wp_remote_retrieve_cookies( $login );
}
ss_assert( count( $login_cookies ) > 1, 'logged in over HTTP' );
$lookups = array_filter( ss_log(), fn( $e ) => '/api/v2/stores/st_1/pixel' === $e['path'] );
ss_assert( array() === $lookups, 'the login request made no Pixel lookup' );
$html = wp_remote_retrieve_body( ss_get( $url, $login_cookies ) );
ss_assert( 1 === substr_count( $html, '"type":"login","data":{}' ), 'the next storefront page pushes one login event with no data' );
$html = wp_remote_retrieve_body( ss_get( $url, $login_cookies ) );
ss_assert( 0 === substr_count( $html, '"type":"login"' ), 'the page after that has no login event' );
echo "pixel done\n";
