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
	 * Constructor.
	 *
	 * @param Logger                     $logger Runtime logger.
	 * @param RuntimeTelemetryRepository $telemetry Telemetry repository.
	 */
	public function __construct( Logger $logger, RuntimeTelemetryRepository $telemetry, DiagnosticSessionRepository $sessions ) {
		$this->logger    = $logger;
		$this->telemetry = $telemetry;
		$this->sessions  = $sessions;
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
			'log_access'       => $this->build_log_access_report(),
		);

		$debug_log = (string) ( $signals['log_access']['path'] ?? '' );

		if ( ! empty( $signals['log_access']['readable'] ) && '' !== $debug_log ) {
			$signals['entries'] = $this->read_recent_log_entries( $debug_log );
		} else {
			$signals['logs_unavailable'] = true;
			$signals['notes'][]          = __( 'Direct log access is unavailable. Analysis is based on runtime and plugin interaction signals.', 'plugin-conflict-debugger' );
		}

		$last_error = error_get_last();
		if ( ! empty( $last_error ) ) {
			$signals['entries'][] = array(
				'type'    => 'runtime',
				'level'   => (string) ( $last_error['type'] ?? 'unknown' ),
				'message' => (string) ( $last_error['message'] ?? '' ),
				'file'    => (string) ( $last_error['file'] ?? '' ),
				'line'    => (int) ( $last_error['line'] ?? 0 ),
			);
		}

		$recovery_mode = get_option( 'recovery_keys', array() );
		if ( ! empty( $recovery_mode ) ) {
			$signals['notes'][] = __( 'Recovery mode data exists, which may indicate a recent fatal error event.', 'plugin-conflict-debugger' );
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
				'type'            => (string) ( $event['type'] ?? 'runtime_event' ),
				'level'           => (string) ( $event['level'] ?? 'info' ),
				'message'         => (string) ( $event['message'] ?? '' ),
				'file'            => (string) ( $event['source'] ?? '' ),
				'line'            => 0,
				'request_context' => (string) ( $event['request_context'] ?? '' ),
				'request_uri'     => (string) ( $event['request_uri'] ?? '' ),
				'resource'        => (string) ( $event['resource'] ?? '' ),
				'execution_surface' => (string) ( $event['execution_surface'] ?? '' ),
				'callback_identifier' => (string) ( $event['callback_identifier'] ?? '' ),
				'failure_mode'    => (string) ( $event['failure_mode'] ?? '' ),
				'mutation_kind'   => (string) ( $event['mutation_kind'] ?? '' ),
				'status_code'     => (int) ( $event['status_code'] ?? 0 ),
				'session_id'      => (string) ( $event['session_id'] ?? '' ),
				'resource_hints'  => is_array( $event['resource_hints'] ?? null ) ? $event['resource_hints'] : array(),
				'owner_slugs'     => is_array( $event['owner_slugs'] ?? null ) ? $event['owner_slugs'] : array(),
			);
		}

		if ( ! empty( $signals['request_contexts'] ) ) {
			$signals['notes'][] = __( 'Recent request contexts were captured and included in this analysis.', 'plugin-conflict-debugger' );
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
		$path_writable    = '' !== (string) $path && ( $path_exists ? is_writable( (string) $path ) : is_writable( dirname( (string) $path ) ) );
		$status           = 'available';
		$status_message   = __( 'Debug log is enabled and readable.', 'plugin-conflict-debugger' );
		$recommendations  = array();

		if ( ! $wp_debug_log ) {
			$status         = 'disabled';
			$status_message = __( 'WP_DEBUG_LOG is disabled, so WordPress is not writing a direct debug log for this plugin to read.', 'plugin-conflict-debugger' );
			$recommendations[] = __( 'Enable WP_DEBUG_LOG in wp-config.php to create a readable WordPress debug log.', 'plugin-conflict-debugger' );
		} elseif ( '' === (string) $path ) {
			$status         = 'unresolved';
			$status_message = __( 'The debug log path could not be resolved from the current WordPress configuration.', 'plugin-conflict-debugger' );
			$recommendations[] = __( 'Check whether WP_DEBUG_LOG points to a valid file path or boolean true.', 'plugin-conflict-debugger' );
		} elseif ( ! $path_exists ) {
			$status         = 'missing';
			$status_message = __( 'Debug logging is enabled, but the log file does not exist yet.', 'plugin-conflict-debugger' );
			$recommendations[] = __( 'Trigger a PHP notice or warning in staging, or verify that the web server can create the log file.', 'plugin-conflict-debugger' );
		} elseif ( ! $path_readable ) {
			$status         = 'unreadable';
			$status_message = __( 'The debug log exists but is not readable by the current PHP process.', 'plugin-conflict-debugger' );
			$recommendations[] = __( 'Check file ownership, permissions, and any open_basedir restrictions on the server.', 'plugin-conflict-debugger' );
		}

		if ( '' !== $open_basedir ) {
			$recommendations[] = __( 'This server reports open_basedir restrictions. If the log path falls outside that scope, PHP may not be able to read it.', 'plugin-conflict-debugger' );
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
				'message' => (string) $line,
				'file'    => '',
				'line'    => 0,
			);
		}

		return $entries;
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
