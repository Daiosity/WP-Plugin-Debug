<?php
/**
 * Error signal collector.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

use PluginConflictDebugger\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ErrorCollector {
	/**
	 * Runtime logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Runtime telemetry repository.
	 *
	 * @var RuntimeTelemetryRepository
	 */
	private RuntimeTelemetryRepository $telemetry;

	/**
	 * Diagnostic session repository.
	 *
	 * @var DiagnosticSessionRepository
	 */
	private DiagnosticSessionRepository $sessions;

	/**
	 * Validation mode repository.
	 *
	 * @var ValidationModeRepository
	 */
	private ValidationModeRepository $validation;

	/**
	 * Constructor.
	 *
	 * @param Logger                     $logger Runtime logger.
	 * @param RuntimeTelemetryRepository $telemetry Telemetry repository.
	 */
	public function __construct( Logger $logger, RuntimeTelemetryRepository $telemetry, DiagnosticSessionRepository $sessions, ValidationModeRepository $validation ) {
		$this->logger    = $logger;
		$this->telemetry = $telemetry;
		$this->sessions  = $sessions;
		$this->validation = $validation;
	}

	/**
	 * Collects current error signals.
	 *
	 * @return array<string, mixed>
	 */
	public function collect(): array {
		$runtime_events = $this->telemetry->get_events( 25 );

		$signals = array(
			'entries'          => array(),
			'logs_unavailable' => false,
			'notes'            => array(),
			'request_contexts' => $this->telemetry->get_request_contexts( 12 ),
			'runtime_events'   => $runtime_events,
			'diagnostic_session' => array(
				'active' => $this->sessions->get_active(),
				'last'   => $this->sessions->get_last(),
			),
			'validation_mode'   => array(
				'active' => $this->validation->get_active(),
				'last'   => $this->validation->get_last(),
			),
			'log_access'       => $this->build_log_access_report(),
		);

		$debug_log = (string) ( $signals['log_access']['path'] ?? '' );

		if ( ! empty( $signals['log_access']['readable'] ) && '' !== $debug_log ) {
			$signals['entries'] = $this->read_recent_log_entries( $debug_log );
		} else {
			$signals['logs_unavailable'] = true;
			$signals['notes'][]          = __( 'Direct log access is unavailable. Analysis is based on runtime and plugin interaction signals.', 'conflict-debugger' );
		}

		$last_error = error_get_last();
		if ( ! empty( $last_error ) && empty( $signals['log_access']['readable'] ) ) {
			$last_error_entry = array(
				'type'    => 'runtime',
				'level'   => (string) ( $last_error['type'] ?? 'unknown' ),
				'message' => (string) ( $last_error['message'] ?? '' ),
				'file'    => (string) ( $last_error['file'] ?? '' ),
				'line'    => (int) ( $last_error['line'] ?? 0 ),
			);

			if ( ! $this->contains_equivalent_summary_signal( $signals['entries'], $last_error_entry ) ) {
				$signals['entries'][] = $last_error_entry;
			}
		}

		$recovery_mode = get_option( 'recovery_keys', array() );
		if ( ! empty( $recovery_mode ) ) {
			$signals['notes'][] = __( 'Recovery mode data exists, which may indicate a recent fatal error event.', 'conflict-debugger' );
		}

		foreach ( $this->logger->get_entries() as $entry ) {
			$signals['entries'][] = array(
				'type'    => 'plugin-runtime',
				'level'   => (string) ( $entry['level'] ?? 'info' ),
				'message' => (string) ( $entry['message'] ?? '' ),
				'file'    => '',
				'line'    => 0,
			);
		}

		foreach ( $runtime_events as $event ) {
			$signals['entries'][] = array(
				'event_id'             => (string) ( $event['event_id'] ?? '' ),
				'request_id'           => (string) ( $event['request_id'] ?? '' ),
				'sequence'             => (int) ( $event['sequence'] ?? 0 ),
				'type'                 => (string) ( $event['type'] ?? 'runtime_event' ),
				'evidence_source'      => (string) ( $event['evidence_source'] ?? '' ),
				'level'                => (string) ( $event['level'] ?? 'info' ),
				'message'              => (string) ( $event['message'] ?? '' ),
				'file'                 => (string) ( $event['source'] ?? '' ),
				'line'                 => 0,
				'request_context'      => (string) ( $event['request_context'] ?? '' ),
				'request_uri'          => (string) ( $event['request_uri'] ?? '' ),
				'request_scope'        => (string) ( $event['request_scope'] ?? '' ),
				'scope_type'           => (string) ( $event['scope_type'] ?? '' ),
				'resource'             => (string) ( $event['resource'] ?? '' ),
				'resource_type'        => (string) ( $event['resource_type'] ?? '' ),
				'resource_key'         => (string) ( $event['resource_key'] ?? '' ),
				'execution_surface'    => (string) ( $event['execution_surface'] ?? '' ),
				'hook'                 => (string) ( $event['hook'] ?? '' ),
				'callback'             => (string) ( $event['callback'] ?? '' ),
				'priority'             => (int) ( $event['priority'] ?? 0 ),
				'callback_identifier'  => (string) ( $event['callback_identifier'] ?? '' ),
				'failure_mode'         => (string) ( $event['failure_mode'] ?? '' ),
				'mutation_kind'        => (string) ( $event['mutation_kind'] ?? '' ),
				'mutation_status'      => (string) ( $event['mutation_status'] ?? '' ),
				'attribution_status'   => (string) ( $event['attribution_status'] ?? '' ),
				'contamination_status' => (string) ( $event['contamination_status'] ?? '' ),
				'actor_slug'           => (string) ( $event['actor_slug'] ?? '' ),
				'target_owner_slug'    => (string) ( $event['target_owner_slug'] ?? '' ),
				'status_code'          => (int) ( $event['status_code'] ?? 0 ),
				'session_id'           => (string) ( $event['session_id'] ?? '' ),
				'validation_mode_id'   => (string) ( $event['validation_mode_id'] ?? '' ),
				'validation_target_type' => (string) ( $event['validation_target_type'] ?? '' ),
				'validation_target_value' => (string) ( $event['validation_target_value'] ?? '' ),
				'validation_label'     => (string) ( $event['validation_label'] ?? '' ),
				'validation_matched'   => ! empty( $event['validation_matched'] ),
				'resource_hints'       => is_array( $event['resource_hints'] ?? null ) ? $event['resource_hints'] : array(),
				'owner_slugs'          => is_array( $event['owner_slugs'] ?? null ) ? $event['owner_slugs'] : array(),
				'previous_state'       => is_array( $event['previous_state'] ?? null ) ? $event['previous_state'] : array(),
				'new_state'            => is_array( $event['new_state'] ?? null ) ? $event['new_state'] : array(),
			);
		}

		if ( ! empty( $signals['request_contexts'] ) ) {
			$signals['notes'][] = __( 'Recent request contexts were captured and included in this analysis.', 'conflict-debugger' );
		}

		$signals['entries']             = $this->deduplicate_entries( $signals['entries'] );
		$signals['summary_count']       = $this->count_meaningful_entries( $signals['entries'] );
		$signals['trace_summary_count'] = $this->count_trace_summary_entries( $signals['entries'] );

		if ( $signals['trace_summary_count'] > 0 ) {
			$signals['notes'][] = __( 'Trace-level mutation warnings were captured separately from PHP, log, and request error signals.', 'conflict-debugger' );
		}

		return $signals;
	}

	/**
	 * Builds a detailed log-access report for diagnostics UI.
	 *
	 * @return array<string, mixed>
	 */
	private function build_log_access_report(): array {
		$path             = $this->resolve_debug_log_path();
		$wp_debug         = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$wp_debug_log     = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
		$open_basedir     = (string) ini_get( 'open_basedir' );
		$path_exists      = '' !== (string) $path && file_exists( (string) $path );
		$path_readable    = '' !== (string) $path && is_readable( (string) $path );
		$path_writable    = '' !== (string) $path && ( $path_exists ? wp_is_writable( (string) $path ) : wp_is_writable( dirname( (string) $path ) ) );
		$status           = 'available';
		$status_message   = __( 'Debug log is enabled and readable.', 'conflict-debugger' );
		$recommendations  = array();

		if ( ! $wp_debug_log ) {
			$status         = 'disabled';
			$status_message = __( 'WP_DEBUG_LOG is disabled, so WordPress is not writing a direct debug log for this plugin to read.', 'conflict-debugger' );
			$recommendations[] = __( 'Enable WP_DEBUG_LOG in wp-config.php to create a readable WordPress debug log.', 'conflict-debugger' );
		} elseif ( '' === (string) $path ) {
			$status         = 'unresolved';
			$status_message = __( 'The debug log path could not be resolved from the current WordPress configuration.', 'conflict-debugger' );
			$recommendations[] = __( 'Check whether WP_DEBUG_LOG points to a valid file path or boolean true.', 'conflict-debugger' );
		} elseif ( ! $path_exists ) {
			$status         = 'missing';
			$status_message = __( 'Debug logging is enabled, but the log file does not exist yet.', 'conflict-debugger' );
			$recommendations[] = __( 'Trigger a PHP notice or warning in staging, or verify that the web server can create the log file.', 'conflict-debugger' );
		} elseif ( ! $path_readable ) {
			$status         = 'unreadable';
			$status_message = __( 'The debug log exists but is not readable by the current PHP process.', 'conflict-debugger' );
			$recommendations[] = __( 'Check file ownership, permissions, and any open_basedir restrictions on the server.', 'conflict-debugger' );
		}

		if ( '' !== $open_basedir ) {
			$recommendations[] = __( 'This server reports open_basedir restrictions. If the log path falls outside that scope, PHP may not be able to read it.', 'conflict-debugger' );
		}

		return array(
			'status'          => $status,
			'status_message'  => $status_message,
			'path'            => $path ? (string) $path : '',
			'wp_debug'        => $wp_debug,
			'wp_debug_log'    => $wp_debug_log,
			'exists'          => $path_exists,
			'readable'        => $path_readable,
			'writable'        => $path_writable,
			'open_basedir'    => $open_basedir,
			'recommendations' => $recommendations,
		);
	}

	/**
	 * Resolves debug.log path when enabled.
	 *
	 * @return string|null
	 */
	private function resolve_debug_log_path(): ?string {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return null;
		}

		if ( is_string( WP_DEBUG_LOG ) ) {
			return WP_DEBUG_LOG;
		}

		return trailingslashit( WP_CONTENT_DIR ) . 'debug.log';
	}

	/**
	 * Reads the most recent log entries without loading the full file into memory.
	 *
	 * @param string $debug_log Log path.
	 * @return array<int, array<string, mixed>>
	 */
	private function read_recent_log_entries( string $debug_log ): array {
		$size     = @filesize( $debug_log );
		$offset   = is_int( $size ) ? max( 0, $size - 65535 ) : 0;
		$contents = @file_get_contents( $debug_log, false, null, $offset );

		if ( false === $contents ) {
			return array();
		}

		$lines   = preg_split( '/\r\n|\r|\n/', trim( $contents ) );
		$lines   = array_slice( array_filter( (array) $lines ), -20 );
		$entries = array();

		foreach ( $lines as $line ) {
			$entries[] = array(
				'type'    => 'debug_log',
				'level'   => $this->extract_log_level( (string) $line ),
				'message' => $this->normalize_log_message( (string) $line ),
				'file'    => '',
				'line'    => 0,
			);
		}

		return $entries;
	}

	/**
	 * Deduplicates repeated signal entries while preserving a repeat count.
	 *
	 * @param array<int, array<string, mixed>> $entries Signal entries.
	 * @return array<int, array<string, mixed>>
	 */
	private function deduplicate_entries( array $entries ): array {
		$deduplicated = array();

		foreach ( $entries as $entry ) {
			$fingerprint = $this->entry_fingerprint( $entry );

			if ( ! isset( $deduplicated[ $fingerprint ] ) ) {
				$entry['repeat_count']            = 1;
				$deduplicated[ $fingerprint ] = $entry;
				continue;
			}

			$deduplicated[ $fingerprint ]['repeat_count'] = (int) ( $deduplicated[ $fingerprint ]['repeat_count'] ?? 1 ) + 1;
		}

		foreach ( $deduplicated as &$entry ) {
			$repeat_count = (int) ( $entry['repeat_count'] ?? 1 );

			if ( $repeat_count <= 1 ) {
				continue;
			}

			$entry['message'] = sprintf(
				/* translators: 1: repeat count, 2: message text. */
				__( 'Repeated %1$d times: %2$s', 'conflict-debugger' ),
				$repeat_count,
				(string) ( $entry['message'] ?? '' )
			);
		}
		unset( $entry );

		return array_values( $deduplicated );
	}

	/**
	 * Counts actual runtime/log error signals for dashboard summaries.
	 *
	 * @param array<int, array<string, mixed>> $entries Signal entries.
	 * @return int
	 */
	private function count_meaningful_entries( array $entries ): int {
		$count = 0;
		$seen  = array();

		foreach ( $entries as $entry ) {
			if ( ! $this->should_count_entry_in_summary( $entry ) ) {
				continue;
			}

			$fingerprint = $this->summary_fingerprint( $entry );
			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}

			$seen[ $fingerprint ] = true;
			++$count;
		}

		return $count;
	}

	/**
	 * Counts trace-only mutation warnings separately from real error signals.
	 *
	 * @param array<int, array<string, mixed>> $entries Signal entries.
	 * @return int
	 */
	private function count_trace_summary_entries( array $entries ): int {
		$count = 0;
		$seen  = array();

		foreach ( $entries as $entry ) {
			if ( ! $this->should_count_entry_in_trace_summary( $entry ) ) {
				continue;
			}

			$fingerprint = md5(
				wp_json_encode(
					array(
						'type'          => sanitize_key( (string) ( $entry['type'] ?? '' ) ),
						'resource_key'  => sanitize_text_field( (string) ( $entry['resource_key'] ?? $entry['resource'] ?? '' ) ),
						'hook'          => sanitize_text_field( (string) ( $entry['hook'] ?? $entry['execution_surface'] ?? '' ) ),
						'mutation_kind' => sanitize_key( (string) ( $entry['mutation_kind'] ?? '' ) ),
						'actor_slug'    => sanitize_key( (string) ( $entry['actor_slug'] ?? '' ) ),
						'target_owner'  => sanitize_key( (string) ( $entry['target_owner_slug'] ?? '' ) ),
					)
				)
			);

			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}

			$seen[ $fingerprint ] = true;
			++$count;
		}

		return $count;
	}

	/**
	 * Checks whether an equivalent summary-level signal is already present.
	 *
	 * @param array<int, array<string, mixed>> $entries Existing entries.
	 * @param array<string, mixed>              $candidate Candidate entry.
	 * @return bool
	 */
	private function contains_equivalent_summary_signal( array $entries, array $candidate ): bool {
		$candidate_fingerprint = $this->summary_fingerprint( $candidate );

		foreach ( $entries as $entry ) {
			if ( $candidate_fingerprint === $this->summary_fingerprint( $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determines whether a signal should contribute to the Error Signals summary card.
	 *
	 * @param array<string, mixed> $entry Signal entry.
	 * @return bool
	 */
	private function should_count_entry_in_summary( array $entry ): bool {
		$level       = strtolower( (string) ( $entry['level'] ?? '' ) );
		$type        = sanitize_key( (string) ( $entry['type'] ?? '' ) );
		$status_code = (int) ( $entry['status_code'] ?? 0 );
		$failure_mode = sanitize_key( (string) ( $entry['failure_mode'] ?? '' ) );

		if ( $this->is_trace_mutation_entry( $entry ) && $status_code < 400 && '' === $failure_mode ) {
			return false;
		}

		if ( $status_code >= 400 || '' !== $failure_mode ) {
			return true;
		}

		if ( in_array( $level, array( 'fatal error', 'warning', 'notice', 'deprecated', 'parse error', 'error' ), true ) ) {
			return true;
		}

		if ( 'runtime' === $type || 'plugin-runtime' === $type ) {
			return '' !== trim( (string) ( $entry['message'] ?? '' ) ) && 'info' !== $level;
		}

		return false;
	}

	/**
	 * Determines whether a signal should contribute to the Trace Warnings summary card.
	 *
	 * @param array<string, mixed> $entry Signal entry.
	 * @return bool
	 */
	private function should_count_entry_in_trace_summary( array $entry ): bool {
		if ( ! $this->is_trace_mutation_entry( $entry ) ) {
			return false;
		}

		if ( (int) ( $entry['status_code'] ?? 0 ) >= 400 || '' !== sanitize_key( (string) ( $entry['failure_mode'] ?? '' ) ) ) {
			return false;
		}

		$level           = strtolower( (string) ( $entry['level'] ?? '' ) );
		$mutation_status = sanitize_key( (string) ( $entry['mutation_status'] ?? '' ) );

		return in_array( $level, array( 'warning', 'error' ), true )
			|| in_array( $mutation_status, array( TraceEvent::MUTATION_OBSERVED, TraceEvent::MUTATION_CONFIRMED ), true );
	}

	/**
	 * Checks whether an entry is a trace-level mutation signal.
	 *
	 * @param array<string, mixed> $entry Signal entry.
	 * @return bool
	 */
	private function is_trace_mutation_entry( array $entry ): bool {
		return in_array(
			sanitize_key( (string) ( $entry['type'] ?? '' ) ),
			array( 'asset_lifecycle', 'asset_queue_mutation', 'asset_registry_mutation', 'callback_mutation' ),
			true
		);
	}

	/**
	 * Builds a stable fingerprint for entry deduplication.
	 *
	 * @param array<string, mixed> $entry Signal entry.
	 * @return string
	 */
	private function entry_fingerprint( array $entry ): string {
		return md5(
			wp_json_encode(
				array(
					'type'             => sanitize_key( (string) ( $entry['type'] ?? '' ) ),
					'level'            => strtolower( (string) ( $entry['level'] ?? '' ) ),
					'message'          => $this->normalize_log_message( (string) ( $entry['message'] ?? '' ) ),
					'resource'         => sanitize_text_field( (string) ( $entry['resource'] ?? '' ) ),
					'resource_key'     => sanitize_text_field( (string) ( $entry['resource_key'] ?? '' ) ),
					'execution_surface'=> sanitize_text_field( (string) ( $entry['execution_surface'] ?? '' ) ),
					'hook'             => sanitize_text_field( (string) ( $entry['hook'] ?? '' ) ),
					'failure_mode'     => sanitize_key( (string) ( $entry['failure_mode'] ?? '' ) ),
					'actor_slug'       => sanitize_key( (string) ( $entry['actor_slug'] ?? '' ) ),
					'target_owner_slug'=> sanitize_key( (string) ( $entry['target_owner_slug'] ?? '' ) ),
				)
			)
		);
	}

	/**
	 * Builds a summary-level fingerprint so the dashboard count reflects distinct issues.
	 *
	 * @param array<string, mixed> $entry Signal entry.
	 * @return string
	 */
	private function summary_fingerprint( array $entry ): string {
		return md5(
			wp_json_encode(
				array(
					'level'        => $this->normalize_summary_level( (string) ( $entry['level'] ?? '' ) ),
					'message'      => $this->normalize_log_message( (string) ( $entry['message'] ?? '' ) ),
					'failure_mode' => sanitize_key( (string) ( $entry['failure_mode'] ?? '' ) ),
					'status_code'  => (int) ( $entry['status_code'] ?? 0 ),
				)
			)
		);
	}

	/**
	 * Normalizes heterogeneous level formats like PHP numeric severities for summary grouping.
	 *
	 * @param string $level Raw level.
	 * @return string
	 */
	private function normalize_summary_level( string $level ): string {
		$level = strtolower( trim( $level ) );

		return match ( $level ) {
			'1024' => 'notice',
			'512'  => 'warning',
			'256', '1' => 'error',
			default => $level,
		};
	}

	/**
	 * Removes variable prefixes like timestamps from log lines before comparison.
	 *
	 * @param string $message Raw log message.
	 * @return string
	 */
	private function normalize_log_message( string $message ): string {
		$message = preg_replace( '/^\[[^\]]+\]\s*/', '', $message );
		$message = preg_replace( '/^Repeated\s+\d+\s+times:\s*/i', '', (string) $message );
		$message = preg_replace( '/^PHP\s+[A-Za-z ]+:\s*/', '', (string) $message );
		$message = preg_replace( '/\s+in\s+.+?\s+on\s+line\s+\d+\s*$/i', '', (string) $message );
		$message = is_string( $message ) ? $message : '';

		return trim( preg_replace( '/\s+/', ' ', $message ) ?? '' );
	}

	/**
	 * Extracts a broad error level from a log line.
	 *
	 * @param string $line Log line.
	 * @return string
	 */
	private function extract_log_level( string $line ): string {
		$map = array( 'fatal error', 'warning', 'notice', 'deprecated', 'parse error' );

		foreach ( $map as $level ) {
			if ( false !== stripos( $line, $level ) ) {
				return $level;
			}
		}

		return 'log';
	}
}
