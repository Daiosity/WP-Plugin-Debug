<?php
/**
 * Stores focused validation mode state for deeper trace capture.
 *
 * Validation mode is intentionally narrow. It helps users validate one
 * specific pair, hook, handle, route, or action without pretending the plugin
 * can prove causality from broad telemetry alone.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ValidationModeRepository {
	/**
	 * Active validation option key.
	 */
	private const ACTIVE_OPTION_KEY = 'pcd_active_validation_mode';

	/**
	 * Last validation option key.
	 */
	private const LAST_OPTION_KEY = 'pcd_last_validation_mode';

	/**
	 * Validation mode lifetime in seconds.
	 */
	private const VALIDATION_TTL = 1800;

	/**
	 * Returns the active validation mode if it is still valid.
	 *
	 * @return array<string, mixed>
	 */
	public function get_active(): array {
		$mode = get_option( self::ACTIVE_OPTION_KEY, array() );
		$mode = is_array( $mode ) ? $mode : array();

		if ( empty( $mode['id'] ) ) {
			return array();
		}

		$expires_at = strtotime( (string) ( $mode['expires_at'] ?? '' ) );
		if ( false !== $expires_at && $expires_at < time() ) {
			$this->archive_active( 'expired' );
			return array();
		}

		return $mode;
	}

	/**
	 * Returns the last completed validation mode.
	 *
	 * @return array<string, mixed>
	 */
	public function get_last(): array {
		$mode = get_option( self::LAST_OPTION_KEY, array() );
		return is_array( $mode ) ? $mode : array();
	}

	/**
	 * Starts a focused validation mode.
	 *
	 * @param array<string, mixed> $payload Validation payload.
	 * @return array<string, mixed>
	 */
	public function start( array $payload ): array {
		$this->archive_active( 'replaced' );

		$target_type  = sanitize_key( (string) ( $payload['target_type'] ?? 'plugin_pair' ) );
		$target_value = sanitize_text_field( (string) ( $payload['target_value'] ?? '' ) );
		$plugin_a     = sanitize_key( (string) ( $payload['plugin_a'] ?? '' ) );
		$plugin_b     = sanitize_key( (string) ( $payload['plugin_b'] ?? '' ) );

		if ( '' === $target_type || ! isset( $this->get_supported_targets()[ $target_type ] ) ) {
			$target_type = 'plugin_pair';
		}

		$mode = array(
			'id'               => wp_generate_uuid4(),
			'status'           => 'active',
			'target_type'      => $target_type,
			'target_value'     => $target_value,
			'plugin_a'         => $plugin_a,
			'plugin_b'         => $plugin_b,
			'label'            => $this->build_label( $target_type, $target_value, $plugin_a, $plugin_b ),
			'started_at'       => current_time( 'mysql' ),
			'last_activity_at' => '',
			'expires_at'       => gmdate( 'Y-m-d H:i:s', time() + self::VALIDATION_TTL ),
		);

		update_option( self::ACTIVE_OPTION_KEY, $mode, false );

		return $mode;
	}

	/**
	 * Records matching validation activity.
	 *
	 * @param string $mode_id Validation mode identifier.
	 * @return void
	 */
	public function touch_activity( string $mode_id ): void {
		$mode = $this->get_active();
		if ( empty( $mode['id'] ) || $mode_id !== (string) $mode['id'] ) {
			return;
		}

		$mode['last_activity_at'] = current_time( 'mysql' );
		update_option( self::ACTIVE_OPTION_KEY, $mode, false );
	}

	/**
	 * Ends the active validation mode.
	 *
	 * @param string $reason End reason.
	 * @return array<string, mixed>
	 */
	public function end( string $reason = 'completed' ): array {
		$mode = $this->archive_active( $reason );
		return is_array( $mode ) ? $mode : array();
	}

	/**
	 * Deletes stored validation mode state.
	 *
	 * @return void
	 */
	public function delete(): void {
		delete_option( self::ACTIVE_OPTION_KEY );
		delete_option( self::LAST_OPTION_KEY );
	}

	/**
	 * Returns supported validation targets for the UI.
	 *
	 * @return array<string, string>
	 */
	public function get_supported_targets(): array {
		return array(
			'plugin_pair'  => __( 'Plugin pair', 'conflict-debugger' ),
			'hook'         => __( 'Hook / execution surface', 'conflict-debugger' ),
			'asset_handle' => __( 'Asset handle', 'conflict-debugger' ),
			'rest_route'   => __( 'REST route', 'conflict-debugger' ),
			'ajax_action'  => __( 'AJAX action', 'conflict-debugger' ),
		);
	}

	/**
	 * Decorates a runtime event with validation metadata if it matches the active mode.
	 *
	 * @param array<string, mixed> $event Runtime event payload.
	 * @return array<string, mixed>
	 */
	public function decorate_event( array $event ): array {
		$mode = $this->get_active();
		if ( empty( $mode['id'] ) ) {
			return $event;
		}

		$matched = $this->payload_matches_mode( $event, $mode );

		$event['validation_mode_id']     = (string) $mode['id'];
		$event['validation_target_type'] = (string) ( $mode['target_type'] ?? '' );
		$event['validation_target_value']= (string) ( $mode['target_value'] ?? '' );
		$event['validation_label']       = (string) ( $mode['label'] ?? '' );
		$event['validation_matched']     = $matched;

		if ( $matched ) {
			$this->touch_activity( (string) $mode['id'] );
		}

		return $event;
	}

	/**
	 * Decorates a request context payload with validation metadata if matched.
	 *
	 * @param array<string, mixed> $context Request context payload.
	 * @return array<string, mixed>
	 */
	public function decorate_context( array $context ): array {
		$mode = $this->get_active();
		if ( empty( $mode['id'] ) ) {
			return $context;
		}

		$matched = $this->payload_matches_mode( $context, $mode );

		$context['validation_mode_id']      = (string) $mode['id'];
		$context['validation_target_type']  = (string) ( $mode['target_type'] ?? '' );
		$context['validation_target_value'] = (string) ( $mode['target_value'] ?? '' );
		$context['validation_label']        = (string) ( $mode['label'] ?? '' );
		$context['validation_matched']      = $matched;

		if ( $matched ) {
			$this->touch_activity( (string) $mode['id'] );
		}

		return $context;
	}

	/**
	 * Archives the current active validation mode into the last-mode slot.
	 *
	 * @param string $reason End reason.
	 * @return array<string, mixed>
	 */
	private function archive_active( string $reason ): array {
		$mode = get_option( self::ACTIVE_OPTION_KEY, array() );
		$mode = is_array( $mode ) ? $mode : array();

		if ( empty( $mode['id'] ) ) {
			return array();
		}

		$mode['status']   = sanitize_key( $reason );
		$mode['ended_at'] = current_time( 'mysql' );

		update_option( self::LAST_OPTION_KEY, $mode, false );
		delete_option( self::ACTIVE_OPTION_KEY );

		return $mode;
	}

	/**
	 * Returns whether a request/event payload matches the active validation mode.
	 *
	 * @param array<string, mixed> $payload Request or event payload.
	 * @param array<string, mixed> $mode Validation mode.
	 * @return bool
	 */
	private function payload_matches_mode( array $payload, array $mode ): bool {
		$target_type  = sanitize_key( (string) ( $mode['target_type'] ?? '' ) );
		$target_value = sanitize_text_field( strtolower( (string) ( $mode['target_value'] ?? '' ) ) );
		$plugin_a     = sanitize_key( (string) ( $mode['plugin_a'] ?? '' ) );
		$plugin_b     = sanitize_key( (string) ( $mode['plugin_b'] ?? '' ) );
		$owner_slugs  = $this->collect_owner_slugs( $payload );

		if ( '' !== $plugin_a && ! in_array( $plugin_a, $owner_slugs, true ) ) {
			return false;
		}

		if ( '' !== $plugin_b && ! in_array( $plugin_b, $owner_slugs, true ) ) {
			return false;
		}

		if ( 'plugin_pair' === $target_type ) {
			return '' !== $plugin_a || '' !== $plugin_b;
		}

		$resource_hints     = array_values( array_filter( array_map( 'strval', is_array( $payload['resource_hints'] ?? null ) ? $payload['resource_hints'] : array() ) ) );
		$request_scope      = strtolower( sanitize_text_field( (string) ( $payload['request_scope'] ?? '' ) ) );
		$execution_surface  = strtolower( sanitize_text_field( (string) ( $payload['execution_surface'] ?? $payload['hook'] ?? '' ) ) );
		$resource_key       = strtolower( sanitize_text_field( (string) ( $payload['resource_key'] ?? $payload['resource'] ?? '' ) ) );

		if ( 'hook' === $target_type ) {
			if ( '' === $target_value ) {
				return false;
			}

			if ( $execution_surface === $target_value ) {
				return true;
			}

			return in_array( 'hook:' . $target_value, array_map( 'strtolower', $resource_hints ), true );
		}

		if ( 'asset_handle' === $target_type ) {
			if ( '' === $target_value ) {
				return false;
			}

			if ( $resource_key === $target_value ) {
				return true;
			}

			return in_array( 'asset:' . $target_value, array_map( 'strtolower', $resource_hints ), true );
		}

		if ( 'rest_route' === $target_type ) {
			if ( '' === $target_value ) {
				return false;
			}

			if ( false !== strpos( $request_scope, 'rest:' . $target_value ) ) {
				return true;
			}

			return in_array( 'rest:' . $target_value, array_map( 'strtolower', $resource_hints ), true );
		}

		if ( 'ajax_action' === $target_type ) {
			if ( '' === $target_value ) {
				return false;
			}

			if ( false !== strpos( $request_scope, 'ajax:' . $target_value ) ) {
				return true;
			}

			return in_array( 'ajax:' . $target_value, array_map( 'strtolower', $resource_hints ), true );
		}

		return false;
	}

	/**
	 * Builds a readable label for the validation mode.
	 *
	 * @param string $target_type Target type.
	 * @param string $target_value Target value.
	 * @param string $plugin_a Plugin A.
	 * @param string $plugin_b Plugin B.
	 * @return string
	 */
	private function build_label( string $target_type, string $target_value, string $plugin_a, string $plugin_b ): string {
		$target_labels = $this->get_supported_targets();
		$parts         = array();

		if ( isset( $target_labels[ $target_type ] ) ) {
			$parts[] = $target_labels[ $target_type ];
		}

		if ( '' !== $plugin_a ) {
			$parts[] = $plugin_a;
		}

		if ( '' !== $plugin_b ) {
			$parts[] = $plugin_b;
		}

		if ( '' !== $target_value ) {
			$parts[] = $target_value;
		}

		if ( empty( $parts ) ) {
			return __( 'Focused validation', 'conflict-debugger' );
		}

		return implode( ' - ', $parts );
	}

	/**
	 * Collects plugin owner slugs from a payload.
	 *
	 * @param array<string, mixed> $payload Runtime payload.
	 * @return string[]
	 */
	private function collect_owner_slugs( array $payload ): array {
		$owner_slugs = is_array( $payload['owner_slugs'] ?? null ) ? array_values( array_map( 'sanitize_key', $payload['owner_slugs'] ) ) : array();
		$owner_slugs[] = sanitize_key( (string) ( $payload['actor_slug'] ?? '' ) );
		$owner_slugs[] = sanitize_key( (string) ( $payload['target_owner_slug'] ?? '' ) );

		return array_values( array_unique( array_filter( $owner_slugs ) ) );
	}
}
