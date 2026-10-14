<?php
// Part of the smoke flow; each part runs in a fresh wp process.
function ss_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "ASSERT FAILED: $msg\n" );
		exit( 1 );
	}
	echo "ok - $msg\n";
}
use SocialScore\Connect;
use SocialScore\Connection;

function ss_log() {
	$lines = file_exists( '/shared/api.log' ) ? file( '/shared/api.log', FILE_IGNORE_NEW_LINES ) : array();
	return array_map( fn( $l ) => json_decode( $l, true ), $lines );
}

// Disconnect.
@unlink( '/shared/api.log' );
Connect::disconnect();
ss_assert( ! Connection::is_connected(), 'disconnected' );
ss_assert( 'DELETE' === ss_log()[0]['method'], 'DELETE /stores/{id} sent' );
$pending = as_get_scheduled_actions( array( 'group' => 'socialscore', 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' );
ss_assert( array() === $pending, 'no pending actions after disconnect' );

// A store key SocialScore no longer accepts (uninstalled there): the 401 drops the key and the admin page asks to connect again.
Connection::save( 'st_gone', 'sk_dead' );
$res = ( new \SocialScore\Api_Client() )->get_settings();
ss_assert( 401 === $res['status'], 'mock answered 401 for the dead store' );
ss_assert( ! Connection::is_connected() && Connection::was_lost(), 'a 401 forgets the key and remembers it was lost' );
wp_set_current_user( 1 );
ob_start();
( new \SocialScore\Admin_Page() )->render();
$page = ob_get_clean();
ss_assert( false !== strpos( $page, 'no longer accepts' ) && false !== strpos( $page, 'data-connected="0"' ), 'admin page shows the connect screen with the reason' );
ss_assert( 'connected' === ( new Connect() )->complete( ( function () {
	parse_str( (string) wp_parse_url( Connect::begin( 1 ), PHP_URL_QUERY ), $q );
	return $q['state'];
} )(), 'auth-code', '', 1 ), 'connecting again works' );
ss_assert( ! Connection::was_lost(), 'connecting again clears the lost flag' );

// Deactivate and uninstall cleanup.
echo "flow done\n";
