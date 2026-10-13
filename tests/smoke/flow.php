<?php
// Runs through wp eval-file: connect (code exchange), first import, real-time sync, check + label, proxy, disconnect.
use SocialScore\Connect;
use SocialScore\Connection;

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
function ss_run_queue() {
	for ( $i = 0; $i < 5; $i++ ) {
		$runner = \ActionScheduler::runner();
		$runner->run();
	}
}

wp_set_current_user( 1 );
@unlink( '/shared/api.log' );

ss_assert( ! Connection::is_connected() && ! file_exists( '/shared/api.log' ), 'nothing sent before connect' );

// Existing data to import.
$customer_id = wc_create_new_customer( 'old@example.test', 'oldcustomer', 'pw-Old-123456' );
$old = wc_create_order( array( 'customer_id' => $customer_id ) );
$old->set_billing_email( 'old@example.test' );
$old->set_total( 30 );
$old->save();

// Connect: start (state + PKCE), then the callback with a code.
$url = Connect::begin( 1 );
parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
ss_assert( 'woocommerce' === $q['platform'] && 'S256' === $q['code_challenge_method'], 'connect url has platform and S256' );
$result = ( new Connect() )->complete( $q['state'], 'auth-code', '', 1 );
ss_assert( 'connected' === $result, 'code exchange stores the connection' );
// WordPress 6.6+ stores 'off' where older versions stored 'no'.
ss_assert( in_array( $GLOBALS['wpdb']->get_var( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name='socialscore_connection'" ), array( 'no', 'off' ), true ), 'connection option autoload is off' );
ss_assert( 'sk_mock_secret' === Connection::api_key(), 'key decrypts' );
ss_assert( false === strpos( (string) $GLOBALS['wpdb']->get_var( "SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name='socialscore_connection'" ), 'sk_mock_secret' ), 'key is not stored in clear text' );

// First import runs from Action Scheduler.
ss_run_queue();
$paths = array_column( ss_log(), 'path' );
ss_assert( in_array( '/api/v2/stores/st_1/customers:batch', $paths, true ), 'import pushed customers' );
ss_assert( in_array( '/api/v2/stores/st_1/orders:batch', $paths, true ), 'import pushed orders' );
$state = get_option( 'socialscore_sync_state' );
ss_assert( 'done' === $state['status'], 'import state is done' );
foreach ( ss_log() as $row ) {
	if ( 'POST' === $row['method'] && false !== strpos( $row['path'], ':batch' ) ) {
		ss_assert( 'sk_mock_secret' === $row['key'], 'batch carries the store key header' );
		break;
	}
}

// Real-time: a new order is queued, not sent in the same request.
@unlink( '/shared/api.log' );
$order = wc_create_order();
$order->set_billing_email( 'new@example.test' );
$order->set_billing_first_name( 'Nina' );
$order->set_total( 55 );
$order->save();
ss_assert( array() === ss_log(), 'checkout request does not call SocialScore (queued)' );
ss_run_queue();
$rows  = ss_log();
$paths = array_column( $rows, 'path' );
ss_assert( in_array( '/api/v2/stores/st_1/orders:batch', $paths, true ), 'new order synced in background' );
ss_assert( in_array( '/api/v2/stores/st_1/checks', $paths, true ), 'auto-check ran (order_any_customer)' );
$check = array_values( array_filter( $rows, fn( $r ) => false !== strpos( $r['path'], '/checks' ) ) )[0];
ss_assert( 'wc:st_1:order:' . $order->get_id() === $check['idem'], 'check has stable Idempotency-Key' );

$fresh = wc_get_order( $order->get_id() );
ss_assert( array( 'review_before_shipping' ) === $fresh->get_meta( '_socialscore_labels' ), 'label saved in order meta (HPOS safe)' );
$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
ss_assert( count( array_filter( $notes, fn( $n ) => false !== strpos( $n->content, 'Review before shipping' ) ) ) === 1, 'order note added once' );

// Order screen ids and meta box. wc_get_page_screen_id() lives in an admin-only file.
require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
ss_assert( 'woocommerce_page_wc-orders' === wc_get_page_screen_id( 'shop-order' ), 'wc_get_page_screen_id returns the HPOS screen id' );
ss_assert( 'woocommerce_page_wc-orders' === \SocialScore\Check_Panels::order_screen_id(), 'HPOS screen id' );
ob_start();
( new \SocialScore\Check_Panels() )->render_meta_box( $fresh );
$html = ob_get_clean();
ss_assert( false !== strpos( $html, 'data-external-id="' . $order->get_id() . '"' ) && false === strpos( $html, 'sk_mock_secret' ), 'meta box renders the mount point without the key' );
ss_assert( 'Review before shipping' === \SocialScore\Check_Panels::column_text( $fresh ), 'list column text' );

// REST proxy through the real REST server.
$req = new WP_REST_Request( 'GET', '/socialscore/v1/proxy/api/v2/stores/st_1/settings' );
$res = rest_do_request( $req );
ss_assert( 200 === $res->get_status() && false === strpos( wp_json_encode( $res->get_data() ), 'sk_mock' ), 'proxy answers and does not leak the key' );
$res = rest_do_request( new WP_REST_Request( 'GET', '/socialscore/v1/proxy/api/v2/stores/other/settings' ) );
ss_assert( 403 === $res->get_status(), 'proxy refuses other store ids' );
wp_set_current_user( 0 );
$res = rest_do_request( new WP_REST_Request( 'GET', '/socialscore/v1/proxy/api/v2/stores/st_1/settings' ) );
ss_assert( in_array( $res->get_status(), array( 401, 403 ), true ), 'proxy refuses logged-out users' );
wp_set_current_user( 1 );

// Admin page renders (connected) without notices.
set_current_screen( 'toplevel_page_socialscore' );
ob_start();
( new \SocialScore\Admin_Page() )->render();
$page = ob_get_clean();
ss_assert( false !== strpos( $page, 'id="socialscore-root"' ) && false !== strpos( $page, 'data-store-id="st_1"' ), 'dashboard mount point rendered' );

echo "flow part 1 done\n";
