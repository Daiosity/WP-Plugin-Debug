<?php
/**
 * Cleanup on uninstall.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pcd_latest_scan_result' );
delete_option( 'pcd_scan_history' );
delete_option( 'pcd_runtime_log' );
delete_option( 'pcd_recent_plugin_changes' );
delete_option( 'pcd_scan_state' );
delete_option( 'pcd_admin_menu_snapshot' );
delete_option( 'pcd_runtime_events' );
delete_option( 'pcd_request_contexts' );
delete_option( 'pcd_shortcode_snapshot' );
delete_option( 'pcd_block_snapshot' );
delete_option( 'pcd_ajax_action_snapshot' );
delete_option( 'pcd_asset_handle_snapshot' );
delete_option( 'pcd_active_diagnostic_session' );
delete_option( 'pcd_last_diagnostic_session' );
