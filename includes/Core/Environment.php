<?php
/**
 * Environment snapshot builder.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Environment {
	/**
	 * Returns an environment snapshot.
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot(): array {
		$theme = wp_get_theme();
		$memory_limit = defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : ini_get( 'memory_limit' );
		$max_memory   = defined( 'WP_MAX_MEMORY_LIMIT' ) ? (string) WP_MAX_MEMORY_LIMIT : $memory_limit;

		return array(
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'active_theme'      => $theme->exists() ? $theme->get( 'Name' ) : __( 'Unknown', 'conflict-debugger' ),
			'is_multisite'      => is_multisite(),
			'memory_limit'      => wp_convert_hr_to_bytes( $memory_limit ?: '0' ),
			'max_memory_limit'  => wp_convert_hr_to_bytes( $max_memory ?: '0' ),
			'wp_debug'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_log'      => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'locale'            => get_locale(),
			'site_url'          => home_url(),
		);
	}
}
