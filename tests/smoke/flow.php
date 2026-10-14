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
	// Chained actions (the import) are queued while the runner works, so run until nothing is pending.
	for ( $i = 0; $i < 20; $i++ ) {
		\ActionScheduler::runner()->run();
		$pending = as_get_scheduled_actions( array( 'group' => 'socialscore', 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' );
		if ( ! $pending ) {
			break;
		}
		sleep( 1 );
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

// An abandoned block checkout (draft) and a manual order with no account and no email.
$draft = wc_create_order();
$draft->set_billing_email( 'draft@example.test' );
$draft->set_total( 5 );
$draft->set_status( 'checkout-draft' );
$draft->save();
ss_assert( 'checkout-draft' === wc_get_order( $draft->get_id() )->get_status(), 'the draft order really is checkout-draft' );
$manual = wc_create_order();
$manual->set_total( 7 );
$manual->save();
$GLOBALS['ss_draft_id']  = $draft->get_id();
$GLOBALS['ss_manual_id'] = $manual->get_id();

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
$sent_orders = array();
$sent_emails = array();
foreach ( ss_log() as $row ) {
	foreach ( (array) ( $row['body']['orders'] ?? array() ) as $o ) {
		$sent_orders[ $o['external_id'] ] = $o;
	}
	foreach ( (array) ( $row['body']['customers'] ?? array() ) as $c ) {
		$sent_emails[] = $c['email'] ?? '';
	}
}
ss_assert( ! isset( $sent_orders[ (string) $GLOBALS['ss_draft_id'] ] ) && ! in_array( 'draft@example.test', $sent_emails, true ), 'import skips checkout-draft orders and their customers' );
ss_assert( isset( $sent_orders[ (string) $GLOBALS['ss_manual_id'] ] ) && '' !== $sent_orders[ (string) $GLOBALS['ss_manual_id'] ]['customer_external_id'], 'order without account and email still carries customer_external_id' );
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

// The batch answer's id mapping is kept in the order meta, and the order is found again by it.
$mapped = (int) $fresh->get_meta( '_socialscore_order_id' );
ss_assert( $mapped > 9000, 'order meta holds the SocialScore id from the batch answer' );
$found = \SocialScore\Sync::order_for_socialscore_id( $mapped );
ss_assert( $found && $found->get_id() === $order->get_id(), 'order is found again by its SocialScore id' );
ss_assert( '' === wc_get_order( $GLOBALS['ss_draft_id'] )->get_meta( '_socialscore_order_id' ), 'the skipped draft order has no SocialScore id' );

// WordPress personal data exporter and eraser reach the per-customer routes.
$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
ss_assert( isset( $exporters['socialscore'] ) && isset( $erasers['socialscore'] ), 'exporter and eraser are registered' );
$guest_id = 'guest-' . sha1( 'new@example.test' );
@unlink( '/shared/api.log' );
$export = call_user_func( $exporters['socialscore']['callback'], 'new@example.test', 1 );
ss_assert( true === $export['done'] && 'socialscore-customer' === $export['data'][0]['group_id'], 'exporter returns the customer group' );
ss_assert( '/api/v2/stores/st_1/customers/' . $guest_id . '/export' === ss_log()[0]['path'], 'exporter called the export route with the guest id' );
@unlink( '/shared/api.log' );
$erase = call_user_func( $erasers['socialscore']['callback'], 'new@example.test', 1 );
ss_assert( true === $erase['items_removed'] && false === $erase['items_retained'], 'eraser reports the removed customer' );
$del = ss_log()[0];
ss_assert( 'DELETE' === $del['method'] && '/api/v2/stores/st_1/customers/' . $guest_id === $del['path'] && 'sk_mock_secret' === $del['key'], 'eraser called DELETE on the customer route with the store key' );

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
