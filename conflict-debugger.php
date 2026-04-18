<?php
/**
 * Plugin Name: Conflict Debugger
 * Plugin URI: https://github.com/Daiosity/Conflict-Debugger
 * Description: Find likely plugin conflicts before you waste hours disabling plugins manually.
 * Version: 1.1.3
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Author: Christo Theron
 * Author URI: https://github.com/Daiosity
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: conflict-debugger
 * Domain Path: /languages
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PCD_VERSION', '1.1.3' );
define( 'PCD_FILE', __FILE__ );
define( 'PCD_DIR', plugin_dir_path( __FILE__ ) );
define( 'PCD_URL', plugin_dir_url( __FILE__ ) );
define( 'PCD_BASENAME', plugin_basename( __FILE__ ) );

( static function (): void {
	$pcd_bootstrap_autoloader = '';
	$pcd_bootstrap_candidates = array(
		__DIR__ . '/includes/Autoloader.php',
	);
	$pcd_bootstrap_nested     = glob( __DIR__ . '/*/includes/Autoloader.php' );

	if ( is_array( $pcd_bootstrap_nested ) ) {
		$pcd_bootstrap_candidates = array_merge( $pcd_bootstrap_candidates, $pcd_bootstrap_nested );
	}

	foreach ( array_unique( $pcd_bootstrap_candidates ) as $pcd_bootstrap_candidate ) {
		if ( is_string( $pcd_bootstrap_candidate ) && is_readable( $pcd_bootstrap_candidate ) ) {
			$pcd_bootstrap_autoloader = $pcd_bootstrap_candidate;
			break;
		}
	}

	if ( '' === $pcd_bootstrap_autoloader ) {
		add_action(
			'admin_notices',
			static function (): void {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__(
						'Conflict Debugger could not locate its autoloader. Reinstall the plugin and confirm the ZIP preserved the includes directory.',
						'conflict-debugger'
					)
				);
			}
		);

		return;
	}

	require_once $pcd_bootstrap_autoloader;

	\PluginConflictDebugger\Autoloader::register();

	$pcd_bootstrap_plugin = new \PluginConflictDebugger\Plugin();
	$pcd_bootstrap_plugin->boot();
} )();
