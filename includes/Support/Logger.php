<?php
/**
 * Lightweight runtime logger.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logger {
	/**
	 * Option key.
	 */
	private const OPTION_KEY = 'pcd_runtime_log';

	/**
	 * Adds a log entry.
	 *
	 * @param string $message Message text.
	 * @param string $level Message level.
	 * @return void
	 */
	public function log( string $message, string $level = 'info' ): void {
		$entries   = $this->get_entries();
		$entries[] = array(
			'timestamp' => current_time( 'mysql' ),
			'level'     => sanitize_key( $level ),
			'message'   => sanitize_text_field( $message ),
		);

		$entries = array_slice( $entries, -25 );
		update_option( self::OPTION_KEY, $entries, false );
	}

	/**
	 * Returns existing entries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_entries(): array {
		$entries = get_option( self::OPTION_KEY, array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Deletes stored entries.
	 *
	 * @return void
	 */
	public function clear(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Returns the runtime log option key.
	 *
	 * @return string
	 */
	public static function option_key(): string {
		return self::OPTION_KEY;
	}
}
