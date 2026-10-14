<?php
// Tiny stand-in for the SocialScore public API v2. Logs each request to /shared/api.log (one JSON per line).
$path    = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$body    = file_get_contents( 'php://input' );
$headers = array_change_key_case( function_exists( 'getallheaders' ) ? getallheaders() : array(), CASE_LOWER );
file_put_contents(
	'/shared/api.log',
	json_encode( array( 'method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'key' => $headers['x-api-key'] ?? null, 'idem' => $headers['idempotency-key'] ?? null, 'body' => json_decode( $body, true ) ) ) . "\n",
	FILE_APPEND
);
header( 'Content-Type: application/json' );
$json = json_decode( $body, true );
if ( false !== strpos( $path, '/stores/st_gone/' ) ) {
	// A store that was uninstalled on the SocialScore side.
	http_response_code( 401 );
	echo json_encode( array( 'detail' => 'Invalid API key' ) );
} elseif ( preg_match( '#/stores/token$#', $path ) ) {
	echo json_encode( array( 'store_id' => 'st_1', 'api_key' => 'sk_mock_secret' ) );
} elseif ( preg_match( '#/(customers|orders):batch$#', $path, $m ) ) {
	$ids = array();
	foreach ( (array) ( $json[ $m[1] ] ?? array() ) as $i => $row ) {
		$ids[] = array( 'external_id' => $row['external_id'], 'id' => 9000 + (int) $row['external_id'] + $i );
	}
	echo json_encode( array( 'created' => count( $ids ), 'updated' => 0, 'rejected' => array(), 'ids' => $ids ) );
} elseif ( preg_match( '#/customers/([^/]+)/export$#', $path, $m ) ) {
	echo json_encode( array( 'store_id' => 1, 'customer' => array( 'external_id' => $m[1], 'email' => 'new@example.test', 'orders_count' => 1 ), 'orders' => array(), 'profile' => null ) );
} elseif ( 'DELETE' === $_SERVER['REQUEST_METHOD'] && preg_match( '#/customers/([^/]+)$#', $path, $m ) ) {
	echo json_encode( array( 'store_id' => 1, 'external_id' => $m[1], 'customers_redacted' => 1, 'orders_redacted' => 1, 'profile_erased' => true ) );
} elseif ( preg_match( '#/checks$#', $path ) ) {
	echo json_encode( array( 'profile' => new stdClass(), 'labels' => array( 'review_before_shipping' ) ) );
} elseif ( preg_match( '#/settings$#', $path ) ) {
	echo json_encode( array( 'status' => 'active', 'settings' => array( 'order_any_customer' => true, 'manual_only' => false ) ) );
} elseif ( 'DELETE' === $_SERVER['REQUEST_METHOD'] ) {
	http_response_code( 204 );
} else {
	http_response_code( 404 );
	echo json_encode( array( 'detail' => array( 'code' => 'not_found' ) ) );
}
