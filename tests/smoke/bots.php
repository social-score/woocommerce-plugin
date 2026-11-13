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
$low = new WC_Product_Simple();
$low->set_name( 'Low stock mug' );
$low->set_regular_price( '47.47' );
$low->set_manage_stock( true );
$low->set_stock_quantity( 2 );
$low->set_status( 'publish' );
$low->save();
$low_url  = get_permalink( $low->get_id() );
$shop_url = wc_get_page_permalink( 'shop' );
echo 'theme: ' . wp_get_theme()->get( 'Name' ) . ', block theme: ' . ( wp_is_block_theme() ? 'yes' : 'no' ) . "\n";
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

// 4b. A block theme puts the Store API data of the page into its HTML (the Blocks hydration). A bad bot gets no quantity
//     there in any mode: not the remaining stock, not the most that can be added.
foreach ( array( 'the product page' => $low_url, 'the shop page' => $shop_url ) as $what => $page_url ) {
	$body = wp_remote_retrieve_body( wp_remote_get( $page_url, array( 'timeout' => 60, 'user-agent' => $BOT ) ) );
	if ( preg_match( '/.{0,300}low_stock_remaining["\']?\s*:\s*[1-9].{0,60}/s', $body, $leak ) ) {
		echo 'leak context: ' . $leak[0] . "\n";
	}
	ss_assert( 0 === preg_match( '/low_stock_remaining["\']?\s*:\s*[1-9]/', $body ), 'flag mode: ' . $what . ' has no remaining stock in its hydrated JSON' );
	ss_assert( 0 === preg_match( '/"maximum"\s*:\s*"?(?:2|37)"?[,}]/', $body ), 'flag mode: ' . $what . ' has no stock-sized maximum for the cart' );
}
$body = wp_remote_retrieve_body( wp_remote_get( $low_url, array( 'timeout' => 60, 'user-agent' => $PERSON, 'headers' => $person_headers ) ) );
ss_assert( 1 === preg_match( '/low_stock_remaining["\']?\s*:\s*2/', $body ) || false !== strpos( $body, '4747' ), 'a person still gets the real hydrated data (' . strlen( $body ) . ' bytes)' );

// 4c. The same data in the other dresses JSON is found in inside HTML: a page that carries it in all of them.
$dresses = array( '"', '\\"', '\\\\"', '&quot;', '&#34;', '&#x22;', '%22', '\\u0022' );
$blob    = '';
foreach ( $dresses as $i => $q ) {
	$blob .= '<div data-case="' . $i . '" data-x=\'{' . $q . 'id' . $q . ':88,' . $q . 'prices' . $q . ':{' . $q . 'price' . $q . ':' . $q . '4747' . $q . ',' . $q . 'price_range' . $q . ':{' . $q . 'min_amount' . $q . ':' . $q . '4747' . $q . '}},' . $q . 'low_stock_remaining' . $q . ':2,' . $q . 'add_to_cart' . $q . ':{' . $q . 'maximum' . $q . ':2}}\'>case ' . $i . '</div>';
}
kses_remove_filters();
$dressed_id = wp_insert_post( wp_slash( array( 'post_title' => 'Dressed JSON', 'post_name' => 'dressed-json', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '<!-- wp:html -->' . $blob . '<!-- /wp:html -->' ) ) );
$dressed    = get_permalink( $dressed_id );
$body       = wp_remote_retrieve_body( wp_remote_get( $dressed, array( 'timeout' => 60, 'user-agent' => $PERSON, 'headers' => $person_headers ) ) );
ss_assert( false !== strpos( $body, 'case 7' ) && 8 === substr_count( $body, '4747' ) / 2, 'the test page carries the data in all eight dresses for a person (' . substr_count( $body, '4747' ) . ' prices)' );
$body = wp_remote_retrieve_body( wp_remote_get( $dressed, array( 'timeout' => 60, 'user-agent' => $BOT ) ) );
$left = array();
foreach ( $dresses as $q ) {
	foreach ( array( 'low_stock_remaining' . $q . ':2', 'maximum' . $q . ':2}' ) as $real_quantity ) {
		if ( false !== strpos( $body, $real_quantity ) ) {
			$left[] = $real_quantity;
		}
	}
}
ss_assert( false !== strpos( $body, 'case 7' ) && array() === $left, 'flag mode: no quantity is left in any dress of the JSON in a page (' . implode( ' ', $left ) . ')' );

// 5. Substitute mode: another price is shown to the bot, never charged.
ss_mode( 'substitute' );
$html = wp_remote_retrieve_body( wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => $BOT ) ) );
if ( false !== strpos( $html, '9.90' ) ) {
	preg_match_all( '/.{0,80}9\.90.{0,40}/s', $html, $ctx );
	echo 'real price still in the page: ' . wp_json_encode( array_slice( $ctx[0], 0, 5 ) ) . "\n";
}
ss_assert( 1 === preg_match( '#<span class="price"><span class="woocommerce-Price-amount#', $html ), 'substitute mode: the bot sees the substitute price markup' );
ss_assert( 0 === preg_match( '/(?<![\d.])9\.9(?:0)?(?!\d)/', $html ), 'substitute mode: the real price is nowhere in the page in any form (text, JSON-LD, Pixel events, button attribute)' );
$html = wp_remote_retrieve_body( wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => $PERSON, 'headers' => $person_headers ) ) );
ss_assert( false !== strpos( $html, '9.90' ), 'substitute mode: a person still sees the real price' );
$res = wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => $BOT ) );
ss_assert( false !== stripos( (string) wp_remote_retrieve_header( $res, 'cache-control' ), 'no-store' ), 'substitute mode: the changed page cannot be cached (' . wp_remote_retrieve_header( $res, 'cache-control' ) . ')' );
$res = wp_remote_get( $url . '?wc-api=x', array( 'timeout' => 60, 'user-agent' => $BOT ) );
ss_assert( 0 === preg_match( '/(?<![\d.])9\.9(?:0)?(?!\d)/', wp_remote_retrieve_body( $res ) ), 'substitute mode: a made-up wc-api marker gives no real price (WooCommerce ends a wc-api request empty)' );

// The hydrated JSON of a block theme carries the price in minor units too.
foreach ( array( 'the product page' => $low_url, 'the shop page' => $shop_url ) as $what => $page_url ) {
	$body = wp_remote_retrieve_body( wp_remote_get( $page_url, array( 'timeout' => 60, 'user-agent' => $BOT ) ) );
	ss_assert( false === strpos( $body, '4747' ) && false === strpos( $body, '47.47' ) && 0 === preg_match( '/low_stock_remaining["\']?\s*:\s*[1-9]/', $body ), 'substitute mode: ' . $what . ' (hydrated JSON included) has no real price in minor units or in text, and no remaining stock' );
}
$body = wp_remote_retrieve_body( wp_remote_get( $dressed, array( 'timeout' => 60, 'user-agent' => $BOT ) ) );
if ( preg_match_all( '/.{0,70}4747.{0,30}/s', $body, $leaks ) ) {
	echo 'left: ' . wp_json_encode( array_slice( $leaks[0], 0, 4 ) ) . "\n";
}
ss_assert( false !== strpos( $body, 'case 7' ) && false === strpos( $body, '4747' ), 'substitute mode: no real price is left in any dress of the JSON in a page' );
// The page and the API show the bot the same substitute, and nothing in either says it was changed.
$low_page = wp_remote_retrieve_body( wp_remote_get( $low_url, array( 'timeout' => 60, 'user-agent' => $BOT ) ) );
$low_api  = wp_remote_retrieve_body( wp_remote_get( 'http://wordpress/wp-json/wc/store/v1/products/' . $low->get_id(), array( 'timeout' => 60, 'user-agent' => $BOT ) ) );
$api_json = json_decode( $low_api, true );
ss_assert( isset( $api_json['prices']['price'] ) && '4747' !== $api_json['prices']['price'], 'substitute mode: the API price of the low-stock product is a substitute (' . ( $api_json['prices']['price'] ?? '?' ) . ')' );
ss_assert( false !== strpos( $low_page, '"price":"' . ( $api_json['prices']['price'] ?? '-' ) . '"' ), 'substitute mode: the page carries the very same substitute as the API, not a second one' );
ss_assert( false === stripos( $low_page . $low_api, 'socialscore_shown' ) && false === stripos( $low_api, 'shown' ), 'substitute mode: nothing in the page or the API answer says it was changed' );
// wc-api as WooCommerce does not read it is a page request: a bot in substitute mode gets the substitute price, never the real one.
$real = '/(?<![\d.])9\.9(?:0)?(?!\d)/';
foreach ( array( 'an empty name' => $url . '?wc-api=', 'a list' => $url . '?wc-api[]=x' ) as $what => $probe ) {
	$body = wp_remote_retrieve_body( wp_remote_get( $probe, array( 'timeout' => 60, 'user-agent' => $BOT ) ) );
	ss_assert( 0 === preg_match( $real, $body ) && 1 === preg_match( '#<span class="price"><span class="woocommerce-Price-amount#', $body ), 'substitute mode: wc-api as ' . $what . ' is a page request and shows the substitute price' );
}
$body = wp_remote_retrieve_body( wp_remote_post( $url, array( 'timeout' => 60, 'user-agent' => $BOT, 'body' => array( 'wc-api' => 'x' ) ) ) );
ss_assert( 0 === preg_match( $real, $body ), 'substitute mode: wc-api in the POST body is a page request and shows no real price' );

// Other versions and routes of the Store API that can carry a price.
foreach ( array( '/wp-json/wc/store/v1/products/collection-data?calculate_price_range=true', '/wp-json/wc/store/v1/products' ) as $route ) {
	$body = wp_remote_retrieve_body( wp_remote_get( 'http://wordpress' . $route, array( 'timeout' => 60, 'user-agent' => $BOT ) ) );
	ss_assert( false === strpos( $body, '"price":"990"' ) && false === strpos( $body, '"min_price":"990"' ) && false === strpos( $body, '"min_amount":"990"' ), 'substitute mode: ' . $route . ' shows no real price' );
}
$body = wp_remote_retrieve_body( wp_remote_post( 'http://wordpress/wp-json/wc/store/v1/batch', array( 'timeout' => 60, 'user-agent' => $BOT, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'requests' => array( array( 'path' => '/wc/store/v1/products/' . $product->get_id(), 'method' => 'GET' ) ) ) ) ) ) );
ss_assert( false === strpos( $body, '"price":"990"' ), 'substitute mode: the Store API batch route shows no real price' );

// The published address ranges of Google and Bing, downloaded by the daily job (real files).
\SocialScore\Bot_Verifier::refresh();
$ranges = get_option( \SocialScore\Bot_Verifier::OPTION );
ss_assert( is_array( $ranges ) && count( $ranges['google_common'] ?? array() ) > 50 && count( $ranges['google_special'] ?? array() ) > 5 && count( $ranges['bing'] ?? array() ) >= 3 && count( $ranges['cloudflare'] ?? array() ) >= 10, 'the job stored the published ranges (' . count( $ranges['google_common'] ?? array() ) . ' Google common, ' . count( $ranges['google_special'] ?? array() ) . ' special, ' . count( $ranges['bing'] ?? array() ) . ' Bing, ' . count( $ranges['cloudflare'] ?? array() ) . ' Cloudflare)' );
ss_assert( ! isset( $ranges['google'] ) && ! isset( $ranges['google_user'] ), 'the user-triggered fetcher files are not kept' );
ss_assert( \SocialScore\Bot_Verifier::VERIFIED === \SocialScore\Bot_Verifier::verify( '157.55.39.1', 'bing' ) && \SocialScore\Bot_Verifier::FAILED === \SocialScore\Bot_Verifier::verify( '203.0.113.50', 'bing' ), 'an address is checked against the stored list without any DNS' );
ss_assert( false !== wp_next_scheduled( \SocialScore\Bot_Verifier::HOOK ), 'the daily job is scheduled' );

// The Store API: prices is an object, price_range too; the route is case-insensitive.
$pid = $product->get_id();
foreach ( array( 'http://wordpress/wp-json/wc/store/v1/products/' . $pid, 'http://wordpress/?rest_route=/WC/STORE/v1/products/' . $pid, 'http://wordpress/wp-json/WC/STORE/v1/products/' . $pid ) as $api ) {
	$res  = wp_remote_get( $api, array( 'timeout' => 60, 'user-agent' => $BOT ) );
	$body = wp_remote_retrieve_body( $res );
	$json = json_decode( $body, true );
	if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
		ss_assert( false === strpos( $body, '"990"' ), 'substitute mode: ' . $api . ' answers ' . wp_remote_retrieve_response_code( $res ) . ' with no real price' );
		continue;
	}
	ss_assert( is_array( $json ) && isset( $json['prices']['price'] ) && '990' !== $json['prices']['price'] && '990' !== $json['prices']['regular_price'], 'substitute mode: the Store API gives the bot a substitute price (' . $api . ' ' . ( $json['prices']['price'] ?? '?' ) . ')' );
	ss_assert( array_key_exists( 'low_stock_remaining', $json ) && null === $json['low_stock_remaining'], 'substitute mode: and no remaining stock' );
}
$res  = wp_remote_get( 'http://wordpress/wp-json/wc/store/v1/products/' . $pid, array( 'timeout' => 60, 'user-agent' => $PERSON, 'headers' => $person_headers ) );
$json = json_decode( wp_remote_retrieve_body( $res ), true );
ss_assert( isset( $json['prices']['price'] ) && '990' === $json['prices']['price'], 'substitute mode: a person gets the real Store API price' );

// admin-ajax answers the storefront's variation lookup to anyone.
$attr = new WC_Product_Attribute();
$attr->set_name( 'size' );
$attr->set_options( array( 'S' ) );
$attr->set_visible( true );
$attr->set_variation( true );
$variable = new WC_Product_Variable();
$variable->set_name( 'Bot shirt' );
$variable->set_attributes( array( $attr ) );
$variable->set_status( 'publish' );
$variable->save();
$variation = new WC_Product_Variation();
$variation->set_parent_id( $variable->get_id() );
$variation->set_attributes( array( 'size' => 'S' ) );
$variation->set_regular_price( '12.50' );
$variation->set_manage_stock( true );
$variation->set_stock_quantity( 41 );
$variation->set_status( 'publish' );
$variation->save();
WC_Product_Variable::sync( $variable->get_id() );
$ajax = function ( $ua, array $headers = array(), $query = '' ) use ( $variable ) {
	return wp_remote_post( 'http://wordpress/wp-admin/admin-ajax.php' . $query, array( 'timeout' => 60, 'user-agent' => $ua, 'headers' => $headers, 'body' => array( 'action' => 'woocommerce_get_variation', 'product_id' => $variable->get_id(), 'attribute_size' => 'S' ) ) );
};
$res  = $ajax( $BOT );
$data = json_decode( wp_remote_retrieve_body( $res ), true );
ss_assert( is_array( $data ) && isset( $data['display_price'] ) && 12.5 !== (float) $data['display_price'] && '' === (string) $data['max_qty'], 'substitute mode: admin-ajax woocommerce_get_variation gives the bot a substitute price and no quantity limit' );
$res  = $ajax( $PERSON, $person_headers );
$data = json_decode( wp_remote_retrieve_body( $res ), true );
ss_assert( is_array( $data ) && 12.5 === (float) $data['display_price'], 'substitute mode: a person gets the real variation price from admin-ajax' );
$sub_order = ss_cart_order( $product, $BOT );
ss_assert( 9.9 === (float) $sub_order->get_total() || 9.9 === (float) $sub_order->get_subtotal(), 'substitute mode never changes what is charged (order subtotal ' . $sub_order->get_subtotal() . ')' );

// 6. Block mode: the bad bot is stopped before the shop answers; people, known bots and integrations are not.
ss_mode( 'block' );
ss_assert( 403 === ss_as( $BOT, $url ), 'block mode: the bad bot gets 403 on a product page' );
ss_assert( 403 === ss_as( $BOT, $home ), 'block mode: and on the home page' );
ss_assert( 403 === ss_as( $BOT, 'http://wordpress/wp-json/wc/store/v1/products' ), 'block mode: and on the Store API' );
$wcapi = wp_remote_get( $url . '?wc-api=x', array( 'timeout' => 60, 'user-agent' => $BOT ) );
echo 'wc-api probe: HTTP ' . wp_remote_retrieve_response_code( $wcapi ) . ', ' . strlen( wp_remote_retrieve_body( $wcapi ) ) . " bytes\n";
ss_assert( 403 !== wp_remote_retrieve_response_code( $wcapi ) && strlen( wp_remote_retrieve_body( $wcapi ) ) < 100 && 0 === preg_match( '/(?<![\d.])9\.9(?:0)?(?!\d)/', wp_remote_retrieve_body( $wcapi ) ), 'block mode: a wc-api request is left alone, and WooCommerce answers it with a stub, not a page' );
foreach ( array( 'Java/11.0.2', 'Go-http-client/1.1' ) as $hook_ua ) {
	ss_assert( 403 !== ss_as( $hook_ua, 'http://wordpress/?wc-api=wc_gateway_paypal' ), 'block mode: a gateway webhook (query form, ' . $hook_ua . ') is not blocked' );
	ss_assert( 403 !== ss_as( $hook_ua, 'http://wordpress/wc-api/wc_gateway_paypal/' ), 'block mode: a gateway webhook (path form, ' . $hook_ua . ') is not blocked' );
}
// wc-api as WooCommerce reads it: a name that is text and not empty. Anything else is a page request and a bad bot is stopped.
foreach ( array( 'an empty name' => $url . '?wc-api=', 'a list' => $url . '?wc-api[]=x' ) as $what => $probe ) {
	ss_assert( 403 === ss_as( $BOT, $probe ), 'block mode: wc-api as ' . $what . ' is a page request and gets 403' );
}
$res = wp_remote_post( $url, array( 'timeout' => 60, 'user-agent' => $BOT, 'body' => array( 'wc-api' => 'x' ) ) );
ss_assert( 403 === wp_remote_retrieve_response_code( $res ), 'block mode: wc-api in the POST body is a page request and gets 403' );
foreach ( array( '/wc-api/', '/wc-api//', '/wc-api/%20/' ) as $probe_path ) {
	ss_assert( 403 === ss_as( $BOT, 'http://wordpress' . $probe_path ), 'block mode: ' . $probe_path . ' has no name for WooCommerce, is a page request and gets 403' );
}
foreach ( array( 'Go-http-client/1.1', 'Java/11.0.2' ) as $hook_ua ) {
	ss_assert( 403 !== ss_as( $hook_ua, 'http://wordpress/?wc-api=probe_gw' ), 'block mode: /?wc-api=probe_gw passes for ' . $hook_ua );
	ss_assert( 403 !== ss_as( $hook_ua, 'http://wordpress/wc-api/probe_gw/' ), 'block mode: /wc-api/probe_gw/ passes for ' . $hook_ua );
}

// The Store API reached any other way. WordPress reads rest_route from the POST body first, so the address can say anything.
$post = function ( $ua, $url_, array $body ) {
	return wp_remote_post( $url_, array( 'timeout' => 60, 'user-agent' => $ua, 'body' => $body ) );
};
foreach ( array( '/wc/store/v1/products/' . $pid, '/wc/store/v1/cart', '/WC/STORE/v1/cart' ) as $route ) {
	$res = $post( $BOT, 'http://wordpress/wp-json/wp/v2/types?_method=GET', array( 'rest_route' => $route ) );
	ss_assert( 403 === wp_remote_retrieve_response_code( $res ), 'block mode: POST /wp-json/wp/v2/types with rest_route=' . $route . ' in the body gets 403 (' . wp_remote_retrieve_response_code( $res ) . ')' );
}
$res = $post( $BOT, 'http://wordpress/wp-json/wp/v2/types', array( 'rest_route' => '/wc/store/v1/cart/add-item', 'id' => $pid, 'quantity' => 1 ) );
ss_assert( 403 === wp_remote_retrieve_response_code( $res ), 'block mode: add-item through the body route gets 403' );
$res = $post( $PERSON, 'http://wordpress/wp-json/wp/v2/types', array( 'rest_route' => '/wc/store/v1/products/' . $pid ) );
ss_assert( 403 !== wp_remote_retrieve_response_code( $res ), 'block mode: a person is not blocked on the same request (' . wp_remote_retrieve_response_code( $res ) . ')' );
$res = $post( $BOT, 'http://wordpress/wp-json/wc/store/v1/products/' . $pid, array( 'rest_route' => '/wp/v2/types' ) );
ss_assert( 200 !== wp_remote_retrieve_response_code( $res ) && false === strpos( wp_remote_retrieve_body( $res ), '"prices"' ), 'block mode: Store API path with another route in the body gives a bot nothing (' . wp_remote_retrieve_response_code( $res ) . ')' );
foreach ( array( 'an encoded slash' => '/wp-json/wc%2Fstore/v1/products/' . $pid, 'an encoded letter' => '/wp-json/%77c/store/v1/products/' . $pid, 'double encoding' => '/wp-json/wc%252Fstore/v1/products/' . $pid, 'double slashes' => '//wp-json//wc//store//v1/products/' . $pid, 'index.php' => '/index.php/wp-json/wc/store/v1/products/' . $pid, 'capitals' => '/WP-JSON/WC/STORE/v1/products/' . $pid ) as $what => $path ) {
	$res  = wp_remote_get( 'http://wordpress' . $path, array( 'timeout' => 60, 'redirection' => 0, 'user-agent' => $BOT ) );
	$code = wp_remote_retrieve_response_code( $res );
	ss_assert( in_array( $code, array( 301, 302, 403, 404 ), true ) && false === strpos( wp_remote_retrieve_body( $res ), '"prices"' ), 'block mode: the Store API by ' . $what . ' gives a bot nothing (' . $code . ')' );
}

$res = $ajax( $BOT );
ss_assert( 403 === wp_remote_retrieve_response_code( $res ), 'block mode: admin-ajax woocommerce_get_variation is blocked for a bot' );
$res = $ajax( $BOT, array(), '?wc-api=probe_gw' );
ss_assert( 403 === wp_remote_retrieve_response_code( $res ), 'block mode: a wc-api marker does not exempt admin-ajax' );
ss_assert( 403 === ss_as( $BOT, 'http://wordpress/wp-json/WC/STORE/v1/products/' . $pid ) || 404 === ss_as( $BOT, 'http://wordpress/wp-json/WC/STORE/v1/products/' . $pid ), 'block mode: the Store API in capitals is no way round' );
$res = wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => $BOT ) );
ss_assert( false !== stripos( (string) wp_remote_retrieve_header( $res, 'cache-control' ), 'no-store' ), 'block mode: the 403 cannot be cached (' . wp_remote_retrieve_header( $res, 'cache-control' ) . ')' );
ss_assert( 403 === ss_as( '', $url ), 'block mode: a request with no user agent is a bad bot too' );
ss_assert( 200 === ss_as( $PERSON, $url, $person_headers ), 'block mode: a person still gets the page' );
ss_assert( 200 === ss_as( $GOOGLE, $url ), 'block mode: Googlebot is never blocked' );
ss_assert( 403 !== ss_as( $BOT, 'http://wordpress/wp-json/socialscore/v1/nothing' ), 'block mode: a REST callback by an HTTP library is not blocked' );
ss_assert( 403 !== ss_as( $BOT, 'http://wordpress/?wc-api=socialscore_test' ), 'block mode: any wc-api request is left alone' );
ss_assert( 403 !== ss_as( $BOT, 'http://wordpress/wp-cron.php?doing_wp_cron' ), 'block mode: wp-cron is not blocked' );

// 6b. Behind a proxy. The test stack's own address is made a "Cloudflare" address (and, below, a trusted proxy), so the module
//     sees what a shop behind one sees. The visitor is whatever a proxy the SHOP trusts says; nobody else's header counts.
$saved_ranges   = get_option( \SocialScore\Bot_Verifier::OPTION );
$saved_settings = get_option( \SocialScore\Bot_Settings::OPTION );
$private_nets   = array( '172.16.0.0/12', '10.0.0.0/8', '192.168.0.0/16' );
update_option( \SocialScore\Bot_Verifier::OPTION, array( 'google_common' => array( '66.249.64.0/19' ), 'cloudflare' => $private_nets, 'fetched' => time() ), false );
delete_option( \SocialScore\Bot_Settings::OPTION );
delete_option( \SocialScore\Bot_Settings::HINTS );
// "Behind Cloudflare" is off (the default): Cloudflare is not trusted. Never verified, never 403, whatever the header says.
ss_assert( 403 !== ss_as( $GOOGLE, $url, array( 'CF-Connecting-IP' => '66.249.66.1' ) ), 'block mode, Behind Cloudflare off: Googlebot from a Cloudflare address is unverified and never gets 403' );
ss_assert( 403 !== ss_as( $GOOGLE, $url, array( 'CF-Connecting-IP' => '203.0.113.50' ) ), 'block mode, Behind Cloudflare off: and a scraper writing the header is not turned into a 403 either (nothing is trusted)' );
// A private address with only a forwarding header: a proxy nobody described, unknown.
update_option( \SocialScore\Bot_Verifier::OPTION, array( 'google_common' => array( '66.249.64.0/19' ), 'cloudflare' => array( '203.0.113.0/24' ), 'fetched' => time() ), false );
ss_assert( 403 !== ss_as( $GOOGLE, $url, array( 'X-Forwarded-For' => '66.249.66.1' ) ), 'block mode: Googlebot from a private address with only X-Forwarded-For is unverified and never gets 403' );
update_option( \SocialScore\Bot_Verifier::OPTION, array( 'google_common' => array( '66.249.64.0/19' ), 'cloudflare' => $private_nets, 'fetched' => time() ), false );
// "Behind Cloudflare" is on: CF-Connecting-IP is the visitor.
update_option( \SocialScore\Bot_Settings::OPTION, array( 'behind_cloudflare' => true ), false );
ss_assert( 403 !== ss_as( $GOOGLE, $url, array( 'CF-Connecting-IP' => '66.249.66.1' ) ), 'block mode, Behind Cloudflare on: Googlebot with its real address in CF-Connecting-IP is verified and gets the page' );
ss_assert( 403 !== ss_as( $GOOGLE, $url ), 'block mode, Behind Cloudflare on: no header is unverified and never gets 403' );
ss_assert( 403 === ss_as( $GOOGLE, $url, array( 'CF-Connecting-IP' => '203.0.113.50' ) ), 'block mode, Behind Cloudflare on: a scraper that says Googlebot gets 403' );
// A trusted proxy from the settings, with the client in X-Forwarded-For.
update_option( \SocialScore\Bot_Settings::OPTION, array( 'trusted_proxies' => array( '172.16.0.0/12', '10.0.0.0/8', '192.168.0.0/16' ) ), false );
ss_assert( 403 !== ss_as( $GOOGLE, $url, array( 'X-Forwarded-For' => '66.249.66.1' ) ), 'block mode, trusted proxy: Googlebot named in X-Forwarded-For is verified' );
ss_assert( 403 === ss_as( $GOOGLE, $url, array( 'X-Forwarded-For' => '66.249.66.1, 203.0.113.50' ) ), 'block mode, trusted proxy: the right-most untrusted address is the visitor, so a scraper that wrote a Google address on the left gets 403' );
ss_assert( 403 !== ss_as( $GOOGLE, $url ), 'block mode, trusted proxy without the header: unverified, never 403' );
update_option( \SocialScore\Bot_Verifier::OPTION, $saved_ranges, false );
if ( false === $saved_settings ) {
	delete_option( \SocialScore\Bot_Settings::OPTION );
} else {
	update_option( \SocialScore\Bot_Settings::OPTION, $saved_settings, false );
}
ss_assert( array() !== \SocialScore\Bot_Settings::hints() && false !== get_option( \SocialScore\Bot_Settings::HINTS ), 'the module counted the engines it could not check behind a proxy, for the warning on the reports page' );

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
ss_assert( false !== strpos( $page, 'Trusted proxies' ) && false !== strpos( $page, 'name="behind_cloudflare"' ) && false !== strpos( $page, 'looks like this shop is behind Cloudflare' ), 'the reports page has the proxy settings and the warning for the shop that is behind a proxy' );
ss_assert( false === strpos( $page, 'sk_mock' ), 'the store key is not on the page' );
$list = wp_remote_retrieve_body( wp_remote_get( 'http://wordpress/wp-admin/admin.php?page=wc-orders', array( 'cookies' => $cookies, 'timeout' => 60 ) ) );
ss_assert( false !== strpos( $list, 'Not human: Bad bot' ), 'the orders list shows the flag' );
$edit = wp_remote_retrieve_body( wp_remote_get( 'http://wordpress/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $bot_order->get_id(), array( 'cookies' => $cookies, 'timeout' => 60 ) ) );
ss_assert( false !== strpos( $edit, 'socialscore-not-human' ) && false !== strpos( $edit, 'did not come from a person' ), 'the order screen shows the warning' );

// 9. Level 1 sends nothing personal: no identity event was queued by any of it.
$queued = as_get_scheduled_actions( array( 'hook' => 'socialscore_identity_event' ), 'ids' );
ss_assert( array() === $queued, 'no identity event is queued on Level 1' );
echo "bots done\n";
