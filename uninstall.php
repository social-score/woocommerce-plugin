<?php
/**
 * Removes the plugin's data. Nothing is sent to SocialScore: disconnect from the SocialScore page
 * before deleting the plugin if you also want the store removed there.
 *
 * @package SocialScore
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'socialscore_connection' );
delete_option( 'socialscore_sync_state' );
delete_transient( 'socialscore_trigger_settings' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'socialscore' );
} else {
	global $wpdb;
	$socialscore_table = $wpdb->prefix . 'actionscheduler_actions';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup of our own pending actions when Action Scheduler is not loaded.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $socialscore_table ) ) ) === $socialscore_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE hook LIKE %s AND status = 'pending'", $socialscore_table, $wpdb->esc_like( 'socialscore_' ) . '%' ) );
	}
}

delete_metadata( 'user', 0, '_socialscore_labels', '', true );
delete_metadata( 'user', 0, '_socialscore_checked', '', true );
