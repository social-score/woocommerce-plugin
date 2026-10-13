<?php
// Part of the smoke flow; each part runs in a fresh wp process.
function ss_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "ASSERT FAILED: $msg\n" );
		exit( 1 );
	}
	echo "ok - $msg\n";
}
// The REST nonce, over real HTTP with a login cookie: no nonce is refused, a valid nonce is served.
$cookies = array();
$detail  = '';
for ( $try = 0; $try < 4 && count( $cookies ) < 2; $try++ ) {
	if ( $try ) {
		sleep( 3 );
	}
	$login   = wp_remote_post(
		'http://wordpress/wp-login.php',
		array(
			'redirection' => 0,
			'timeout'     => 60,
			'cookies'     => array( 'wordpress_test_cookie' => 'WP Cookie check' ),
			'body'        => array( 'log' => 'admin', 'pwd' => 'admin', 'wp-submit' => 'Log In', 'testcookie' => 1 ),
		)
	);
	$detail  = is_wp_error( $login ) ? $login->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $login );
	$cookies = is_wp_error( $login ) ? array() : wp_remote_retrieve_cookies( $login );
}
ss_assert( count( $cookies ) > 1, 'logged in over HTTP (' . $detail . ')' );
$nonce = wp_remote_retrieve_body( wp_remote_get( 'http://wordpress/wp-admin/admin-ajax.php?action=rest-nonce', array( 'cookies' => $cookies, 'timeout' => 60 ) ) );
$url   = 'http://wordpress/?rest_route=/socialscore/v1/proxy/api/v2/stores/st_1/settings';
$no    = wp_remote_get( $url, array( 'cookies' => $cookies, 'timeout' => 60 ) );
ss_assert( in_array( wp_remote_retrieve_response_code( $no ), array( 401, 403 ), true ), 'HTTP proxy call without the REST nonce is refused (got ' . ( is_wp_error( $no ) ? $no->get_error_message() : wp_remote_retrieve_response_code( $no ) ) . ')' );
$yes = wp_remote_get( $url, array( 'cookies' => $cookies, 'timeout' => 60, 'headers' => array( 'X-WP-Nonce' => $nonce ) ) );
ss_assert( 200 === wp_remote_retrieve_response_code( $yes ) && false === strpos( wp_remote_retrieve_body( $yes ), 'sk_mock' ), 'HTTP proxy call with the nonce works and the body has no key' );
$key_leak = array_filter( array_keys( wp_remote_retrieve_headers( $yes )->getAll() ), fn( $h ) => false !== stripos( $h, 'api-key' ) );
ss_assert( array() === $key_leak, 'no key header in the proxy response' );


// An encoded guest id must pass the allow-list on plain and pretty permalinks (the mock API answers 404 for the profile itself).
$guest = rawurlencode( 'guest-' . sha1( 'a@b.bg' ) );
foreach ( array(
	'plain'  => 'http://wordpress/?rest_route=/socialscore/v1/proxy/api/v2/stores/st_1/customers/' . $guest . '/profile',
	'pretty' => 'http://wordpress/wp-json/socialscore/v1/proxy/api/v2/stores/st_1/customers/' . rawurlencode( 'guest:abc' ) . '/profile',
) as $label => $guest_url ) {
	$res  = wp_remote_get( $guest_url, array( 'cookies' => $cookies, 'timeout' => 60, 'headers' => array( 'X-WP-Nonce' => $nonce ) ) );
	$code = is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_response_code( $res );
	ss_assert( ! is_wp_error( $res ) && false === strpos( wp_remote_retrieve_body( $res ), 'socialscore_path_not_allowed' ) && false !== strpos( wp_remote_retrieve_body( $res ), 'not_found' ), "encoded guest id is forwarded to the API ($label permalinks, HTTP $code)" );
}
