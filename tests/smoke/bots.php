<?php
// Stage 5: a bot that runs no JavaScript is caught on the server, its order is flagged, and the "block" mode stops it.
// Over real HTTP against WordPress and WooCommerce. The bad-bot mode is a flag file the mock API serves, like the switch.
use SocialScore\Bot_Guard;
use SocialScore\Bot_Stats;
use SocialScore\Not_Human;
use SocialScore\Pixel;

function ss_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "ASSERT FAILED: $msg\n" );
		exit( 1 );
	}
	echo "ok - $msg\n";
}
function ss_as( $ua, $url, array $more = array() ) {
	$headers = array_merge( array( 'Accept' => '*/*' ), $more );
	$res     = wp_remote_get( $url, array( 'timeout' => 60, 'redirection' => 0, 'user-agent' => $ua, 'headers' => $headers ) );
	if ( is_wp_error( $res ) ) {
		fwrite( STDERR, 'GET failed: ' . $res->get_error_message() . "\n" );
		exit( 1 );
	}
	return wp_remote_retrieve_response_code( $res );
}
function ss_mode( $mode ) {
	file_put_contents( '/shared/bot-mode', $mode );
	Pixel::forget();
}
function ss_cart_order( $product, $ua ) {
	$_SERVER['HTTP_USER_AGENT']      = $ua;
	$_SERVER['HTTP_ACCEPT']          = 'text/html,application/xhtml+xml';
	$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
	Bot_Guard::reset();
	wc_load_cart();
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $product->get_id(), 1 );
	$address = array( 'billing_first_name' => 'Bot', 'billing_last_name' => 'Test', 'billing_email' => 'bot@example.test', 'billing_country' => 'BG', 'billing_address_1' => 'A 1', 'billing_city' => 'Sofia', 'billing_postcode' => '1000', 'billing_phone' => '0888123456', 'payment_method' => 'cod' );
	$order   = wc_get_order( WC()->checkout()->create_order( $address ) );
	( new Not_Human() )->note_new_order( $order->get_id() );
	return wc_get_order( $order->get_id() );
}

$PERSON = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
$BOT    = 'python-requests/2.31.0';
$GOOGLE = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
$home   = 'http://wordpress/';

$product = new WC_Product_Simple();
$product->set_name( 'Bot mug' );
$product->set_regular_price( '9.90' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 37 );
$product->set_status( 'publish' );
$product->save();
$url = get_permalink( $product->get_id() );
@unlink( '/shared/pixel-off' );
@unlink( '/shared/api.log' );
update_option( 'woocommerce_coming_soon', 'no' );
delete_option( Bot_Stats::OPTION );
ss_mode( 'flag' );
$person_headers = array( 'Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'en-US,en;q=0.9' );

// 1. Default mode (flag): nothing is stopped, the server still counts what it caught with no JavaScript.
ss_assert( 200 === ss_as( $BOT, $url ), 'flag mode: a script that runs no JavaScript still gets the page' );
ss_assert( 200 === ss_as( $GOOGLE, $url ), 'flag mode: Googlebot gets the page' );
ss_assert( 200 === ss_as( $PERSON, $url, $person_headers ), 'flag mode: a person gets the page' );
$totals = Bot_Stats::totals( 1 );
ss_assert( $totals['bad_bot'] >= 1 && $totals['info_bot'] >= 1, 'the server counted the bad bot and the known bot (' . wp_json_encode( $totals ) . ')' );
$stored = wp_json_encode( get_option( Bot_Stats::OPTION ) );
ss_assert( false === strpos( $stored, 'python' ) && false === strpos( $stored, '127.' ) && false === strpos( $stored, '172.' ), 'the counts hold no user agent and no address' );

// 2. The order of a bot that ran no JavaScript is flagged (classic checkout), the order of a person is not.
$bot_order = ss_cart_order( $product, $BOT );
$flag      = Not_Human::for_order( $bot_order );
ss_assert( $flag && 'bad_bot' === $flag['class'] && 'server' === $flag['source'] && in_array( 'http_client_ua', $flag['signals'], true ), 'the bot order is flagged bad_bot from the request (HPOS: ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) . ')' );
$notes = array_map( fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $bot_order->get_id() ) ) );
ss_assert( in_array( 'SocialScore: not from a human (Bad bot). Check this order.', $notes, true ), 'the bot order has one note that says so' );
$person_order = ss_cart_order( $product, $PERSON );
ss_assert( null === Not_Human::for_order( $person_order ), 'the order of a person has no flag' );

// 3. The class of the visit that comes from SocialScore flags an order the request did not catch (a bot that ran JavaScript).
$js_bot = ss_cart_order( $product, $PERSON );
file_put_contents( '/shared/session-class-' . $js_bot->get_id(), 'bad_bot' );
( new Not_Human() )->handle_session( $js_bot->get_id(), 0 );
$flag = Not_Human::for_order( wc_get_order( $js_bot->get_id() ) );
ss_assert( $flag && 'bad_bot' === $flag['class'] && 'pixel' === $flag['source'] && array( 'webdriver' ) === $flag['signals'], 'the Pixel class of the order visit flags the order too' );
$late = ss_cart_order( $product, $PERSON );
( new Not_Human() )->handle_session( $late->get_id(), 0 );
ss_assert( null === Not_Human::for_order( wc_get_order( $late->get_id() ) ), 'an order with no visit at SocialScore yet is not flagged' );

// 4. What a bad bot sees in flag mode: the real price, and stock without the quantity.
$html = wp_remote_retrieve_body( wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => $BOT ) ) );
ss_assert( false !== strpos( $html, '9.90' ), 'flag mode: the bot sees the real price' );
ss_assert( false === strpos( $html, '37 in stock' ), 'in every mode the bot is not told how many are in stock' );
$html = wp_remote_retrieve_body( wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => $PERSON, 'headers' => $person_headers ) ) );
ss_assert( false !== strpos( $html, '37 in stock' ), 'a person sees the shop\'s own stock text' );

// 5. Substitute mode: another price is shown to the bot, never charged.
ss_mode( 'substitute' );
$html = wp_remote_retrieve_body( wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => $BOT ) ) );
if ( false !== strpos( $html, '9.90' ) ) {
	preg_match_all( '/.{0,80}9\.90.{0,40}/s', $html, $ctx );
	echo 'real price still in the page: ' . wp_json_encode( array_slice( $ctx[0], 0, 5 ) ) . "\n";
}
ss_assert( 1 === preg_match( '#<span class="price"><span class="woocommerce-Price-amount#', $html ), 'substitute mode: the bot sees the substitute price markup' );
ss_assert( false === strpos( $html, '9.90' ), 'substitute mode: the real price is nowhere in the page, structured data included' );
$html = wp_remote_retrieve_body( wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => $PERSON, 'headers' => $person_headers ) ) );
ss_assert( false !== strpos( $html, '9.90' ), 'substitute mode: a person still sees the real price' );
$sub_order = ss_cart_order( $product, $BOT );
ss_assert( 9.9 === (float) $sub_order->get_total() || 9.9 === (float) $sub_order->get_subtotal(), 'substitute mode never changes what is charged (order subtotal ' . $sub_order->get_subtotal() . ')' );

// 6. Block mode: the bad bot is stopped before the shop answers; people, known bots and integrations are not.
ss_mode( 'block' );
ss_assert( 403 === ss_as( $BOT, $url ), 'block mode: the bad bot gets 403 on a product page' );
ss_assert( 403 === ss_as( $BOT, $home ), 'block mode: and on the home page' );
ss_assert( 403 === ss_as( $BOT, 'http://wordpress/wp-json/wc/store/v1/products' ), 'block mode: and on the Store API' );
ss_assert( 403 === ss_as( '', $url ), 'block mode: a request with no user agent is a bad bot too' );
ss_assert( 200 === ss_as( $PERSON, $url, $person_headers ), 'block mode: a person still gets the page' );
ss_assert( 200 === ss_as( $GOOGLE, $url ), 'block mode: Googlebot is never blocked' );
ss_assert( 403 !== ss_as( $BOT, 'http://wordpress/wp-json/socialscore/v1/nothing' ), 'block mode: a REST callback by an HTTP library is not blocked' );
ss_assert( 403 !== ss_as( $BOT, 'http://wordpress/?wc-api=socialscore_test' ), 'block mode: a wc-api callback by an HTTP library is not blocked' );
ss_assert( 403 !== ss_as( $BOT, 'http://wordpress/wp-cron.php?doing_wp_cron' ), 'block mode: wp-cron is not blocked' );

// 7. With the Pixel off, nothing is blocked.
touch( '/shared/pixel-off' );
Pixel::forget();
ss_assert( 200 === ss_as( $BOT, $url ), 'Pixel off: the bot is served, the module does nothing' );
@unlink( '/shared/pixel-off' );
ss_mode( 'flag' );

// 8. The reports page and the orders list, as the admin sees them.
$cookies = array();
for ( $try = 0; $try < 4 && count( $cookies ) < 2; $try++ ) {
	if ( $try ) {
		sleep( 3 );
	}
	$login   = wp_remote_post( 'http://wordpress/wp-login.php', array( 'redirection' => 0, 'timeout' => 60, 'cookies' => array( 'wordpress_test_cookie' => 'WP Cookie check' ), 'body' => array( 'log' => 'admin', 'pwd' => 'admin', 'wp-submit' => 'Log In', 'testcookie' => 1 ) ) );
	$cookies = is_wp_error( $login ) ? array() : wp_remote_retrieve_cookies( $login );
}
ss_assert( count( $cookies ) > 1, 'logged in over HTTP' );
$res  = wp_remote_get( 'http://wordpress/wp-admin/admin.php?page=socialscore-reports&period=last_7_days', array( 'cookies' => $cookies, 'timeout' => 60 ) );
$page = wp_remote_retrieve_body( $res );
ss_assert( 200 === wp_remote_retrieve_response_code( $res ) && false !== strpos( $page, 'SocialScore reports' ), 'the reports page opens' );
ss_assert( false !== strpos( $page, 'By kind of visitor' ) && false !== strpos( $page, 'Bad bots' ) && false !== strpos( $page, '120' ), 'the reports page shows the traffic of the report API' );
ss_assert( false !== strpos( $page, 'name="bot_mode" value="flag" checked="checked"' ), 'the reports page offers the three modes with the one SocialScore holds selected' );
ss_assert( false !== strpos( $page, '#' . $bot_order->get_id() ), 'the warnings list has the bot order' );
ss_assert( false === strpos( $page, 'sk_mock' ), 'the store key is not on the page' );
$list = wp_remote_retrieve_body( wp_remote_get( 'http://wordpress/wp-admin/admin.php?page=wc-orders', array( 'cookies' => $cookies, 'timeout' => 60 ) ) );
ss_assert( false !== strpos( $list, 'Not human: Bad bot' ), 'the orders list shows the flag' );
$edit = wp_remote_retrieve_body( wp_remote_get( 'http://wordpress/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $bot_order->get_id(), array( 'cookies' => $cookies, 'timeout' => 60 ) ) );
ss_assert( false !== strpos( $edit, 'socialscore-not-human' ) && false !== strpos( $edit, 'did not come from a person' ), 'the order screen shows the warning' );

// 9. Level 1 sends nothing personal: no identity event was queued by any of it.
$queued = as_get_scheduled_actions( array( 'hook' => 'socialscore_identity_event' ), 'ids' );
ss_assert( array() === $queued, 'no identity event is queued on Level 1' );
echo "bots done\n";
