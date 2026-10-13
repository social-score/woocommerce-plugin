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

// Deactivate and uninstall cleanup.
echo "flow done\n";
