<?php
/**
 * Uninstall handler. Removes the options, any leftover send locks and the sync
 * log table — before 0.2.0 the table was left in the database forever.
 *
 * @package CcOneboxSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'cc_onebox_settings', 'cc_onebox_version', 'cc_onebox_crypto_key' ) as $cc_onebox_option ) {
	delete_option( $cc_onebox_option );
}

delete_transient( 'cc_onebox_api_token' );
delete_transient( 'cc_onebox_default_workflow' );

// Locks are plain option rows written with a raw INSERT (see Sender).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock rows, no cache to clear on uninstall.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'cc_onebox_lock_' ) . '%' ) );

$cc_onebox_table = $wpdb->prefix . 'ccob_log';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- custom table.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $cc_onebox_table ) );

// The per-order OneBox ids are deliberately left in place: they are the guard
// that keeps a reinstall from creating a second copy of an old order in the CRM.
