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
if ( preg_match( '#/stores/token$#', $path ) ) {
	echo json_encode( array( 'store_id' => 'st_1', 'api_key' => 'sk_mock_secret' ) );
} elseif ( preg_match( '#/(customers|orders):batch$#', $path ) ) {
	echo json_encode( array( 'upserted' => 1 ) );
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
