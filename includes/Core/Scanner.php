<?php
/**
 * Primary scan orchestration service.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

use PluginConflictDebugger\Support\PluginChangeTracker;
use function get_plugins;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scanner {
	/**
	 * Environment service.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * Error collector.
	 *
	 * @var ErrorCollector
	 */
	private ErrorCollector $error_collector;

	/**
	 * Conflict detector.
	 *
	 * @var ConflictDetector
	 */
	private ConflictDetector $detector;

	/**
	 * Results repository.
	 *
	 * @var ResultsRepository
	 */
	private ResultsRepository $repository;

	/**
	 * Plugin change tracker.
	 *
	 * @var PluginChangeTracker
	 */
	private PluginChangeTracker $change_tracker;

	/**
	 * Request trace analyzer.
	 *
	 * @var TraceAnalyzer
	 */
	private TraceAnalyzer $trace_analyzer;

	/**
	 * Validation mode repository.
	 *
	 * @var ValidationModeRepository
	 */
	private ValidationModeRepository $validation;

	/**
	 * Constructor.
	 *
	 * @param Environment       $environment Environment service.
	 * @param ErrorCollector    $error_collector Error collector.
	 * @param ConflictDetector  $detector Conflict detector.
	 * @param ResultsRepository $repository Results repository.
	 */
	public function __construct( Environment $environment, ErrorCollector $error_collector, ConflictDetector $detector, ResultsRepository $repository, PluginChangeTracker $change_tracker, TraceAnalyzer $trace_analyzer, ValidationModeRepository $validation ) {
		$this->environment     = $environment;
		$this->error_collector = $error_collector;
		$this->detector        = $detector;
		$this->repository      = $repository;
		$this->change_tracker  = $change_tracker;
		$this->trace_analyzer  = $trace_analyzer;
		$this->validation      = $validation;
	}

	/**
	 * Runs a complete scan and stores results.
	 *
	 * @return array<string, mixed>
	 */
	public function run_scan(): array {
		return $this->run_scan_with_progress();
	}

	/**
	 * Runs a complete scan and stores results with optional progress reporting.
	 *
	 * @param callable|null $progress_callback Optional progress callback.
	 * @return array<string, mixed>
	 */
	public function run_scan_with_progress( ?callable $progress_callback = null ): array {
		$this->notify_progress( $progress_callback, __( 'Preparing scan context...', 'conflict-debugger' ), 10 );
		$plugins       = $this->get_active_plugins();
		$this->notify_progress( $progress_callback, __( 'Capturing environment details...', 'conflict-debugger' ), 30 );
		$environment   = $this->environment->snapshot();
		$this->notify_progress( $progress_callback, __( 'Collecting runtime and log signals...', 'conflict-debugger' ), 50 );
		$error_signals = $this->error_collector->collect();
		$this->notify_progress( $progress_callback, __( 'Analyzing plugin interactions...', 'conflict-debugger' ), 75 );
		$findings      = $this->detector->detect( $plugins, $error_signals, $environment );
		$this->notify_progress( $progress_callback, __( 'Saving scan results...', 'conflict-debugger' ), 90 );
		$summary       = $this->build_summary( $plugins, $error_signals, $findings );
		$focus_session = array();
		if ( is_array( $error_signals['diagnostic_session']['active'] ?? null ) && ! empty( $error_signals['diagnostic_session']['active']['id'] ) ) {
			$focus_session = $error_signals['diagnostic_session']['active'];
		} elseif ( is_array( $error_signals['diagnostic_session']['last'] ?? null ) ) {
			$focus_session = $error_signals['diagnostic_session']['last'];
		}

		$trace_snapshot = $this->trace_analyzer->build_snapshot(
			is_array( $error_signals['request_contexts'] ?? null ) ? $error_signals['request_contexts'] : array(),
			is_array( $error_signals['runtime_events'] ?? null ) ? $error_signals['runtime_events'] : array(),
			is_array( $focus_session ) ? $focus_session : array()
		);
		$results       = array(
			'scan_timestamp' => current_time( 'mysql' ),
			'environment'    => $environment,
			'plugins'        => $plugins,
			'error_signals'  => $error_signals['entries'] ?? array(),
			'request_contexts' => $error_signals['request_contexts'] ?? array(),
			'runtime_events' => $error_signals['runtime_events'] ?? array(),
			'diagnostic_session' => $error_signals['diagnostic_session'] ?? array(),
			'validation_mode' => $error_signals['validation_mode'] ?? array(
				'active' => $this->validation->get_active(),
				'last'   => $this->validation->get_last(),
			),
			'log_access'     => $error_signals['log_access'] ?? array(),
			'analysis_notes' => array(
				'logs_unavailable' => ! empty( $error_signals['logs_unavailable'] ),
				'notes'            => $error_signals['notes'] ?? array(),
			),
			'recent_changes' => $this->change_tracker->get_changes(),
			'findings'       => $findings,
			'trace_snapshot' => $trace_snapshot,
			'summary'        => $summary,
			'severity_counts'=> $this->build_severity_counts( $findings ),
			'site_status'    => $this->derive_site_status( $findings ),
		);

		$this->repository->save( $results );

		return $results;
	}

	/**
	 * Emits a progress update when a callback is available.
	 *
	 * @param callable|null $progress_callback Progress callback.
	 * @param string        $message Status message.
	 * @param int           $progress Progress percentage.
	 * @return void
	 */
	private function notify_progress( ?callable $progress_callback, string $message, int $progress ): void {
		if ( null !== $progress_callback ) {
			$progress_callback( $message, $progress );
		}
	}

	/**
	 * Returns normalized active plugin metadata.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_active_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins        = get_plugins();
		$active_plugins     = (array) get_option( 'active_plugins', array() );
		$recently_activated = (array) get_option( 'recently_activated', array() );
		$plugins            = array();

		foreach ( $active_plugins as $plugin_file ) {
			if ( empty( $all_plugins[ $plugin_file ] ) ) {
				continue;
			}

			$plugin_data      = $all_plugins[ $plugin_file ];
			$categories       = $this->infer_categories( $plugin_file, $plugin_data );
			$recently_changed = isset( $recently_activated[ $plugin_file ] ) || $this->change_tracker->was_recently_changed( (string) $plugin_file );

			$plugins[] = PluginSnapshot::from_plugin_data(
				(string) $plugin_file,
				(array) $plugin_data,
				$categories,
				$recently_changed
			);
		}

		return $plugins;
	}

	/**
	 * Groups plugin into broad categories using name and slug heuristics.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @param array  $plugin_data Plugin header data.
	 * @return string[]
	 */
	private function infer_categories( string $plugin_file, array $plugin_data ): array {
		$text = strtolower( $plugin_file . ' ' . wp_json_encode( $plugin_data ) );
		$map  = array(
			'cache'        => array( 'cache', 'rocket', 'litespeed', 'w3 total cache' ),
			'seo'          => array( 'seo', 'rank math', 'yoast', 'all in one seo' ),
			'security'     => array( 'security', 'firewall', 'wordfence', 'sucuri', 'ithemes security' ),
			'forms'        => array( 'forms', 'formidable', 'gravity forms', 'contact form', 'wpforms' ),
			'ecommerce'    => array( 'woocommerce', 'ecommerce', 'shop', 'checkout' ),
			'checkout'     => array( 'checkout', 'cart', 'payment', 'order bump', 'funnel' ),
			'analytics'    => array( 'analytics', 'tracking', 'gtm', 'google tag manager' ),
			'page-builder' => array( 'elementor', 'beaver builder', 'divi', 'builder', 'bricks' ),
			'optimization' => array( 'optimize', 'minify', 'asset clean', 'perfmatters', 'autoptimize' ),
			'backup'       => array( 'backup', 'migration', 'updraft', 'duplicator' ),
			'membership'   => array( 'membership', 'memberpress', 'restrict content', 'paid memberships' ),
			'schema'       => array( 'schema', 'rich snippet', 'structured data' ),
			'editor'       => array( 'editor', 'gutenberg', 'metabox', 'tinymce', 'acf block' ),
			'authentication' => array( 'login', 'authentication', 'auth', 'redirect after login', 'mfa', '2fa' ),
			'api'          => array( 'rest api', 'api', 'ajax', 'endpoint', 'webhook' ),
			'content-model'=> array( 'custom post type', 'cpt', 'taxonomy', 'custom taxonomy', 'content type', 'pods' ),
			'email'        => array( 'smtp', 'mail', 'email', 'notification', 'mailer' ),
			'admin-tools'  => array( 'admin menu', 'settings page', 'tools', 'dashboard widget', 'admin columns' ),
			'observer'     => array( 'query monitor', 'debug bar', 'profiler', 'debugger', 'health check', 'troubleshoot' ),
		);

		$categories = array();

		foreach ( $map as $category => $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( false !== strpos( $text, $keyword ) ) {
					$categories[] = $category;
					break;
				}
			}
		}

		return $categories;
	}

	/**
	 * Builds summary card values.
	 *
	 * @param array<int, array<string, mixed>> $plugins Active plugins.
	 * @param array<string, mixed>              $error_signals Error signals.
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @return array<string, int>
	 */
	private function build_summary( array $plugins, array $error_signals, array $findings ): array {
		return array(
			'active_plugins'   => count( $plugins ),
			'error_signals'    => (int) ( $error_signals['summary_count'] ?? count( $error_signals['entries'] ?? array() ) ),
			'trace_warnings'   => (int) ( $error_signals['trace_summary_count'] ?? 0 ),
			'likely_conflicts' => count( $findings ),
			'recent_changes'   => $this->change_tracker->count_recent_changes(),
		);
	}

	/**
	 * Builds site-health-style severity counts.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @return array<string, int>
	 */
	private function build_severity_counts( array $findings ): array {
		$counts = array(
			'info'     => 0,
			'low'      => 0,
			'medium'   => 0,
			'high'     => 0,
			'critical' => 0,
		);

		foreach ( $findings as $finding ) {
			$severity = (string) ( $finding['severity'] ?? 'info' );
			if ( isset( $counts[ $severity ] ) ) {
				$counts[ $severity ]++;
			}
		}

		return $counts;
	}

	/**
	 * Derives overall site status from finding severity.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @return string
	 */
	private function derive_site_status( array $findings ): string {
		foreach ( $findings as $finding ) {
			if ( 'critical' === ( $finding['severity'] ?? '' ) ) {
				return 'critical';
			}
		}

		foreach ( $findings as $finding ) {
			if ( in_array( (string) ( $finding['severity'] ?? '' ), array( 'medium', 'high' ), true ) ) {
				return 'warning';
			}
		}

		return 'healthy';
	}
}
