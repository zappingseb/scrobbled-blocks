<?php
/**
 * Uninstall Scrobbled Blocks
 *
 * Removes all plugin data when the plugin is uninstalled.
 *
 * @package ScrobbledBlocks
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options.
// Settings are stored as one serialised array under Scrobbled_Blocks_Settings::OPTION_NAME.
// That class is not loaded during uninstall, so the option name is repeated here.
delete_option( 'scrobbled_blocks_settings' );

// Delete all transients created by the plugin.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk transient cleanup requires direct query.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_scrobbled_blocks_%',
		'_transient_timeout_scrobbled_blocks_%'
	)
);
