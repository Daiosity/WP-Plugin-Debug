<?php
/**
 * Plugin Name: Plugin Conflict Debugger
 * Plugin URI: https://example.com/plugin-conflict-debugger
 * Description: Find likely plugin conflicts before you waste hours disabling plugins manually.
 * Version: 1.0.28
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Author: Christo Theron
 * Text Domain: plugin-conflict-debugger
 * Domain Path: /languages
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PCD_VERSION', '1.0.28' );
define( 'PCD_FILE', __FILE__ );
define( 'PCD_DIR', plugin_dir_path( __FILE__ ) );
define( 'PCD_URL', plugin_dir_url( __FILE__ ) );
define( 'PCD_BASENAME', plugin_basename( __FILE__ ) );

$pcd_autoloader_candidates = array(
	__DIR__ . '/includes/Autoloader.php',
);

$pcd_nested_autoloaders = glob( __DIR__ . '/*/includes/Autoloader.php' );
if ( is_array( $pcd_nested_autoloaders ) ) {
	$pcd_autoloader_candidates = array_merge( $pcd_autoloader_candidates, $pcd_nested_autoloaders );
}

$pcd_autoloader = '';
foreach ( array_unique( $pcd_autoloader_candidates ) as $pcd_candidate ) {
	if ( is_string( $pcd_candidate ) && is_readable( $pcd_candidate ) ) {
		$pcd_autoloader = $pcd_candidate;
		break;
	}
}

if ( '' === $pcd_autoloader ) {
	if ( function_exists( 'error_log' ) ) {
		error_log( 'Plugin Conflict Debugger: Autoloader.php could not be located. Check plugin ZIP structure and ensure the includes directory was extracted.' );
	}

	return;
}

require_once $pcd_autoloader;

\PluginConflictDebugger\Autoloader::register();

add_action(
	'plugins_loaded',
	static function (): void {
		$plugin = new \PluginConflictDebugger\Plugin();
		$plugin->boot();
	}
);
