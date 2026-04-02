<?php
/**
 * Scan state storage for async progress updates.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ScanStateRepository {
	/**
	 * Option key.
	 */
	private const OPTION_KEY = 'pcd_scan_state';

	/**
	 * Returns current state.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$state = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $state ) ) {
			return $this->default_state();
		}

		return wp_parse_args( $state, $this->default_state() );
	}

	/**
	 * Saves full state.
	 *
	 * @param array<string, mixed> $state Scan state.
	 * @return void
	 */
	public function save( array $state ): void {
		update_option( self::OPTION_KEY, wp_parse_args( $state, $this->default_state() ), false );
	}

	/**
	 * Marks the scan as queued.
	 *
	 * @param string $token Scan token.
	 * @return array<string, mixed>
	 */
	public function mark_queued( string $token ): array {
		$state = array(
			'status'        => 'queued',
			'progress'      => 5,
			'message'       => __( 'Scan queued. Waiting for the background worker to begin.', 'plugin-conflict-debugger' ),
			'token'         => $token,
			'started_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
			'completed_at'  => '',
			'finding_count' => 0,
			'last_error'    => '',
		);

		$this->save( $state );

		return $state;
	}

	/**
	 * Marks a scan stage as running.
	 *
	 * @param string $token Scan token.
	 * @param string $message Status message.
	 * @param int    $progress Progress percentage.
	 * @return void
	 */
	public function mark_running( string $token, string $message, int $progress ): void {
		$state               = $this->get();
		$state['status']     = 'running';
		$state['token']      = $token;
		$state['message']    = $message;
		$state['progress']   = max( 0, min( 99, $progress ) );
		$state['updated_at'] = current_time( 'mysql' );

		if ( empty( $state['started_at'] ) ) {
			$state['started_at'] = current_time( 'mysql' );
		}

		$this->save( $state );
	}

	/**
	 * Marks the scan as complete.
	 *
	 * @param string $token Scan token.
	 * @param int    $finding_count Finding count.
	 * @return void
	 */
	public function mark_complete( string $token, int $finding_count ): void {
		$state                  = $this->get();
		$state['status']        = 'complete';
		$state['token']         = $token;
		$state['progress']      = 100;
		$state['message']       = __( 'Scan complete. Results are ready to review.', 'plugin-conflict-debugger' );
		$state['updated_at']    = current_time( 'mysql' );
		$state['completed_at']  = current_time( 'mysql' );
		$state['finding_count'] = $finding_count;
		$state['last_error']    = '';

		$this->save( $state );
	}

	/**
	 * Marks the scan as failed.
	 *
	 * @param string $token Scan token.
	 * @param string $error Error message.
	 * @return void
	 */
	public function mark_failed( string $token, string $error ): void {
		$state                 = $this->get();
		$state['status']       = 'failed';
		$state['token']        = $token;
		$state['message']      = __( 'The scan did not finish successfully.', 'plugin-conflict-debugger' );
		$state['updated_at']   = current_time( 'mysql' );
		$state['completed_at'] = current_time( 'mysql' );
		$state['last_error']   = $error;

		$this->save( $state );
	}

	/**
	 * Default state.
	 *
	 * @return array<string, mixed>
	 */
	private function default_state(): array {
		return array(
			'status'        => 'idle',
			'progress'      => 0,
			'message'       => __( 'No scan is running.', 'plugin-conflict-debugger' ),
			'token'         => '',
			'started_at'    => '',
			'updated_at'    => '',
			'completed_at'  => '',
			'finding_count' => 0,
			'last_error'    => '',
		);
	}
}
