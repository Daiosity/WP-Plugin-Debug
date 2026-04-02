<?php
/**
 * Tracks recent plugin changes for scan correlation.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PluginChangeTracker {
	/**
	 * Option key.
	 */
	private const OPTION_KEY = 'pcd_recent_plugin_changes';

	/**
	 * Registers change-tracking hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'activated_plugin', array( $this, 'track_activation' ) );
		add_action( 'deactivated_plugin', array( $this, 'track_deactivation' ) );
		add_action( 'upgrader_process_complete', array( $this, 'track_upgrade' ), 10, 2 );
	}

	/**
	 * Tracks plugin activation.
	 *
	 * @param string $plugin Plugin file.
	 * @return void
	 */
	public function track_activation( string $plugin ): void {
		$this->record_change( $plugin, 'activated' );
	}

	/**
	 * Tracks plugin deactivation.
	 *
	 * @param string $plugin Plugin file.
	 * @return void
	 */
	public function track_deactivation( string $plugin ): void {
		$this->record_change( $plugin, 'deactivated' );
	}

	/**
	 * Tracks plugin update/install completion.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $hook_extra Extra hook context.
	 * @return void
	 */
	public function track_upgrade( \WP_Upgrader $upgrader, array $hook_extra ): void {
		unset( $upgrader );

		if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}

		if ( empty( $hook_extra['plugins'] ) || ! is_array( $hook_extra['plugins'] ) ) {
			return;
		}

		$action = ! empty( $hook_extra['action'] ) ? sanitize_key( (string) $hook_extra['action'] ) : 'updated';

		foreach ( $hook_extra['plugins'] as $plugin ) {
			$this->record_change( (string) $plugin, $action );
		}
	}

	/**
	 * Determines whether a plugin changed recently.
	 *
	 * @param string $plugin_file Plugin file.
	 * @param int    $window_seconds Window in seconds.
	 * @return bool
	 */
	public function was_recently_changed( string $plugin_file, int $window_seconds = DAY_IN_SECONDS * 7 ): bool {
		$changes = $this->get_changes();
		$now     = time();

		foreach ( $changes as $change ) {
			if ( ( $change['plugin_file'] ?? '' ) !== $plugin_file ) {
				continue;
			}

			if ( empty( $change['timestamp'] ) ) {
				continue;
			}

			$timestamp = strtotime( (string) $change['timestamp'] );
			if ( $timestamp && ( $now - $timestamp ) <= $window_seconds ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns recent changes.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_changes(): array {
		$changes = get_option( self::OPTION_KEY, array() );
		return is_array( $changes ) ? $changes : array();
	}

	/**
	 * Returns a count of recent changes in the time window.
	 *
	 * @param int $window_seconds Window in seconds.
	 * @return int
	 */
	public function count_recent_changes( int $window_seconds = DAY_IN_SECONDS * 7 ): int {
		$now = time();

		return count(
			array_filter(
				$this->get_changes(),
				static function ( array $change ) use ( $now, $window_seconds ): bool {
					if ( empty( $change['timestamp'] ) ) {
						return false;
					}

					$timestamp = strtotime( (string) $change['timestamp'] );
					return (bool) ( $timestamp && ( $now - $timestamp ) <= $window_seconds );
				}
			)
		);
	}

	/**
	 * Returns option key.
	 *
	 * @return string
	 */
	public static function option_key(): string {
		return self::OPTION_KEY;
	}

	/**
	 * Records a plugin change.
	 *
	 * @param string $plugin_file Plugin file.
	 * @param string $action Action label.
	 * @return void
	 */
	private function record_change( string $plugin_file, string $action ): void {
		$changes   = $this->get_changes();
		$slug_bits = explode( '/', $plugin_file );

		$changes[] = array(
			'plugin_file' => sanitize_text_field( $plugin_file ),
			'plugin_slug' => sanitize_key( $slug_bits[0] ?? $plugin_file ),
			'action'      => sanitize_key( $action ),
			'timestamp'   => current_time( 'mysql' ),
		);

		$changes = array_slice( $changes, -40 );
		update_option( self::OPTION_KEY, $changes, false );
	}
}
