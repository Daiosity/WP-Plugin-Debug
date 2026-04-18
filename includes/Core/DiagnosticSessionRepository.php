<?php
/**
 * Stores focused diagnostic session state for guided reproductions.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DiagnosticSessionRepository {
	/**
	 * Active session option key.
	 */
	private const ACTIVE_OPTION_KEY = 'pcd_active_diagnostic_session';

	/**
	 * Last completed session option key.
	 */
	private const LAST_OPTION_KEY = 'pcd_last_diagnostic_session';

	/**
	 * Session lifetime in seconds.
	 */
	private const SESSION_TTL = 1800;

	/**
	 * Returns the active session if it is still valid.
	 *
	 * @return array<string, mixed>
	 */
	public function get_active(): array {
		$session = get_option( self::ACTIVE_OPTION_KEY, array() );
		$session = is_array( $session ) ? $session : array();

		if ( empty( $session['id'] ) ) {
			return array();
		}

		$expires_at = strtotime( (string) ( $session['expires_at'] ?? '' ) );
		if ( false !== $expires_at && $expires_at < time() ) {
			$this->archive_active( 'expired' );
			return array();
		}

		return $session;
	}

	/**
	 * Returns the last completed or expired session.
	 *
	 * @return array<string, mixed>
	 */
	public function get_last(): array {
		$session = get_option( self::LAST_OPTION_KEY, array() );
		return is_array( $session ) ? $session : array();
	}

	/**
	 * Starts a new focused diagnostic session.
	 *
	 * @param string $target_context Target request context.
	 * @return array<string, mixed>
	 */
	public function start( string $target_context ): array {
		$this->archive_active( 'replaced' );

		$target_context = sanitize_key( $target_context );
		if ( '' === $target_context ) {
			$target_context = 'all';
		}

		$session = array(
			'id'              => wp_generate_uuid4(),
			'status'          => 'active',
			'target_context'  => $target_context,
			'label'           => $this->label_for_context( $target_context ),
			'started_at'      => current_time( 'mysql' ),
			'last_activity_at'=> '',
			'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + self::SESSION_TTL ),
		);

		update_option( self::ACTIVE_OPTION_KEY, $session, false );

		return $session;
	}

	/**
	 * Records that the active session captured matching activity.
	 *
	 * @param string $session_id Session identifier.
	 * @return void
	 */
	public function touch_activity( string $session_id ): void {
		$session = $this->get_active();
		if ( empty( $session['id'] ) || $session_id !== (string) $session['id'] ) {
			return;
		}

		$session['last_activity_at'] = current_time( 'mysql' );
		update_option( self::ACTIVE_OPTION_KEY, $session, false );
	}

	/**
	 * Ends the active session.
	 *
	 * @param string $reason End reason.
	 * @return array<string, mixed>
	 */
	public function end( string $reason = 'completed' ): array {
		$session = $this->archive_active( $reason );
		return is_array( $session ) ? $session : array();
	}

	/**
	 * Deletes all stored diagnostic session data.
	 *
	 * @return void
	 */
	public function delete(): void {
		delete_option( self::ACTIVE_OPTION_KEY );
		delete_option( self::LAST_OPTION_KEY );
	}

	/**
	 * Returns supported session contexts for the UI.
	 *
	 * @return array<string, string>
	 */
	public function get_supported_contexts(): array {
		return array(
			'all'      => __( 'Any site area', 'conflict-debugger' ),
			'frontend' => __( 'Frontend', 'conflict-debugger' ),
			'admin'    => __( 'Admin', 'conflict-debugger' ),
			'ajax'     => __( 'AJAX / async request', 'conflict-debugger' ),
			'rest'     => __( 'REST API', 'conflict-debugger' ),
			'editor'   => __( 'Editor', 'conflict-debugger' ),
			'login'    => __( 'Login / account', 'conflict-debugger' ),
			'checkout' => __( 'Checkout / commerce', 'conflict-debugger' ),
			'cron'     => __( 'Cron / background job', 'conflict-debugger' ),
		);
	}

	/**
	 * Archives the current active session into the last-session slot.
	 *
	 * @param string $reason End reason.
	 * @return array<string, mixed>
	 */
	private function archive_active( string $reason ): array {
		$session = get_option( self::ACTIVE_OPTION_KEY, array() );
		$session = is_array( $session ) ? $session : array();

		if ( empty( $session['id'] ) ) {
			return array();
		}

		$session['status']   = sanitize_key( $reason );
		$session['ended_at'] = current_time( 'mysql' );

		update_option( self::LAST_OPTION_KEY, $session, false );
		delete_option( self::ACTIVE_OPTION_KEY );

		return $session;
	}

	/**
	 * Returns a UI label for a session context key.
	 *
	 * @param string $context Context key.
	 * @return string
	 */
	private function label_for_context( string $context ): string {
		$contexts = $this->get_supported_contexts();
		return $contexts[ $context ] ?? __( 'Any site area', 'conflict-debugger' );
	}
}
