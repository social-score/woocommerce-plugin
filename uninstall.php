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

// Pending connect attempts: transient names carry a hash, so they are found by prefix.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_socialscore_pkce_' ) . '%', $wpdb->esc_like( '_transient_timeout_socialscore_pkce_' ) . '%' ) );

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

// Order meta: classic post meta, and the HPOS meta table when it exists.
delete_post_meta_by_key( '_socialscore_labels' );
delete_post_meta_by_key( '_socialscore_checked' );
$socialscore_orders_meta = $wpdb->prefix . 'wc_orders_meta';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $socialscore_orders_meta ) ) ) === $socialscore_orders_meta ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key IN (%s, %s)', $socialscore_orders_meta, '_socialscore_labels', '_socialscore_checked' ) );
}
