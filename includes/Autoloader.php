<?php
/**
 * PSR-4 style autoloader for the plugin namespace.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Autoloader {
	/**
	 * Registers the autoloader.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads a class file when it belongs to the plugin namespace.
	 *
	 * @param string $class Fully qualified class name.
	 * @return void
	 */
	public static function load( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$relative_path  = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';
		$file           = PCD_DIR . 'includes/' . $relative_path;

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
