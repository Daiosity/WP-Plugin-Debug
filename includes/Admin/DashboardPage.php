<?php
/**
 * Dashboard page controller and renderer.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Admin;

use PluginConflictDebugger\Core\DiagnosticSessionRepository;
use PluginConflictDebugger\Core\ResultsRepository;
use PluginConflictDebugger\Core\ScanStateRepository;
use PluginConflictDebugger\Core\Scanner;
use PluginConflictDebugger\Core\TraceAnalyzer;
use PluginConflictDebugger\Core\ValidationModeRepository;
use PluginConflictDebugger\Support\Capabilities;
use PluginConflictDebugger\Support\Helpers;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DashboardPage {
	/**
	 * Async worker hook.
	 */
	private const ASYNC_SCAN_HOOK = 'pcd_run_scan_async';

	/**
	 * Delay before trying loopback dispatch for a queued scan.
	 */
	private const QUEUE_DISPATCH_DELAY = 6;

	/**
	 * Delay before forcing the worker to run inside the polling request.
	 */
	private const INLINE_FALLBACK_DELAY = 18;

	/**
	 * Scanner service.
	 *
	 * @var Scanner
	 */
	private Scanner $scanner;

	/**
	 * Results repository.
	 *
	 * @var ResultsRepository
	 */
	private ResultsRepository $repository;

	/**
	 * Scan state repository.
	 *
	 * @var ScanStateRepository
	 */
	private ScanStateRepository $scan_state;

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
	 * Trace analyzer.
	 *
	 * @var TraceAnalyzer
	 */
	private TraceAnalyzer $trace_analyzer;

	/**
	 * Capability service.
	 *
	 * @var Capabilities
	 */
	private Capabilities $capabilities;

	/**
	 * Constructor.
	 *
	 * @param Scanner             $scanner Scanner service.
	 * @param ResultsRepository   $repository Results repository.
	 * @param ScanStateRepository $scan_state Scan state repository.
	 * @param DiagnosticSessionRepository $sessions Diagnostic session repository.
	 * @param Capabilities        $capabilities Capability service.
	 */
	public function __construct( Scanner $scanner, ResultsRepository $repository, ScanStateRepository $scan_state, DiagnosticSessionRepository $sessions, ValidationModeRepository $validation, Capabilities $capabilities, TraceAnalyzer $trace_analyzer ) {
		$this->scanner      = $scanner;
		$this->repository   = $repository;
		$this->scan_state   = $scan_state;
		$this->sessions     = $sessions;
		$this->validation   = $validation;
		$this->capabilities = $capabilities;
		$this->trace_analyzer = $trace_analyzer;
	}

	/**
	 * Hooks admin menu and scan handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_pcd_run_scan', array( $this, 'handle_scan' ) );
		add_action( 'wp_ajax_pcd_start_scan', array( $this, 'ajax_start_scan' ) );
		add_action( 'wp_ajax_pcd_get_scan_status', array( $this, 'ajax_get_scan_status' ) );
		add_action( 'wp_ajax_pcd_run_scan_worker', array( $this, 'ajax_run_scan_worker' ) );
		add_action( 'wp_ajax_nopriv_pcd_run_scan_worker', array( $this, 'ajax_run_scan_worker' ) );
		add_action( 'wp_ajax_pcd_start_diagnostic_session', array( $this, 'ajax_start_diagnostic_session' ) );
		add_action( 'wp_ajax_pcd_end_diagnostic_session', array( $this, 'ajax_end_diagnostic_session' ) );
		add_action( 'wp_ajax_pcd_start_validation_mode', array( $this, 'ajax_start_validation_mode' ) );
		add_action( 'wp_ajax_pcd_end_validation_mode', array( $this, 'ajax_end_validation_mode' ) );
		add_action( self::ASYNC_SCAN_HOOK, array( $this, 'run_background_scan' ), 10, 1 );
	}

	/**
	 * Adds the tools submenu page.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'tools.php',
			__( 'Conflict Debugger', 'conflict-debugger' ),
			__( 'Conflict Debugger', 'conflict-debugger' ),
			$this->capabilities->required_capability(),
			'conflict-debugger',
			array( $this, 'render' )
		);
	}

	/**
	 * Handles manual scan requests for non-JS fallback.
	 *
	 * @return void
	 */
	public function handle_scan(): void {
		if ( ! $this->capabilities->can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to run this scan.', 'conflict-debugger' ) );
		}

		check_admin_referer( 'pcd_run_scan_action', 'pcd_run_scan_nonce' );

		$this->start_background_scan();

		set_transient(
			'pcd_scan_notice',
			array(
				'type'    => 'info',
				'message' => __( 'Scan started in the background. You can stay on this page and watch progress.', 'conflict-debugger' ),
			),
			60
		);

		wp_safe_redirect( admin_url( 'tools.php?page=conflict-debugger' ) );
		exit;
	}

	/**
	 * AJAX endpoint for starting a background scan.
	 *
	 * @return void
	 */
	public function ajax_start_scan(): void {
		if ( ! $this->capabilities->can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to run this scan.', 'conflict-debugger' ) ), 403 );
		}

		check_ajax_referer( 'pcd_scan_ajax', 'nonce' );

		$state = $this->start_background_scan();

		wp_send_json_success( $state );
	}

	/**
	 * AJAX endpoint for polling scan status.
	 *
	 * @return void
	 */
	public function ajax_get_scan_status(): void {
		if ( ! $this->capabilities->can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to view scan status.', 'conflict-debugger' ) ), 403 );
		}

		check_ajax_referer( 'pcd_scan_ajax', 'nonce' );
		$state = $this->scan_state->get();

		if ( 'queued' === (string) ( $state['status'] ?? 'idle' ) ) {
			$this->recover_queued_scan( $state );
			$state = $this->scan_state->get();
		}

		wp_send_json_success( $state );
	}

	/**
	 * Loopback worker endpoint for hosts where WP-Cron never starts the job.
	 *
	 * @return void
	 */
	public function ajax_run_scan_worker(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Loopback worker authentication uses a one-time scan token and worker key.
		$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['token'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Loopback worker authentication uses a one-time scan token and worker key.
		$worker_key = isset( $_REQUEST['worker_key'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['worker_key'] ) ) : '';
		$state      = $this->scan_state->get();

		if ( '' === $token || '' === $worker_key ) {
			wp_send_json_error( array( 'message' => __( 'Missing worker credentials.', 'conflict-debugger' ) ), 400 );
		}

		if ( $token !== (string) ( $state['token'] ?? '' ) || $worker_key !== (string) ( $state['worker_key'] ?? '' ) ) {
			wp_send_json_error( array( 'message' => __( 'Worker credentials did not match the queued scan.', 'conflict-debugger' ) ), 403 );
		}

		$this->run_background_scan( $token );
		wp_send_json_success( $this->scan_state->get() );
	}

	/**
	 * AJAX endpoint for starting a focused diagnostic session.
	 *
	 * @return void
	 */
	public function ajax_start_diagnostic_session(): void {
		if ( ! $this->capabilities->can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage diagnostic sessions.', 'conflict-debugger' ) ), 403 );
		}

		check_ajax_referer( 'pcd_scan_ajax', 'nonce' );

		$target_context = sanitize_key( (string) ( $_POST['target_context'] ?? 'all' ) );
		$session        = $this->sessions->start( $target_context );

		wp_send_json_success( $session );
	}

	/**
	 * AJAX endpoint for ending a focused diagnostic session.
	 *
	 * @return void
	 */
	public function ajax_end_diagnostic_session(): void {
		if ( ! $this->capabilities->can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage diagnostic sessions.', 'conflict-debugger' ) ), 403 );
		}

		check_ajax_referer( 'pcd_scan_ajax', 'nonce' );

		$session = $this->sessions->end( 'completed' );
		wp_send_json_success( $session );
	}

	/**
	 * AJAX endpoint for starting focused validation mode.
	 *
	 * @return void
	 */
	public function ajax_start_validation_mode(): void {
		if ( ! $this->capabilities->can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage validation mode.', 'conflict-debugger' ) ), 403 );
		}

		check_ajax_referer( 'pcd_scan_ajax', 'nonce' );

		$posted_data  = wp_unslash( $_POST );
		$target_type  = sanitize_key( (string) ( $posted_data['target_type'] ?? 'plugin_pair' ) );
		$target_value = sanitize_text_field( (string) ( $posted_data['target_value'] ?? '' ) );
		$plugin_a     = sanitize_key( (string) ( $posted_data['plugin_a'] ?? '' ) );
		$plugin_b     = sanitize_key( (string) ( $posted_data['plugin_b'] ?? '' ) );

		$mode = $this->validation->start(
			array(
				'target_type'  => $target_type,
				'target_value' => $target_value,
				'plugin_a'     => $plugin_a,
				'plugin_b'     => $plugin_b,
			)
		);

		wp_send_json_success( $mode );
	}

	/**
	 * AJAX endpoint for ending focused validation mode.
	 *
	 * @return void
	 */
	public function ajax_end_validation_mode(): void {
		if ( ! $this->capabilities->can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage validation mode.', 'conflict-debugger' ) ), 403 );
		}

		check_ajax_referer( 'pcd_scan_ajax', 'nonce' );

		$mode = $this->validation->end( 'completed' );
		wp_send_json_success( $mode );
	}

	/**
	 * Runs the scan in background worker context.
	 *
	 * @param string $token Scan token.
	 * @return void
	 */
	public function run_background_scan( string $token ): void {
		$state = $this->scan_state->get();
		if ( ( $state['status'] ?? 'idle' ) === 'running' && (string) ( $state['token'] ?? '' ) !== $token ) {
			return;
		}

		$this->scan_state->mark_running( $token, __( 'Scan started.', 'conflict-debugger' ), 10 );

		try {
			$results = $this->scanner->run_scan_with_progress(
				function ( string $message, int $progress ) use ( $token ): void {
					$this->scan_state->mark_running( $token, $message, $progress );
				}
			);

			$finding_count = count( $results['findings'] ?? array() );
			$this->scan_state->mark_complete( $token, $finding_count );

			set_transient(
				'pcd_scan_notice',
				array(
					'type'    => $finding_count > 0 ? 'warning' : 'success',
					'message' => $finding_count > 0
						? sprintf(
							/* translators: %d finding count. */
							__( 'Scan complete. %d interaction finding(s) need review. These signals are conservative diagnostics, not guaranteed proof.', 'conflict-debugger' ),
							$finding_count
						)
						: __( 'Scan complete. No significant plugin conflict signals were detected in this pass.', 'conflict-debugger' ),
				),
				120
			);
		} catch ( Throwable $exception ) {
			$this->scan_state->mark_failed( $token, $exception->getMessage() );
			set_transient(
				'pcd_scan_notice',
				array(
					'type'    => 'error',
					'message' => __( 'Scan failed. Please check server logs and try again.', 'conflict-debugger' ),
				),
				120
			);
		}
	}

	/**
	 * Starts background scan if one is not already running.
	 *
	 * @return array<string, mixed>
	 */
	private function start_background_scan(): array {
		$state = $this->scan_state->get();
		if ( in_array( $state['status'] ?? 'idle', array( 'queued', 'running' ), true ) ) {
			return $state;
		}

		$token      = wp_generate_uuid4();
		$worker_key = wp_generate_password( 20, false, false );
		$state      = $this->scan_state->mark_queued( $token, $worker_key );

		if ( ! wp_next_scheduled( self::ASYNC_SCAN_HOOK, array( $token ) ) ) {
			wp_schedule_single_event( time() + 1, self::ASYNC_SCAN_HOOK, array( $token ) );
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		$this->dispatch_async_worker( $state );

		return $state;
	}

	/**
	 * Attempts to recover a scan that has been queued for too long.
	 *
	 * @param array<string, mixed> $state Current scan state.
	 * @return void
	 */
	private function recover_queued_scan( array $state ): void {
		$queued_for = $this->seconds_since( (string) ( $state['started_at'] ?? '' ) );
		if ( $queued_for < self::QUEUE_DISPATCH_DELAY ) {
			return;
		}

		$this->dispatch_async_worker( $state );

		if ( $queued_for >= self::INLINE_FALLBACK_DELAY ) {
			$token = sanitize_text_field( (string) ( $state['token'] ?? '' ) );
			if ( '' !== $token ) {
				$this->run_background_scan( $token );
			}
		}
	}

	/**
	 * Dispatches a non-blocking loopback worker request.
	 *
	 * @param array<string, mixed> $state Scan state.
	 * @return void
	 */
	private function dispatch_async_worker( array $state ): void {
		$token      = sanitize_text_field( (string) ( $state['token'] ?? '' ) );
		$worker_key = sanitize_text_field( (string) ( $state['worker_key'] ?? '' ) );

		if ( '' === $token || '' === $worker_key ) {
			return;
		}

		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'blocking'  => false,
				'timeout'   => 0.01,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter used for local loopback requests.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action'     => 'pcd_run_scan_worker',
					'token'      => $token,
					'worker_key' => $worker_key,
				),
			)
		);
	}

	/**
	 * Returns seconds elapsed since a timestamp.
	 *
	 * @param string $timestamp Timestamp string.
	 * @return int
	 */
	private function seconds_since( string $timestamp ): int {
		$time = strtotime( $timestamp );
		if ( false === $time ) {
			return 0;
		}

		return max( 0, time() - $time );
	}

	/**
	 * Renders the dashboard.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->capabilities->can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'conflict-debugger' ) );
		}

		$results           = $this->repository->get_latest();
		$history           = $this->repository->get_history( 8 );
		$scan_state        = $this->scan_state->get();
		$has_results       = ! empty( $results );
		$summary           = $has_results ? ( $results['summary'] ?? array() ) : array();
		$findings          = $has_results ? ( $results['findings'] ?? array() ) : array();
		$scan_timestamp    = $has_results ? (string) ( $results['scan_timestamp'] ?? '' ) : '';
		$logs_unavailable  = $has_results ? ! empty( $results['analysis_notes']['logs_unavailable'] ) : false;
		$log_access        = $has_results && ! empty( $results['log_access'] ) && is_array( $results['log_access'] ) ? $results['log_access'] : array();
		$analysis_notes    = $has_results && ! empty( $results['analysis_notes']['notes'] ) && is_array( $results['analysis_notes']['notes'] )
			? array_values( array_filter( $results['analysis_notes']['notes'], fn( $note ): bool => ! $logs_unavailable || 'Direct log access is unavailable. Analysis is based on runtime and plugin interaction signals.' !== (string) $note ) )
			: array();
		$diagnostic_session = $has_results && is_array( $results['diagnostic_session'] ?? null ) ? $results['diagnostic_session'] : array();
		$active_session     = ! empty( $diagnostic_session['active'] ) && is_array( $diagnostic_session['active'] ) ? $diagnostic_session['active'] : $this->sessions->get_active();
		$last_session       = ! empty( $diagnostic_session['last'] ) && is_array( $diagnostic_session['last'] ) ? $diagnostic_session['last'] : $this->sessions->get_last();
		$session_contexts   = $this->sessions->get_supported_contexts();
		$validation_mode    = $has_results && is_array( $results['validation_mode'] ?? null ) ? $results['validation_mode'] : array();
		$active_validation  = ! empty( $validation_mode['active'] ) && is_array( $validation_mode['active'] ) ? $validation_mode['active'] : $this->validation->get_active();
		$last_validation    = ! empty( $validation_mode['last'] ) && is_array( $validation_mode['last'] ) ? $validation_mode['last'] : $this->validation->get_last();
		$validation_targets = $this->validation->get_supported_targets();
		$validation_plugins = $this->build_validation_plugin_options( $results );
		$plugin_drilldown  = $has_results ? $this->build_plugin_drilldown( $results ) : array();
		$scan_comparison   = $has_results ? $this->build_scan_comparison( $results, $history ) : array();
		$runtime_event_view = $has_results ? $this->build_runtime_event_view( $results, $findings, $active_session, $last_session, $active_validation, $last_validation ) : array(
			'summary'         => array(),
			'events'          => array(),
			'finding_event_map' => array(),
			'focus_label'     => '',
			'validation_focus_label' => '',
			'validation_matches' => 0,
		);
		$trace_snapshot    = $has_results && is_array( $results['trace_snapshot'] ?? null )
			? $results['trace_snapshot']
			: $this->trace_analyzer->build_snapshot(
				$has_results && is_array( $results['request_contexts'] ?? null ) ? $results['request_contexts'] : array(),
				$has_results && is_array( $results['runtime_events'] ?? null ) ? $results['runtime_events'] : array(),
				! empty( $active_session['id'] ) ? $active_session : $last_session
			);
		$finding_event_map = is_array( $runtime_event_view['finding_event_map'] ?? null ) ? $runtime_event_view['finding_event_map'] : array();
		$is_scan_active    = in_array( (string) ( $scan_state['status'] ?? 'idle' ), array( 'queued', 'running' ), true );
		$scan_status_text  = (string) ( $scan_state['message'] ?? __( 'No scan is running.', 'conflict-debugger' ) );
		$scan_progress     = (int) ( $scan_state['progress'] ?? 0 );
		?>
		<div class="wrap pcd-wrap">
			<div class="pcd-header">
				<div>
					<h1><?php esc_html_e( 'Conflict Debugger', 'conflict-debugger' ); ?></h1>
					<p class="description">
						<?php esc_html_e( 'Find likely plugin conflicts before you waste hours disabling plugins manually.', 'conflict-debugger' ); ?>
					</p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pcd_run_scan" />
					<?php wp_nonce_field( 'pcd_run_scan_action', 'pcd_run_scan_nonce' ); ?>
					<button
						type="button"
						class="button button-primary button-large"
						data-pcd-scan-button="true"
						data-pcd-default-label="<?php echo esc_attr__( 'Run Scan', 'conflict-debugger' ); ?>"
						<?php disabled( $is_scan_active ); ?>
					>
						<?php esc_html_e( 'Run Scan', 'conflict-debugger' ); ?>
					</button>
					<noscript>
						<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Run Scan (No JS)', 'conflict-debugger' ); ?></button>
					</noscript>
				</form>
			</div>

			<div class="pcd-meta">
				<strong><?php esc_html_e( 'Last scanned at:', 'conflict-debugger' ); ?></strong>
				<?php echo $scan_timestamp ? esc_html( Helpers::format_datetime( $scan_timestamp ) ) : esc_html__( 'Not yet scanned', 'conflict-debugger' ); ?>
			</div>

			<div
				class="pcd-scan-status"
				data-pcd-scan-status="true"
				data-pcd-scan-state="<?php echo esc_attr( (string) ( $scan_state['status'] ?? 'idle' ) ); ?>"
				<?php echo $is_scan_active || 'failed' === ( $scan_state['status'] ?? '' ) ? '' : 'hidden'; ?>
			>
				<div data-pcd-scan-message="true"><?php echo esc_html( $scan_status_text ); ?></div>
				<div class="pcd-scan-progress">
					<div class="pcd-scan-progress-bar" data-pcd-scan-progress="true" style="width: <?php echo esc_attr( (string) $scan_progress ); ?>%;"></div>
				</div>
				<span class="pcd-scan-progress-label" data-pcd-scan-progress-label="true"><?php echo esc_html( (string) $scan_progress ); ?>%</span>
			</div>

			<?php if ( $logs_unavailable ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'Direct log access is unavailable. Analysis is based on runtime and plugin interaction signals.', 'conflict-debugger' ); ?></p>
				</div>
			<?php endif; ?>

			<section class="pcd-summary-cards">
				<?php $this->render_summary_card( __( 'Active Plugins', 'conflict-debugger' ), (string) ( $summary['active_plugins'] ?? '0' ) ); ?>
				<?php $this->render_summary_card( __( 'Error Signals', 'conflict-debugger' ), (string) ( $summary['error_signals'] ?? '0' ), __( 'PHP, log, request, and runtime failures.', 'conflict-debugger' ) ); ?>
				<?php $this->render_summary_card( __( 'Trace Warnings', 'conflict-debugger' ), (string) ( $summary['trace_warnings'] ?? '0' ), __( 'Observed mutations and suppressed callbacks, not confirmed breakage by themselves.', 'conflict-debugger' ) ); ?>
				<?php $this->render_summary_card( __( 'Likely Conflicts', 'conflict-debugger' ), (string) ( $summary['likely_conflicts'] ?? '0' ) ); ?>
				<?php $this->render_summary_card( __( 'Recent Plugin Changes', 'conflict-debugger' ), (string) ( $summary['recent_changes'] ?? '0' ) ); ?>
				<div class="pcd-summary-card pcd-summary-card-status">
					<span class="pcd-summary-label"><?php esc_html_e( 'Site Status', 'conflict-debugger' ); ?></span>
					<p class="pcd-site-status">
						<span class="pcd-status-badge pcd-status-<?php echo esc_attr( (string) ( $results['site_status'] ?? 'healthy' ) ); ?>">
							<?php echo esc_html( ucfirst( (string) ( $results['site_status'] ?? __( 'Healthy', 'conflict-debugger' ) ) ) ); ?>
						</span>
					</p>
					<p class="pcd-summary-note"><?php esc_html_e( 'Summarizes current scan severity, not a guaranteed root cause.', 'conflict-debugger' ); ?></p>
				</div>
			</section>

			<nav class="nav-tab-wrapper pcd-tab-nav" aria-label="<?php esc_attr_e( 'Conflict Debugger sections', 'conflict-debugger' ); ?>">
				<button type="button" class="nav-tab nav-tab-active" data-pcd-tab-trigger="findings" aria-selected="true"><?php esc_html_e( 'Findings', 'conflict-debugger' ); ?></button>
				<button type="button" class="nav-tab" data-pcd-tab-trigger="plugins" aria-selected="false"><?php esc_html_e( 'Plugins', 'conflict-debugger' ); ?></button>
				<button type="button" class="nav-tab" data-pcd-tab-trigger="diagnostics" aria-selected="false"><?php esc_html_e( 'Diagnostics', 'conflict-debugger' ); ?></button>
				<button type="button" class="nav-tab" data-pcd-tab-trigger="pro" aria-selected="false"><?php esc_html_e( 'Pro Preview', 'conflict-debugger' ); ?></button>
			</nav>

			<div class="pcd-tab-panels">
				<section class="pcd-tab-panel is-active" data-pcd-tab-panel="findings">
					<section class="pcd-panel">
						<div class="pcd-panel-header">
							<h2><?php esc_html_e( 'Findings', 'conflict-debugger' ); ?></h2>
							<p><?php esc_html_e( 'Each finding is conservative and evidence-tiered, not a guaranteed root cause.', 'conflict-debugger' ); ?></p>
						</div>

						<?php if ( ! $has_results ) : ?>
							<div class="pcd-empty-state">
								<h3><?php esc_html_e( 'Scan not yet run', 'conflict-debugger' ); ?></h3>
								<p><?php esc_html_e( 'Run your first scan to review recent error signals, suspicious plugin combinations, and possible conflict patterns.', 'conflict-debugger' ); ?></p>
							</div>
						<?php elseif ( empty( $findings ) ) : ?>
							<div class="pcd-empty-state">
								<h3><?php esc_html_e( 'No issues detected', 'conflict-debugger' ); ?></h3>
								<p><?php esc_html_e( 'This scan did not find strong conflict signals. If the site still breaks intermittently, test again after reproducing the issue or enabling debug logging in staging.', 'conflict-debugger' ); ?></p>
							</div>
						<?php else : ?>
							<div class="pcd-findings-table-wrap">
							<table class="widefat striped pcd-findings-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Severity', 'conflict-debugger' ); ?></th>
										<th><?php esc_html_e( 'Plugin A', 'conflict-debugger' ); ?></th>
										<th><?php esc_html_e( 'Plugin B', 'conflict-debugger' ); ?></th>
										<th><?php esc_html_e( 'Surface', 'conflict-debugger' ); ?></th>
										<th><?php esc_html_e( 'Confidence', 'conflict-debugger' ); ?></th>
										<th><?php esc_html_e( 'Explanation', 'conflict-debugger' ); ?></th>
										<th><?php esc_html_e( 'Action', 'conflict-debugger' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $findings as $finding ) : ?>
										<?php $finding_signature = $this->finding_signature( $finding ); ?>
										<?php $linked_runtime_events = is_array( $finding_event_map[ $finding_signature ] ?? null ) ? $finding_event_map[ $finding_signature ] : array(); ?>
										<?php $evidence_items = is_array( $finding['evidence_items'] ?? null ) ? $finding['evidence_items'] : array(); ?>
										<?php $evidence_breakdown = is_array( $finding['evidence_strength_breakdown'] ?? null ) ? (array) $finding['evidence_strength_breakdown'] : array(); ?>
										<tr>
											<td>
												<span class="pcd-status-badge pcd-status-<?php echo esc_attr( $finding['severity'] ?? 'info' ); ?>">
													<?php echo esc_html( ucfirst( (string) ( $finding['severity'] ?? 'info' ) ) ); ?>
												</span>
											</td>
											<td><?php echo esc_html( (string) ( $finding['primary_plugin_name'] ?? __( 'Unknown', 'conflict-debugger' ) ) ); ?></td>
											<td><?php echo esc_html( (string) ( $finding['secondary_plugin_name'] ?? '-' ) ); ?></td>
											<td>
												<?php echo esc_html( (string) ( $finding['surface_label'] ?? $finding['issue_category'] ?? '-' ) ); ?>
												<?php if ( ! empty( $finding['affected_area'] ) ) : ?>
													<div class="pcd-surface-area"><?php echo esc_html( (string) $finding['affected_area'] ); ?></div>
												<?php endif; ?>
												<?php if ( ! empty( $finding['request_context'] ) ) : ?>
													<div class="pcd-surface-area"><?php echo esc_html( $this->format_labeled_value( __( 'Context', 'conflict-debugger' ), (string) $finding['request_context'] ) ); ?></div>
												<?php endif; ?>
												<?php if ( ! empty( $finding['execution_surface'] ) ) : ?>
													<div class="pcd-surface-area"><?php echo esc_html( $this->format_labeled_value( __( 'Execution surface', 'conflict-debugger' ), (string) $finding['execution_surface'] ) ); ?></div>
												<?php endif; ?>
												<?php if ( ! empty( $finding['shared_resource'] ) ) : ?>
													<div class="pcd-surface-area"><?php echo esc_html( $this->format_labeled_value( __( 'Resource', 'conflict-debugger' ), (string) $finding['shared_resource'] ) ); ?></div>
												<?php endif; ?>
											</td>
											<td><?php echo esc_html( (string) ( $finding['confidence'] ?? 0 ) ); ?>%</td>
											<td>
												<?php if ( ! empty( $finding['title'] ) ) : ?>
													<div class="pcd-finding-title"><?php echo esc_html( (string) $finding['title'] ); ?></div>
												<?php endif; ?>
												<?php if ( ! empty( $finding['category'] ) || ! empty( $finding['finding_type'] ) ) : ?>
													<div class="pcd-finding-type"><?php echo esc_html( $this->format_labeled_value( __( 'Category', 'conflict-debugger' ), ucwords( str_replace( '_', ' ', (string) ( $finding['category'] ?? $finding['finding_type'] ?? '' ) ) ) ) ); ?></div>
												<?php endif; ?>
												<div class="pcd-explanation"><?php echo esc_html( (string) ( $finding['explanation'] ?? '' ) ); ?></div>
												<?php if ( ! empty( $finding['evidence_strength_breakdown'] ) && is_array( $finding['evidence_strength_breakdown'] ) ) : ?>
													<div class="pcd-actionability-note">
														<?php
														echo esc_html(
															sprintf(
																/* translators: 1: strong proof count, 2: supporting count, 3: noise count, 4: runtime breakage count. */
																__( 'Evidence breakdown: Strong proof %1$d, Supporting indicators %2$d, Noise %3$d, Runtime breakage %4$d.', 'conflict-debugger' ),
																(int) ( $evidence_breakdown['strong_proof'] ?? 0 ),
																(int) ( $evidence_breakdown['supporting'] ?? 0 ),
																(int) ( $evidence_breakdown['noise'] ?? 0 ),
																(int) ( $evidence_breakdown['runtime_breakage'] ?? 0 )
															)
														);
														?>
													</div>
												<?php endif; ?>
												<?php if ( ! empty( $finding['why_scored_this_way'] ) ) : ?>
													<div class="pcd-actionability-note"><?php echo esc_html( (string) $finding['why_scored_this_way'] ); ?></div>
												<?php endif; ?>
												<?php if ( ! empty( $finding['why_this_is_not_or_is_actionable'] ) ) : ?>
													<div class="pcd-actionability-note"><?php echo esc_html( (string) $finding['why_this_is_not_or_is_actionable'] ); ?></div>
												<?php endif; ?>
												<?php if ( ! empty( $finding['evidence'] ) && is_array( $finding['evidence'] ) ) : ?>
													<details class="pcd-evidence-details">
														<summary>
															<?php
															echo esc_html(
																sprintf(
																	/* translators: %d evidence count. */
																	__( 'View evidence (%d)', 'conflict-debugger' ),
																	count( $finding['evidence'] )
																)
															);
															?>
														</summary>
														<ul class="pcd-evidence-list">
															<?php foreach ( $finding['evidence'] as $evidence_item ) : ?>
																<li><?php echo esc_html( (string) $evidence_item ); ?></li>
															<?php endforeach; ?>
														</ul>
													</details>
												<?php endif; ?>
												<?php if ( ! empty( $linked_runtime_events ) ) : ?>
													<div class="pcd-runtime-event-links">
														<?php
														$first_link = $linked_runtime_events[0];
														$link_count = count( $linked_runtime_events );
														?>
														<a
															href="<?php echo esc_url( '#' . (string) ( $first_link['id'] ?? '' ) ); ?>"
															class="pcd-runtime-event-link"
															data-pcd-open-tab="diagnostics"
															data-pcd-scroll-target="<?php echo esc_attr( '#' . (string) ( $first_link['id'] ?? '' ) ); ?>"
														>
															<?php
															echo esc_html(
																sprintf(
																	/* translators: %d event count. */
																	_n( 'View matching runtime event (%d)', 'View matching runtime events (%d)', $link_count, 'conflict-debugger' ),
																	$link_count
																)
															);
															?>
														</a>
													</div>
												<?php endif; ?>
											</td>
											<td>
												<div><?php echo esc_html( (string) ( $finding['recommended_next_step'] ?? '' ) ); ?></div>
												<button
													type="button"
													class="button button-link pcd-finding-detail-toggle"
													data-pcd-finding-toggle="<?php echo esc_attr( $finding_signature ); ?>"
													data-pcd-open-label="<?php echo esc_attr__( 'View details', 'conflict-debugger' ); ?>"
													data-pcd-close-label="<?php echo esc_attr__( 'Hide details', 'conflict-debugger' ); ?>"
													aria-expanded="false"
												>
													<?php esc_html_e( 'View details', 'conflict-debugger' ); ?>
												</button>
											</td>
										</tr>
										<tr class="pcd-finding-detail-row" data-pcd-finding-detail-row="<?php echo esc_attr( $finding_signature ); ?>" hidden>
											<td colspan="7">
												<div class="pcd-finding-detail">
													<div class="pcd-finding-detail-grid">
														<div class="pcd-drilldown-stat">
															<span class="pcd-summary-label"><?php esc_html_e( 'Finding Type', 'conflict-debugger' ); ?></span>
															<strong class="pcd-summary-value-small"><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) ( $finding['finding_type'] ?? $finding['category'] ?? __( 'Unknown', 'conflict-debugger' ) ) ) ) ); ?></strong>
														</div>
														<div class="pcd-drilldown-stat">
															<span class="pcd-summary-label"><?php esc_html_e( 'Request Context', 'conflict-debugger' ); ?></span>
															<strong class="pcd-summary-value-small"><?php echo esc_html( (string) ( $finding['request_context'] ?? __( 'Runtime', 'conflict-debugger' ) ) ); ?></strong>
														</div>
														<div class="pcd-drilldown-stat">
															<span class="pcd-summary-label"><?php esc_html_e( 'Execution Surface', 'conflict-debugger' ); ?></span>
															<strong class="pcd-summary-value-small"><?php echo esc_html( (string) ( $finding['execution_surface'] ?? __( 'Not captured', 'conflict-debugger' ) ) ); ?></strong>
														</div>
														<div class="pcd-drilldown-stat">
															<span class="pcd-summary-label"><?php esc_html_e( 'Shared Resource', 'conflict-debugger' ); ?></span>
															<strong class="pcd-summary-value-small"><?php echo esc_html( (string) ( $finding['shared_resource'] ?? __( 'Broad overlap only', 'conflict-debugger' ) ) ); ?></strong>
														</div>
													</div>

													<div class="pcd-finding-detail-section">
														<h4><?php esc_html_e( 'Why This Was Scored This Way', 'conflict-debugger' ); ?></h4>
														<p><?php echo esc_html( (string) ( $finding['why_scored_this_way'] ?? '' ) ); ?></p>
														<?php if ( ! empty( $finding['why_this_is_not_or_is_actionable'] ) ) : ?>
															<p class="pcd-actionability-note"><?php echo esc_html( (string) $finding['why_this_is_not_or_is_actionable'] ); ?></p>
														<?php endif; ?>
													</div>

													<div class="pcd-finding-detail-section">
														<h4><?php esc_html_e( 'Evidence Strength', 'conflict-debugger' ); ?></h4>
														<div class="pcd-finding-detail-grid">
															<div class="pcd-drilldown-stat">
																<span class="pcd-summary-label"><?php esc_html_e( 'Strong Proof', 'conflict-debugger' ); ?></span>
																<strong class="pcd-summary-value-small"><?php echo esc_html( (string) ( $evidence_breakdown['strong_proof'] ?? 0 ) ); ?></strong>
															</div>
															<div class="pcd-drilldown-stat">
																<span class="pcd-summary-label"><?php esc_html_e( 'Supporting', 'conflict-debugger' ); ?></span>
																<strong class="pcd-summary-value-small"><?php echo esc_html( (string) ( $evidence_breakdown['supporting'] ?? 0 ) ); ?></strong>
															</div>
															<div class="pcd-drilldown-stat">
																<span class="pcd-summary-label"><?php esc_html_e( 'Runtime Breakage', 'conflict-debugger' ); ?></span>
																<strong class="pcd-summary-value-small"><?php echo esc_html( (string) ( $evidence_breakdown['runtime_breakage'] ?? 0 ) ); ?></strong>
															</div>
															<div class="pcd-drilldown-stat">
																<span class="pcd-summary-label"><?php esc_html_e( 'Noise', 'conflict-debugger' ); ?></span>
																<strong class="pcd-summary-value-small"><?php echo esc_html( (string) ( $evidence_breakdown['noise'] ?? 0 ) ); ?></strong>
															</div>
														</div>
													</div>

													<?php if ( ! empty( $evidence_items ) ) : ?>
														<div class="pcd-finding-detail-section">
															<h4><?php esc_html_e( 'Evidence Timeline', 'conflict-debugger' ); ?></h4>
															<div class="pcd-finding-evidence-list">
																<?php foreach ( $evidence_items as $evidence_item ) : ?>
																	<article class="pcd-finding-evidence-item">
																		<div class="pcd-plugin-finding-topline">
																			<span class="pcd-status-badge pcd-status-info"><?php echo esc_html( $this->humanize_evidence_tier( (string) ( $evidence_item['tier'] ?? '' ) ) ); ?></span>
																			<?php if ( ! empty( $evidence_item['request_context'] ) ) : ?>
																				<span class="pcd-status-badge pcd-status-info"><?php echo esc_html( (string) $evidence_item['request_context'] ); ?></span>
																			<?php endif; ?>
																			<?php if ( ! empty( $evidence_item['execution_surface'] ) ) : ?>
																				<span class="pcd-status-badge pcd-status-info"><?php echo esc_html( (string) $evidence_item['execution_surface'] ); ?></span>
																			<?php endif; ?>
																		</div>
																		<p><?php echo esc_html( (string) ( $evidence_item['message'] ?? '' ) ); ?></p>
																		<?php if ( ! empty( $evidence_item['shared_resource'] ) ) : ?>
																			<p class="pcd-actionability-note"><?php echo esc_html( $this->format_labeled_value( __( 'Shared resource', 'conflict-debugger' ), (string) $evidence_item['shared_resource'] ) ); ?></p>
																		<?php endif; ?>
																	</article>
																<?php endforeach; ?>
															</div>
														</div>
													<?php endif; ?>

													<?php if ( ! empty( $linked_runtime_events ) ) : ?>
														<div class="pcd-finding-detail-section">
															<h4><?php esc_html_e( 'Matching Runtime Events', 'conflict-debugger' ); ?></h4>
															<ul class="pcd-meta-list">
																<?php foreach ( $linked_runtime_events as $runtime_link ) : ?>
																	<li>
																		<a
																			href="<?php echo esc_url( '#' . (string) ( $runtime_link['id'] ?? '' ) ); ?>"
																			class="pcd-runtime-event-link"
																			data-pcd-open-tab="diagnostics"
																			data-pcd-scroll-target="<?php echo esc_attr( '#' . (string) ( $runtime_link['id'] ?? '' ) ); ?>"
																		>
																			<?php echo esc_html( (string) ( $runtime_link['label'] ?? __( 'Runtime event', 'conflict-debugger' ) ) ); ?>
																		</a>
																	</li>
																<?php endforeach; ?>
															</ul>
														</div>
													<?php endif; ?>
												</div>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							</div>
						<?php endif; ?>
					</section>
				</section>

				<section class="pcd-tab-panel" data-pcd-tab-panel="plugins" hidden>
					<section class="pcd-panel">
						<div class="pcd-panel-header">
							<h2><?php esc_html_e( 'Plugin Drilldown', 'conflict-debugger' ); ?></h2>
							<p><?php esc_html_e( 'Inspect one plugin at a time to see its categories, active findings, runtime contexts, and likely interaction hotspots.', 'conflict-debugger' ); ?></p>
						</div>
						<?php if ( empty( $plugin_drilldown ) ) : ?>
							<div class="pcd-empty-state">
								<h3><?php esc_html_e( 'No plugin drilldown data yet', 'conflict-debugger' ); ?></h3>
								<p><?php esc_html_e( 'Run a scan to build per-plugin diagnostics and relationship context.', 'conflict-debugger' ); ?></p>
							</div>
						<?php else : ?>
							<div class="pcd-drilldown-toolbar">
								<label class="screen-reader-text" for="pcd-plugin-drilldown-select"><?php esc_html_e( 'Select a plugin', 'conflict-debugger' ); ?></label>
								<select id="pcd-plugin-drilldown-select" class="pcd-plugin-select" data-pcd-plugin-select="true">
									<?php foreach ( $plugin_drilldown as $plugin_slug => $plugin_data ) : ?>
										<option value="<?php echo esc_attr( (string) $plugin_slug ); ?>"><?php echo esc_html( (string) ( $plugin_data['name'] ?? $plugin_slug ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>

							<?php foreach ( $plugin_drilldown as $plugin_slug => $plugin_data ) : ?>
								<section class="pcd-plugin-drilldown-card" data-pcd-plugin-card="<?php echo esc_attr( (string) $plugin_slug ); ?>" hidden>
									<div class="pcd-plugin-drilldown-header">
										<div>
											<h3><?php echo esc_html( (string) ( $plugin_data['name'] ?? $plugin_slug ) ); ?></h3>
											<p class="description">
												<?php
												echo esc_html(
													sprintf(
														/* translators: 1: plugin version, 2: finding count. */
														__( 'Version %1$s. %2$d current finding(s) involve this plugin.', 'conflict-debugger' ),
														(string) ( $plugin_data['version'] ?? '-' ),
														(int) ( $plugin_data['finding_count'] ?? 0 )
													)
												);
												?>
											</p>
										</div>
										<div class="pcd-plugin-badges">
											<?php foreach ( (array) ( $plugin_data['categories'] ?? array() ) as $category ) : ?>
												<span class="pcd-status-badge pcd-status-info"><?php echo esc_html( ucfirst( str_replace( '-', ' ', (string) $category ) ) ); ?></span>
											<?php endforeach; ?>
										</div>
									</div>

									<div class="pcd-drilldown-grid">
										<div class="pcd-drilldown-stat">
											<span class="pcd-summary-label"><?php esc_html_e( 'Findings', 'conflict-debugger' ); ?></span>
											<strong class="pcd-summary-value"><?php echo esc_html( (string) ( $plugin_data['finding_count'] ?? 0 ) ); ?></strong>
										</div>
										<div class="pcd-drilldown-stat">
											<span class="pcd-summary-label"><?php esc_html_e( 'Top Severity', 'conflict-debugger' ); ?></span>
											<strong class="pcd-summary-value pcd-summary-value-small"><?php echo esc_html( ucfirst( (string) ( $plugin_data['top_severity'] ?? 'info' ) ) ); ?></strong>
										</div>
										<div class="pcd-drilldown-stat">
											<span class="pcd-summary-label"><?php esc_html_e( 'Request Contexts', 'conflict-debugger' ); ?></span>
											<strong class="pcd-summary-value pcd-summary-value-small"><?php echo esc_html( (string) count( (array) ( $plugin_data['contexts'] ?? array() ) ) ); ?></strong>
										</div>
										<div class="pcd-drilldown-stat">
											<span class="pcd-summary-label"><?php esc_html_e( 'Related Plugins', 'conflict-debugger' ); ?></span>
											<strong class="pcd-summary-value pcd-summary-value-small"><?php echo esc_html( (string) count( (array) ( $plugin_data['related_plugins'] ?? array() ) ) ); ?></strong>
										</div>
									</div>

									<div class="pcd-drilldown-meta">
										<div>
											<h4><?php esc_html_e( 'Observed Contexts', 'conflict-debugger' ); ?></h4>
											<p><?php echo esc_html( ! empty( $plugin_data['contexts'] ) ? implode( ', ', (array) $plugin_data['contexts'] ) : __( 'No contexts captured yet.', 'conflict-debugger' ) ); ?></p>
										</div>
										<div>
											<h4><?php esc_html_e( 'Related Plugins', 'conflict-debugger' ); ?></h4>
											<p><?php echo esc_html( ! empty( $plugin_data['related_plugins'] ) ? implode( ', ', (array) $plugin_data['related_plugins'] ) : __( 'No related plugins flagged in the latest scan.', 'conflict-debugger' ) ); ?></p>
										</div>
									</div>

									<?php if ( ! empty( $plugin_data['findings'] ) ) : ?>
										<div class="pcd-plugin-findings-list">
											<?php foreach ( (array) $plugin_data['findings'] as $plugin_finding ) : ?>
												<article class="pcd-plugin-finding-item">
													<div class="pcd-plugin-finding-topline">
														<span class="pcd-status-badge pcd-status-<?php echo esc_attr( (string) ( $plugin_finding['severity'] ?? 'info' ) ); ?>"><?php echo esc_html( ucfirst( (string) ( $plugin_finding['severity'] ?? 'info' ) ) ); ?></span>
														<strong><?php echo esc_html( (string) ( $plugin_finding['title'] ?? '' ) ); ?></strong>
													</div>
													<p><?php echo esc_html( (string) ( $plugin_finding['summary'] ?? '' ) ); ?></p>
													<p class="pcd-actionability-note"><?php echo esc_html( (string) ( $plugin_finding['meta'] ?? '' ) ); ?></p>
												</article>
											<?php endforeach; ?>
										</div>
									<?php else : ?>
										<p><?php esc_html_e( 'This plugin is active but does not appear in any current findings.', 'conflict-debugger' ); ?></p>
									<?php endif; ?>
								</section>
							<?php endforeach; ?>
						<?php endif; ?>
					</section>
				</section>

				<section class="pcd-tab-panel" data-pcd-tab-panel="diagnostics" hidden>
					<div class="pcd-diagnostics-grid">
						<section class="pcd-panel">
							<h2><?php esc_html_e( 'Diagnostic Session', 'conflict-debugger' ); ?></h2>
							<p><?php esc_html_e( 'Start a focused session, reproduce the problem in that site area, then run a scan to review telemetry captured for that trace.', 'conflict-debugger' ); ?></p>
							<div class="pcd-session-controls" data-pcd-session-controls="true">
								<label class="screen-reader-text" for="pcd-session-context"><?php esc_html_e( 'Choose a site area for this diagnostic session', 'conflict-debugger' ); ?></label>
								<select id="pcd-session-context" class="pcd-plugin-select" data-pcd-session-context="true" <?php disabled( ! empty( $active_session ) ); ?>>
									<?php foreach ( $session_contexts as $context_key => $context_label ) : ?>
										<option value="<?php echo esc_attr( (string) $context_key ); ?>" <?php selected( (string) $context_key, (string) ( $active_session['target_context'] ?? 'all' ) ); ?>><?php echo esc_html( (string) $context_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="button" class="button button-primary" data-pcd-session-start="true" <?php disabled( ! empty( $active_session ) ); ?>><?php esc_html_e( 'Start Session', 'conflict-debugger' ); ?></button>
								<button type="button" class="button" data-pcd-session-end="true" <?php disabled( empty( $active_session ) ); ?>><?php esc_html_e( 'End Session', 'conflict-debugger' ); ?></button>
							</div>
							<div class="pcd-session-status" data-pcd-session-status="true">
								<?php if ( ! empty( $active_session ) ) : ?>
									<p>
										<span class="pcd-status-badge pcd-status-warning"><?php esc_html_e( 'Active', 'conflict-debugger' ); ?></span>
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s session label. */
												__( 'Focused on: %s', 'conflict-debugger' ),
												(string) ( $active_session['label'] ?? __( 'Any site area', 'conflict-debugger' ) )
											)
										);
										?>
									</p>
									<ul class="pcd-meta-list">
										<li><?php echo esc_html( $this->format_labeled_value( __( 'Started', 'conflict-debugger' ), Helpers::format_datetime( (string) ( $active_session['started_at'] ?? '' ) ) ) ); ?></li>
										<?php if ( ! empty( $active_session['last_activity_at'] ) ) : ?>
											<li><?php echo esc_html( $this->format_labeled_value( __( 'Last captured activity', 'conflict-debugger' ), Helpers::format_datetime( (string) $active_session['last_activity_at'] ) ) ); ?></li>
										<?php endif; ?>
										<?php if ( ! empty( $active_session['expires_at'] ) ) : ?>
											<li><?php echo esc_html( $this->format_labeled_value( __( 'Expires', 'conflict-debugger' ), Helpers::format_datetime( (string) $active_session['expires_at'] ) ) ); ?></li>
										<?php endif; ?>
									</ul>
								<?php elseif ( ! empty( $last_session ) ) : ?>
									<p>
										<span class="pcd-status-badge pcd-status-info"><?php esc_html_e( 'Last Session', 'conflict-debugger' ); ?></span>
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: label, 2: status. */
												__( '%1$s (%2$s)', 'conflict-debugger' ),
												(string) ( $last_session['label'] ?? __( 'Any site area', 'conflict-debugger' ) ),
												ucfirst( str_replace( '_', ' ', (string) ( $last_session['status'] ?? 'completed' ) ) )
											)
										);
										?>
									</p>
									<ul class="pcd-meta-list">
										<li><?php echo esc_html( $this->format_labeled_value( __( 'Started', 'conflict-debugger' ), Helpers::format_datetime( (string) ( $last_session['started_at'] ?? '' ) ) ) ); ?></li>
										<?php if ( ! empty( $last_session['ended_at'] ) ) : ?>
											<li><?php echo esc_html( $this->format_labeled_value( __( 'Ended', 'conflict-debugger' ), Helpers::format_datetime( (string) $last_session['ended_at'] ) ) ); ?></li>
										<?php endif; ?>
									</ul>
								<?php else : ?>
									<p><?php esc_html_e( 'No focused diagnostic session is active right now.', 'conflict-debugger' ); ?></p>
								<?php endif; ?>
							</div>
						</section>

						<section class="pcd-panel">
							<h2><?php esc_html_e( 'Validation Mode', 'conflict-debugger' ); ?></h2>
							<p><?php esc_html_e( 'Use focused validation when a finding needs deeper proof. This mode narrows telemetry to one plugin pair, hook, asset handle, REST route, or AJAX action so you can move from suspicion to a cleaner trace.', 'conflict-debugger' ); ?></p>
							<div class="pcd-session-controls pcd-validation-controls" data-pcd-validation-controls="true">
								<label class="screen-reader-text" for="pcd-validation-target-type"><?php esc_html_e( 'Choose a validation target type', 'conflict-debugger' ); ?></label>
								<select id="pcd-validation-target-type" class="pcd-plugin-select" data-pcd-validation-target-type="true" <?php disabled( ! empty( $active_validation ) ); ?>>
									<?php foreach ( $validation_targets as $target_key => $target_label ) : ?>
										<option value="<?php echo esc_attr( (string) $target_key ); ?>" <?php selected( (string) $target_key, (string) ( $active_validation['target_type'] ?? 'plugin_pair' ) ); ?>><?php echo esc_html( (string) $target_label ); ?></option>
									<?php endforeach; ?>
								</select>

								<label class="screen-reader-text" for="pcd-validation-plugin-a"><?php esc_html_e( 'Primary plugin for validation mode', 'conflict-debugger' ); ?></label>
								<select id="pcd-validation-plugin-a" class="pcd-plugin-select" data-pcd-validation-plugin-a="true" <?php disabled( ! empty( $active_validation ) ); ?>>
									<option value=""><?php esc_html_e( 'Choose plugin A', 'conflict-debugger' ); ?></option>
									<?php foreach ( $validation_plugins as $plugin_option ) : ?>
										<option value="<?php echo esc_attr( (string) ( $plugin_option['slug'] ?? '' ) ); ?>" <?php selected( (string) ( $plugin_option['slug'] ?? '' ), (string) ( $active_validation['plugin_a'] ?? '' ) ); ?>><?php echo esc_html( (string) ( $plugin_option['label'] ?? '' ) ); ?></option>
									<?php endforeach; ?>
								</select>

								<label class="screen-reader-text" for="pcd-validation-plugin-b"><?php esc_html_e( 'Secondary plugin for validation mode', 'conflict-debugger' ); ?></label>
								<select id="pcd-validation-plugin-b" class="pcd-plugin-select" data-pcd-validation-plugin-b="true" <?php disabled( ! empty( $active_validation ) ); ?>>
									<option value=""><?php esc_html_e( 'Choose plugin B (optional)', 'conflict-debugger' ); ?></option>
									<?php foreach ( $validation_plugins as $plugin_option ) : ?>
										<option value="<?php echo esc_attr( (string) ( $plugin_option['slug'] ?? '' ) ); ?>" <?php selected( (string) ( $plugin_option['slug'] ?? '' ), (string) ( $active_validation['plugin_b'] ?? '' ) ); ?>><?php echo esc_html( (string) ( $plugin_option['label'] ?? '' ) ); ?></option>
									<?php endforeach; ?>
								</select>

								<label class="screen-reader-text" for="pcd-validation-target-value"><?php esc_html_e( 'Target value for validation mode', 'conflict-debugger' ); ?></label>
								<input
									id="pcd-validation-target-value"
									type="text"
									class="regular-text"
									data-pcd-validation-target-value="true"
									value="<?php echo esc_attr( (string) ( $active_validation['target_value'] ?? '' ) ); ?>"
									placeholder="<?php esc_attr_e( 'Hook, handle, route, or action', 'conflict-debugger' ); ?>"
									<?php disabled( ! empty( $active_validation ) ); ?>
								/>

								<button type="button" class="button button-primary" data-pcd-validation-start="true" <?php disabled( ! empty( $active_validation ) ); ?>><?php esc_html_e( 'Start Validation', 'conflict-debugger' ); ?></button>
								<button type="button" class="button" data-pcd-validation-end="true" <?php disabled( empty( $active_validation ) ); ?>><?php esc_html_e( 'End Validation', 'conflict-debugger' ); ?></button>
							</div>
							<p class="pcd-summary-note"><?php esc_html_e( 'Tip: use plugin pair mode for a suspicious finding, or switch to hook/asset/route/action mode when you already know the exact surface to validate.', 'conflict-debugger' ); ?></p>
							<div class="pcd-session-status" data-pcd-validation-status="true">
								<?php if ( ! empty( $active_validation ) ) : ?>
									<p>
										<span class="pcd-status-badge pcd-status-warning"><?php esc_html_e( 'Active', 'conflict-debugger' ); ?></span>
										<?php echo esc_html( (string) ( $active_validation['label'] ?? __( 'Focused validation', 'conflict-debugger' ) ) ); ?>
									</p>
									<ul class="pcd-meta-list">
										<?php if ( ! empty( $active_validation['started_at'] ) ) : ?>
											<li><?php echo esc_html( $this->format_labeled_value( __( 'Started', 'conflict-debugger' ), Helpers::format_datetime( (string) $active_validation['started_at'] ) ) ); ?></li>
										<?php endif; ?>
										<?php if ( ! empty( $active_validation['last_activity_at'] ) ) : ?>
											<li><?php echo esc_html( $this->format_labeled_value( __( 'Last matching trace', 'conflict-debugger' ), Helpers::format_datetime( (string) $active_validation['last_activity_at'] ) ) ); ?></li>
										<?php endif; ?>
										<?php if ( ! empty( $active_validation['expires_at'] ) ) : ?>
											<li><?php echo esc_html( $this->format_labeled_value( __( 'Expires', 'conflict-debugger' ), Helpers::format_datetime( (string) $active_validation['expires_at'] ) ) ); ?></li>
										<?php endif; ?>
									</ul>
								<?php elseif ( ! empty( $last_validation ) ) : ?>
									<p>
										<span class="pcd-status-badge pcd-status-info"><?php esc_html_e( 'Last Validation', 'conflict-debugger' ); ?></span>
										<?php echo esc_html( (string) ( $last_validation['label'] ?? __( 'Focused validation', 'conflict-debugger' ) ) ); ?>
									</p>
									<ul class="pcd-meta-list">
										<?php if ( ! empty( $last_validation['started_at'] ) ) : ?>
											<li><?php echo esc_html( $this->format_labeled_value( __( 'Started', 'conflict-debugger' ), Helpers::format_datetime( (string) $last_validation['started_at'] ) ) ); ?></li>
										<?php endif; ?>
										<?php if ( ! empty( $last_validation['ended_at'] ) ) : ?>
											<li><?php echo esc_html( $this->format_labeled_value( __( 'Ended', 'conflict-debugger' ), Helpers::format_datetime( (string) $last_validation['ended_at'] ) ) ); ?></li>
										<?php endif; ?>
										<?php if ( ! empty( $last_validation['status'] ) ) : ?>
											<li><?php echo esc_html( $this->format_labeled_value( __( 'Status', 'conflict-debugger' ), ucfirst( str_replace( '_', ' ', (string) $last_validation['status'] ) ) ) ); ?></li>
										<?php endif; ?>
									</ul>
								<?php else : ?>
									<p><?php esc_html_e( 'No focused validation mode is active right now.', 'conflict-debugger' ); ?></p>
								<?php endif; ?>
							</div>
						</section>

						<section class="pcd-panel">
							<h2><?php esc_html_e( 'Log Access Check', 'conflict-debugger' ); ?></h2>
							<?php if ( ! empty( $log_access ) ) : ?>
								<p>
									<span class="pcd-status-badge pcd-status-<?php echo esc_attr( ! empty( $log_access['readable'] ) ? 'healthy' : 'warning' ); ?>">
										<?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) ( $log_access['status'] ?? __( 'Unknown', 'conflict-debugger' ) ) ) ) ); ?>
									</span>
								</p>
								<p><?php echo esc_html( (string) ( $log_access['status_message'] ?? '' ) ); ?></p>
								<ul class="pcd-meta-list">
									<li><?php echo esc_html( $this->format_labeled_value( __( 'WP_DEBUG', 'conflict-debugger' ), ! empty( $log_access['wp_debug'] ) ? __( 'Enabled', 'conflict-debugger' ) : __( 'Disabled', 'conflict-debugger' ) ) ); ?></li>
									<li><?php echo esc_html( $this->format_labeled_value( __( 'WP_DEBUG_LOG', 'conflict-debugger' ), ! empty( $log_access['wp_debug_log'] ) ? __( 'Enabled', 'conflict-debugger' ) : __( 'Disabled', 'conflict-debugger' ) ) ); ?></li>
									<li><?php echo esc_html( $this->format_labeled_value( __( 'File exists', 'conflict-debugger' ), ! empty( $log_access['exists'] ) ? __( 'Yes', 'conflict-debugger' ) : __( 'No', 'conflict-debugger' ) ) ); ?></li>
									<li><?php echo esc_html( $this->format_labeled_value( __( 'Readable by PHP', 'conflict-debugger' ), ! empty( $log_access['readable'] ) ? __( 'Yes', 'conflict-debugger' ) : __( 'No', 'conflict-debugger' ) ) ); ?></li>
									<li><?php echo esc_html( $this->format_labeled_value( __( 'Writable by server', 'conflict-debugger' ), ! empty( $log_access['writable'] ) ? __( 'Yes', 'conflict-debugger' ) : __( 'No', 'conflict-debugger' ) ) ); ?></li>
								</ul>
								<?php if ( ! empty( $log_access['path'] ) ) : ?>
									<code class="pcd-request-context-uri"><?php echo esc_html( (string) $log_access['path'] ); ?></code>
								<?php endif; ?>
								<?php if ( ! empty( $log_access['recommendations'] ) && is_array( $log_access['recommendations'] ) ) : ?>
									<ul class="pcd-meta-list">
										<?php foreach ( $log_access['recommendations'] as $recommendation ) : ?>
											<li><?php echo esc_html( (string) $recommendation ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							<?php else : ?>
								<p><?php esc_html_e( 'No log access data is available yet. Run a scan to populate this check.', 'conflict-debugger' ); ?></p>
							<?php endif; ?>
						</section>

						<?php if ( $has_results && ! empty( $results['environment'] ) && is_array( $results['environment'] ) ) : ?>
							<section class="pcd-panel">
								<h2><?php esc_html_e( 'Environment Snapshot', 'conflict-debugger' ); ?></h2>
								<ul class="pcd-meta-list">
									<li><?php echo esc_html( $this->format_labeled_value( __( 'WordPress', 'conflict-debugger' ), (string) ( $results['environment']['wordpress_version'] ?? '-' ) ) ); ?></li>
									<li><?php echo esc_html( $this->format_labeled_value( __( 'PHP', 'conflict-debugger' ), (string) ( $results['environment']['php_version'] ?? '-' ) ) ); ?></li>
									<li><?php echo esc_html( $this->format_labeled_value( __( 'Theme', 'conflict-debugger' ), (string) ( $results['environment']['active_theme'] ?? '-' ) ) ); ?></li>
									<li><?php echo esc_html( $this->format_labeled_value( __( 'Multisite', 'conflict-debugger' ), ! empty( $results['environment']['is_multisite'] ) ? __( 'Yes', 'conflict-debugger' ) : __( 'No', 'conflict-debugger' ) ) ); ?></li>
									<li><?php echo esc_html( $this->format_labeled_value( __( 'Debug mode', 'conflict-debugger' ), ! empty( $results['environment']['wp_debug'] ) ? __( 'Enabled', 'conflict-debugger' ) : __( 'Disabled', 'conflict-debugger' ) ) ); ?></li>
								</ul>
							</section>
						<?php endif; ?>

						<section class="pcd-panel">
							<h2><?php esc_html_e( 'Analysis Notes', 'conflict-debugger' ); ?></h2>
							<?php if ( $logs_unavailable ) : ?>
								<p><?php esc_html_e( 'Direct log access is unavailable. Analysis is based on runtime and plugin interaction signals.', 'conflict-debugger' ); ?></p>
							<?php endif; ?>
							<?php if ( ! empty( $analysis_notes ) ) : ?>
								<ul class="pcd-meta-list">
									<?php foreach ( $analysis_notes as $analysis_note ) : ?>
										<li><?php echo esc_html( (string) $analysis_note ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php else : ?>
								<p><?php esc_html_e( 'No extra analysis notes were captured in this scan.', 'conflict-debugger' ); ?></p>
							<?php endif; ?>
						</section>

						<?php if ( $has_results && ! empty( $results['request_contexts'] ) && is_array( $results['request_contexts'] ) ) : ?>
							<section class="pcd-panel pcd-panel-span-2">
								<h2><?php esc_html_e( 'Recent Request Contexts', 'conflict-debugger' ); ?></h2>
								<div class="pcd-request-contexts">
									<?php foreach ( array_slice( $results['request_contexts'], 0, 10 ) as $request_context ) : ?>
										<article class="pcd-request-context-card">
											<div class="pcd-request-context-topline">
												<span class="pcd-status-badge pcd-status-info"><?php echo esc_html( (string) ( $request_context['request_context'] ?? __( 'runtime', 'conflict-debugger' ) ) ); ?></span>
												<?php if ( ! empty( $request_context['timestamp'] ) ) : ?>
													<span class="pcd-request-context-time"><?php echo esc_html( Helpers::format_datetime( (string) $request_context['timestamp'] ) ); ?></span>
												<?php endif; ?>
											</div>
											<code class="pcd-request-context-uri"><?php echo esc_html( (string) ( $request_context['request_uri'] ?? '/' ) ); ?></code>
										</article>
									<?php endforeach; ?>
								</div>
							</section>
						<?php endif; ?>

						<section class="pcd-panel pcd-panel-span-2">
							<h2><?php esc_html_e( 'Compare Diagnostic Traces', 'conflict-debugger' ); ?></h2>
							<?php if ( ! empty( $trace_snapshot['comparison']['has_comparison'] ) ) : ?>
								<?php
								$trace_comparison = is_array( $trace_snapshot['comparison'] ?? null ) ? $trace_snapshot['comparison'] : array();
								$primary_trace    = is_array( $trace_comparison['primary'] ?? null ) ? $trace_comparison['primary'] : array();
								$secondary_trace  = is_array( $trace_comparison['secondary'] ?? null ) ? $trace_comparison['secondary'] : array();
								$primary_only     = is_array( $trace_comparison['only_in_primary'] ?? null ) ? $trace_comparison['only_in_primary'] : array();
								$secondary_only   = is_array( $trace_comparison['only_in_secondary'] ?? null ) ? $trace_comparison['only_in_secondary'] : array();
								$metric_delta     = is_array( $trace_comparison['metric_delta'] ?? null ) ? $trace_comparison['metric_delta'] : array();
								?>
								<?php if ( ! empty( $trace_snapshot['focus_label'] ) ) : ?>
									<p class="pcd-summary-note">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s session label. */
												__( 'Focused on traces captured in the diagnostic session: %s', 'conflict-debugger' ),
												(string) $trace_snapshot['focus_label']
											)
										);
										?>
									</p>
								<?php endif; ?>
								<p class="pcd-actionability-note"><?php echo esc_html( (string) ( $trace_comparison['explanation'] ?? '' ) ); ?></p>
								<div class="pcd-trace-compare-grid">
									<article class="pcd-trace-compare-card pcd-trace-compare-card-primary">
										<div class="pcd-runtime-event-topline">
											<div class="pcd-runtime-event-badges">
												<span class="pcd-status-badge pcd-status-<?php echo esc_attr( (string) ( $primary_trace['health'] ?? 'warning' ) ); ?>">
													<?php esc_html_e( 'Affected Trace', 'conflict-debugger' ); ?>
												</span>
												<?php if ( ! empty( $primary_trace['request_context'] ) ) : ?>
													<span class="pcd-status-badge pcd-status-info"><?php echo esc_html( (string) $primary_trace['request_context'] ); ?></span>
												<?php endif; ?>
											</div>
											<?php if ( ! empty( $primary_trace['last_seen'] ) ) : ?>
												<span class="pcd-request-context-time"><?php echo esc_html( Helpers::format_datetime( (string) $primary_trace['last_seen'] ) ); ?></span>
											<?php endif; ?>
										</div>
										<h3><?php echo esc_html( (string) ( $primary_trace['label'] ?? __( 'Affected trace', 'conflict-debugger' ) ) ); ?></h3>
										<p><?php echo esc_html( (string) ( $primary_trace['summary'] ?? '' ) ); ?></p>
										<?php if ( ! empty( $primary_trace['request_uri'] ) ) : ?>
											<code class="pcd-request-context-uri"><?php echo esc_html( (string) $primary_trace['request_uri'] ); ?></code>
										<?php endif; ?>
									</article>
									<article class="pcd-trace-compare-card">
										<div class="pcd-runtime-event-topline">
											<div class="pcd-runtime-event-badges">
												<span class="pcd-status-badge pcd-status-<?php echo esc_attr( (string) ( $secondary_trace['health'] ?? 'info' ) ); ?>">
													<?php esc_html_e( 'Comparison Trace', 'conflict-debugger' ); ?>
												</span>
												<?php if ( ! empty( $secondary_trace['request_context'] ) ) : ?>
													<span class="pcd-status-badge pcd-status-info"><?php echo esc_html( (string) $secondary_trace['request_context'] ); ?></span>
												<?php endif; ?>
											</div>
											<?php if ( ! empty( $secondary_trace['last_seen'] ) ) : ?>
												<span class="pcd-request-context-time"><?php echo esc_html( Helpers::format_datetime( (string) $secondary_trace['last_seen'] ) ); ?></span>
											<?php endif; ?>
										</div>
										<h3><?php echo esc_html( (string) ( $secondary_trace['label'] ?? __( 'Comparison trace', 'conflict-debugger' ) ) ); ?></h3>
										<p><?php echo esc_html( (string) ( $secondary_trace['summary'] ?? '' ) ); ?></p>
										<?php if ( ! empty( $secondary_trace['request_uri'] ) ) : ?>
											<code class="pcd-request-context-uri"><?php echo esc_html( (string) $secondary_trace['request_uri'] ); ?></code>
										<?php endif; ?>
									</article>
								</div>
								<div class="pcd-runtime-summary-grid">
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'Request Delta', 'conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value-small"><?php echo esc_html( $this->format_trace_delta( (int) ( $metric_delta['requests'] ?? 0 ) ) ); ?></strong>
									</div>
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'Event Delta', 'conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value-small"><?php echo esc_html( $this->format_trace_delta( (int) ( $metric_delta['events'] ?? 0 ) ) ); ?></strong>
									</div>
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'Failure Delta', 'conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value-small"><?php echo esc_html( $this->format_trace_delta( (int) ( $metric_delta['failures'] ?? 0 ) ) ); ?></strong>
									</div>
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'Mutation Delta', 'conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value-small"><?php echo esc_html( $this->format_trace_delta( (int) ( $metric_delta['mutations'] ?? 0 ) ) ); ?></strong>
									</div>
								</div>
								<div class="pcd-compare-lists">
									<div>
										<h4><?php esc_html_e( 'Only In Affected Trace', 'conflict-debugger' ); ?></h4>
										<?php $this->render_trace_diff_lists( $primary_only ); ?>
									</div>
									<div>
										<h4><?php esc_html_e( 'Only In Comparison Trace', 'conflict-debugger' ); ?></h4>
										<?php $this->render_trace_diff_lists( $secondary_only ); ?>
									</div>
								</div>
							<?php elseif ( ! empty( $trace_snapshot['traces'] ) ) : ?>
								<p class="pcd-actionability-note"><?php esc_html_e( 'At least two related traces are needed before a request comparison can be shown. Reproduce the issue again in the same diagnostic session to capture a stronger before-and-after baseline.', 'conflict-debugger' ); ?></p>
								<div class="pcd-trace-list">
									<?php foreach ( array_slice( (array) $trace_snapshot['traces'], 0, 4 ) as $trace_item ) : ?>
										<article class="pcd-trace-list-item">
											<div class="pcd-plugin-finding-topline">
												<span class="pcd-status-badge pcd-status-<?php echo esc_attr( (string) ( $trace_item['health'] ?? 'info' ) ); ?>">
													<?php echo esc_html( ucfirst( (string) ( $trace_item['health'] ?? __( 'Info', 'conflict-debugger' ) ) ) ); ?>
												</span>
												<?php if ( ! empty( $trace_item['request_context'] ) ) : ?>
													<span class="pcd-status-badge pcd-status-info"><?php echo esc_html( (string) $trace_item['request_context'] ); ?></span>
												<?php endif; ?>
											</div>
											<h4><?php echo esc_html( (string) ( $trace_item['label'] ?? __( 'Captured trace', 'conflict-debugger' ) ) ); ?></h4>
											<p><?php echo esc_html( (string) ( $trace_item['summary'] ?? '' ) ); ?></p>
										</article>
									<?php endforeach; ?>
								</div>
							<?php else : ?>
								<p><?php esc_html_e( 'No comparable request traces were captured yet. Start a diagnostic session, reproduce the issue path, and run another scan to build a trace comparison.', 'conflict-debugger' ); ?></p>
							<?php endif; ?>
						</section>

						<section class="pcd-panel pcd-panel-span-2">
							<h2><?php esc_html_e( 'Recent Runtime Events', 'conflict-debugger' ); ?></h2>
							<?php if ( ! empty( $runtime_event_view['events'] ) ) : ?>
								<?php if ( ! empty( $runtime_event_view['focus_label'] ) ) : ?>
									<p class="pcd-summary-note">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s session label. */
												__( 'Showing events captured for the focused diagnostic session: %s', 'conflict-debugger' ),
												(string) $runtime_event_view['focus_label']
											)
										);
										?>
									</p>
								<?php endif; ?>
								<?php if ( ! empty( $runtime_event_view['validation_focus_label'] ) ) : ?>
									<p class="pcd-summary-note">
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: validation label, 2: event count. */
												__( 'Showing events matched to the current validation mode: %1$s (%2$d matching traces).', 'conflict-debugger' ),
												(string) $runtime_event_view['validation_focus_label'],
												(int) ( $runtime_event_view['validation_matches'] ?? 0 )
											)
										);
										?>
									</p>
								<?php endif; ?>
								<div class="pcd-runtime-summary-grid">
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'Captured Events', 'conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value"><?php echo esc_html( (string) ( $runtime_event_view['summary']['total'] ?? 0 ) ); ?></strong>
									</div>
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'JS Errors', 'conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value"><?php echo esc_html( (string) ( $runtime_event_view['summary']['js_errors'] ?? 0 ) ); ?></strong>
									</div>
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'Failed Requests', 'conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value"><?php echo esc_html( (string) ( $runtime_event_view['summary']['failed_requests'] ?? 0 ) ); ?></strong>
									</div>
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'Mutation Signals', 'conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value"><?php echo esc_html( (string) ( $runtime_event_view['summary']['mutations'] ?? 0 ) ); ?></strong>
									</div>
								</div>
								<div class="pcd-runtime-events-list">
									<?php foreach ( (array) $runtime_event_view['events'] as $runtime_event ) : ?>
										<article id="<?php echo esc_attr( (string) ( $runtime_event['id'] ?? '' ) ); ?>" class="pcd-runtime-event-card">
											<div class="pcd-runtime-event-topline">
												<div class="pcd-runtime-event-badges">
													<span class="pcd-status-badge pcd-status-<?php echo esc_attr( (string) ( $runtime_event['level_badge'] ?? 'info' ) ); ?>">
														<?php echo esc_html( (string) ( $runtime_event['type_label'] ?? __( 'Runtime Event', 'conflict-debugger' ) ) ); ?>
													</span>
													<?php if ( ! empty( $runtime_event['request_context'] ) ) : ?>
														<span class="pcd-status-badge pcd-status-info"><?php echo esc_html( (string) $runtime_event['request_context'] ); ?></span>
													<?php endif; ?>
													<?php if ( ! empty( $runtime_event['validation_matched'] ) ) : ?>
														<span class="pcd-status-badge pcd-status-warning"><?php esc_html_e( 'Validation Match', 'conflict-debugger' ); ?></span>
													<?php endif; ?>
												</div>
												<?php if ( ! empty( $runtime_event['timestamp'] ) ) : ?>
													<span class="pcd-request-context-time"><?php echo esc_html( Helpers::format_datetime( (string) $runtime_event['timestamp'] ) ); ?></span>
												<?php endif; ?>
											</div>
											<h4><?php echo esc_html( (string) ( $runtime_event['title'] ?? __( 'Runtime event', 'conflict-debugger' ) ) ); ?></h4>
											<p><?php echo esc_html( (string) ( $runtime_event['message'] ?? '' ) ); ?></p>
											<div class="pcd-runtime-event-meta">
												<?php if ( ! empty( $runtime_event['request_uri'] ) ) : ?>
													<div>
														<span class="pcd-summary-label"><?php esc_html_e( 'Request', 'conflict-debugger' ); ?></span>
														<code class="pcd-request-context-uri"><?php echo esc_html( (string) $runtime_event['request_uri'] ); ?></code>
														<?php if ( ! empty( $runtime_event['request_scope'] ) ) : ?>
															<p class="pcd-summary-note"><?php echo esc_html( $this->format_labeled_value( __( 'Scope', 'conflict-debugger' ), (string) $runtime_event['request_scope'] ) ); ?></p>
														<?php endif; ?>
													</div>
												<?php endif; ?>
												<div>
													<ul class="pcd-meta-list">
														<?php if ( ! empty( $runtime_event['resource'] ) ) : ?>
															<li><?php echo esc_html( $this->format_labeled_value( __( 'Resource', 'conflict-debugger' ), (string) $runtime_event['resource'] ) ); ?></li>
														<?php endif; ?>
														<?php if ( ! empty( $runtime_event['execution_surface'] ) ) : ?>
															<li><?php echo esc_html( $this->format_labeled_value( __( 'Execution surface', 'conflict-debugger' ), (string) $runtime_event['execution_surface'] ) ); ?></li>
														<?php endif; ?>
														<?php if ( ! empty( $runtime_event['hook'] ) ) : ?>
															<li><?php echo esc_html( $this->format_labeled_value( __( 'Hook', 'conflict-debugger' ), (string) $runtime_event['hook'] ) ); ?></li>
														<?php endif; ?>
														<?php if ( ! empty( $runtime_event['actor_label'] ) ) : ?>
															<li><?php echo esc_html( $this->format_labeled_value( __( 'Mutating actor', 'conflict-debugger' ), (string) $runtime_event['actor_label'] ) ); ?></li>
														<?php endif; ?>
														<?php if ( ! empty( $runtime_event['target_owner_label'] ) ) : ?>
															<li><?php echo esc_html( $this->format_labeled_value( __( 'Target owner', 'conflict-debugger' ), (string) $runtime_event['target_owner_label'] ) ); ?></li>
														<?php endif; ?>
														<?php if ( ! empty( $runtime_event['attribution_label'] ) ) : ?>
															<li><?php echo esc_html( $this->format_labeled_value( __( 'Attribution', 'conflict-debugger' ), (string) $runtime_event['attribution_label'] ) ); ?></li>
														<?php endif; ?>
														<?php if ( ! empty( $runtime_event['mutation_label'] ) ) : ?>
															<li><?php echo esc_html( $this->format_labeled_value( __( 'Mutation state', 'conflict-debugger' ), (string) $runtime_event['mutation_label'] ) ); ?></li>
														<?php endif; ?>
														<?php if ( ! empty( $runtime_event['evidence_source'] ) ) : ?>
															<li><?php echo esc_html( $this->format_labeled_value( __( 'Evidence source', 'conflict-debugger' ), (string) $runtime_event['evidence_source'] ) ); ?></li>
														<?php endif; ?>
														<?php if ( ! empty( $runtime_event['validation_label'] ) ) : ?>
															<li><?php echo esc_html( $this->format_labeled_value( __( 'Validation mode', 'conflict-debugger' ), (string) $runtime_event['validation_label'] ) ); ?></li>
														<?php endif; ?>
														<?php if ( ! empty( $runtime_event['failure_mode'] ) ) : ?>
															<li><?php echo esc_html( $this->format_labeled_value( __( 'Failure mode', 'conflict-debugger' ), (string) $runtime_event['failure_mode'] ) ); ?></li>
														<?php endif; ?>
														<?php if ( ! empty( $runtime_event['status_code'] ) ) : ?>
															<li><?php echo esc_html( $this->format_labeled_value( __( 'HTTP status', 'conflict-debugger' ), (string) (int) $runtime_event['status_code'] ) ); ?></li>
														<?php endif; ?>
														<?php if ( ! empty( $runtime_event['owner_labels'] ) ) : ?>
															<li><?php echo esc_html( $this->format_labeled_value( __( 'Observed owners', 'conflict-debugger' ), implode( ', ', (array) $runtime_event['owner_labels'] ) ) ); ?></li>
														<?php endif; ?>
													</ul>
												</div>
											</div>
											<?php if ( ! empty( $runtime_event['resource_hints'] ) ) : ?>
												<p class="pcd-actionability-note"><?php echo esc_html( $this->format_labeled_value( __( 'Resource hints', 'conflict-debugger' ), implode( ', ', (array) $runtime_event['resource_hints'] ) ) ); ?></p>
											<?php endif; ?>
											<?php if ( ! empty( $runtime_event['state_changes'] ) ) : ?>
												<div class="pcd-runtime-event-links">
													<strong><?php esc_html_e( 'State changes:', 'conflict-debugger' ); ?></strong>
													<ul class="pcd-meta-list">
														<?php foreach ( (array) $runtime_event['state_changes'] as $state_change ) : ?>
															<li><?php echo esc_html( (string) $state_change ); ?></li>
														<?php endforeach; ?>
													</ul>
												</div>
											<?php endif; ?>
											<?php if ( ! empty( $runtime_event['matched_findings'] ) ) : ?>
												<div class="pcd-runtime-event-links">
													<strong><?php esc_html_e( 'Linked findings:', 'conflict-debugger' ); ?></strong>
													<ul class="pcd-meta-list">
														<?php foreach ( (array) $runtime_event['matched_findings'] as $matched_finding ) : ?>
															<li><?php echo esc_html( (string) $matched_finding ); ?></li>
														<?php endforeach; ?>
													</ul>
												</div>
											<?php endif; ?>
										</article>
									<?php endforeach; ?>
								</div>
							<?php else : ?>
								<p><?php esc_html_e( 'No recent runtime events were captured yet. Start a diagnostic session, reproduce the issue, and run another scan to populate this viewer.', 'conflict-debugger' ); ?></p>
							<?php endif; ?>
						</section>

						<section class="pcd-panel pcd-panel-span-2">
							<h2><?php esc_html_e( 'Compare Last Two Scans', 'conflict-debugger' ); ?></h2>
							<?php if ( ! empty( $scan_comparison['has_previous'] ) ) : ?>
								<div class="pcd-compare-grid">
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'Current Conflicts', 'conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value"><?php echo esc_html( (string) ( $scan_comparison['current_conflicts'] ?? 0 ) ); ?></strong>
									</div>
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'Previous Conflicts', 'conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value"><?php echo esc_html( (string) ( $scan_comparison['previous_conflicts'] ?? 0 ) ); ?></strong>
									</div>
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'New Findings', 'conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value"><?php echo esc_html( (string) count( (array) ( $scan_comparison['new_findings'] ?? array() ) ) ); ?></strong>
									</div>
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'Resolved Findings', 'conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value"><?php echo esc_html( (string) count( (array) ( $scan_comparison['resolved_findings'] ?? array() ) ) ); ?></strong>
									</div>
								</div>
								<div class="pcd-drilldown-meta">
									<div>
										<h4><?php esc_html_e( 'Current Scan', 'conflict-debugger' ); ?></h4>
										<p><?php echo esc_html( Helpers::format_datetime( (string) ( $scan_comparison['current_timestamp'] ?? '' ) ) ); ?></p>
									</div>
									<div>
										<h4><?php esc_html_e( 'Previous Scan', 'conflict-debugger' ); ?></h4>
										<p><?php echo esc_html( Helpers::format_datetime( (string) ( $scan_comparison['previous_timestamp'] ?? '' ) ) ); ?></p>
									</div>
								</div>
								<div class="pcd-compare-lists">
									<div>
										<h4><?php esc_html_e( 'New Since Previous Scan', 'conflict-debugger' ); ?></h4>
										<?php if ( ! empty( $scan_comparison['new_findings'] ) ) : ?>
											<ul class="pcd-meta-list">
												<?php foreach ( (array) $scan_comparison['new_findings'] as $compare_item ) : ?>
													<li><?php echo esc_html( (string) $compare_item ); ?></li>
												<?php endforeach; ?>
											</ul>
										<?php else : ?>
											<p><?php esc_html_e( 'No new findings compared with the previous scan.', 'conflict-debugger' ); ?></p>
										<?php endif; ?>
									</div>
									<div>
										<h4><?php esc_html_e( 'Resolved Since Previous Scan', 'conflict-debugger' ); ?></h4>
										<?php if ( ! empty( $scan_comparison['resolved_findings'] ) ) : ?>
											<ul class="pcd-meta-list">
												<?php foreach ( (array) $scan_comparison['resolved_findings'] as $compare_item ) : ?>
													<li><?php echo esc_html( (string) $compare_item ); ?></li>
												<?php endforeach; ?>
											</ul>
										<?php else : ?>
											<p><?php esc_html_e( 'No findings appear to have resolved since the previous scan.', 'conflict-debugger' ); ?></p>
										<?php endif; ?>
									</div>
								</div>
							<?php else : ?>
								<p><?php esc_html_e( 'A second scan is needed before comparison data becomes available.', 'conflict-debugger' ); ?></p>
							<?php endif; ?>
						</section>

						<section class="pcd-panel pcd-panel-span-2">
							<h2><?php esc_html_e( 'Scan History', 'conflict-debugger' ); ?></h2>
							<?php if ( ! empty( $history ) ) : ?>
								<div class="pcd-history-table-wrap">
									<table class="widefat striped">
										<thead>
											<tr>
												<th><?php esc_html_e( 'Scan Time', 'conflict-debugger' ); ?></th>
												<th><?php esc_html_e( 'Site Status', 'conflict-debugger' ); ?></th>
												<th><?php esc_html_e( 'Conflicts', 'conflict-debugger' ); ?></th>
												<th><?php esc_html_e( 'Errors', 'conflict-debugger' ); ?></th>
												<th><?php esc_html_e( 'Trace Warnings', 'conflict-debugger' ); ?></th>
												<th><?php esc_html_e( 'Log Access', 'conflict-debugger' ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $history as $history_item ) : ?>
												<tr>
													<td><?php echo esc_html( Helpers::format_datetime( (string) ( $history_item['scan_timestamp'] ?? '' ) ) ); ?></td>
													<td>
														<span class="pcd-status-badge pcd-status-<?php echo esc_attr( (string) ( $history_item['site_status'] ?? 'healthy' ) ); ?>">
															<?php echo esc_html( ucfirst( (string) ( $history_item['site_status'] ?? __( 'Unknown', 'conflict-debugger' ) ) ) ); ?>
														</span>
													</td>
													<td><?php echo esc_html( (string) ( $history_item['summary']['likely_conflicts'] ?? 0 ) ); ?></td>
													<td><?php echo esc_html( (string) ( $history_item['summary']['error_signals'] ?? 0 ) ); ?></td>
													<td><?php echo esc_html( (string) ( $history_item['summary']['trace_warnings'] ?? 0 ) ); ?></td>
													<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) ( $history_item['log_access']['status'] ?? __( 'Unknown', 'conflict-debugger' ) ) ) ) ); ?></td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							<?php else : ?>
								<p><?php esc_html_e( 'No prior scan history is stored yet.', 'conflict-debugger' ); ?></p>
							<?php endif; ?>
						</section>
					</div>
				</section>

				<section class="pcd-tab-panel" data-pcd-tab-panel="pro" hidden>
					<section class="pcd-panel">
						<h2><?php esc_html_e( 'Pro Preview', 'conflict-debugger' ); ?></h2>
						<ul class="pcd-feature-list">
							<li><?php esc_html_e( 'Safe Test Mode for controlled plugin isolation', 'conflict-debugger' ); ?></li>
							<li><?php esc_html_e( 'Auto-Isolate Conflict using binary search workflows', 'conflict-debugger' ); ?></li>
							<li><?php esc_html_e( 'Scheduled scans and team alerts', 'conflict-debugger' ); ?></li>
						</ul>
						<p><?php esc_html_e( 'The current free foundation is built so these workflows can be added later without rewriting the scan engine.', 'conflict-debugger' ); ?></p>
					</section>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders a summary card.
	 *
	 * @param string $label Summary label.
	 * @param string $value Summary value.
	 * @return void
	 */
	private function render_summary_card( string $label, string $value, string $note = '' ): void {
		?>
		<div class="pcd-summary-card">
			<span class="pcd-summary-label"><?php echo esc_html( $label ); ?></span>
			<strong class="pcd-summary-value"><?php echo esc_html( $value ); ?></strong>
			<?php if ( '' !== $note ) : ?>
				<p class="pcd-summary-note"><?php echo esc_html( $note ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Formats a translated label/value pair for UI display.
	 *
	 * @param string $label Translated label.
	 * @param string $value Value text.
	 * @return string
	 */
	private function format_labeled_value( string $label, string $value ): string {
		return sprintf(
			/* translators: 1: label text, 2: value text. */
			__( '%1$s: %2$s', 'conflict-debugger' ),
			$label,
			$value
		);
	}

	/**
	 * Builds plugin options for validation mode selectors.
	 *
	 * @param array<string, mixed> $results Latest scan results.
	 * @return array<int, array<string, string>>
	 */
	private function build_validation_plugin_options( array $results ): array {
		$plugins = is_array( $results['plugins'] ?? null ) ? $results['plugins'] : array();
		$options = array();

		foreach ( $plugins as $plugin ) {
			$slug = sanitize_key( (string) ( $plugin['slug'] ?? '' ) );
			$name = sanitize_text_field( (string) ( $plugin['name'] ?? '' ) );

			if ( '' === $slug || '' === $name ) {
				continue;
			}

			$options[ $slug ] = array(
				'slug'  => $slug,
				'label' => $name,
			);
		}

		uasort(
			$options,
			static fn( array $left, array $right ): int => strcasecmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) )
		);

		return array_values( $options );
	}

	/**
	 * Builds per-plugin drilldown data from the latest scan.
	 *
	 * @param array<string, mixed> $results Latest scan results.
	 * @return array<string, array<string, mixed>>
	 */
	private function build_plugin_drilldown( array $results ): array {
		$plugins   = is_array( $results['plugins'] ?? null ) ? $results['plugins'] : array();
		$findings  = is_array( $results['findings'] ?? null ) ? $results['findings'] : array();
		$drilldown = array();

		foreach ( $plugins as $plugin ) {
			$slug = sanitize_key( (string) ( $plugin['slug'] ?? '' ) );
			if ( '' === $slug ) {
				continue;
			}

			$drilldown[ $slug ] = array(
				'slug'           => $slug,
				'name'           => sanitize_text_field( (string) ( $plugin['name'] ?? $slug ) ),
				'version'        => sanitize_text_field( (string) ( $plugin['version'] ?? '' ) ),
				'categories'     => is_array( $plugin['categories'] ?? null ) ? array_values( array_map( 'sanitize_key', $plugin['categories'] ) ) : array(),
				'finding_count'  => 0,
				'top_severity'   => 'info',
				'contexts'       => array(),
				'related_plugins'=> array(),
				'findings'       => array(),
			);
		}

		foreach ( $findings as $finding ) {
			$primary_slug   = sanitize_key( (string) ( $finding['primary_plugin'] ?? '' ) );
			$secondary_slug = sanitize_key( (string) ( $finding['secondary_plugin'] ?? '' ) );
			$related_name   = sanitize_text_field( (string) ( $finding['secondary_plugin_name'] ?? '' ) );
			$request_context = sanitize_text_field( (string) ( $finding['request_context'] ?? '' ) );
			$summary        = sanitize_text_field( (string) ( $finding['explanation'] ?? '' ) );
			$meta_bits      = array_filter(
				array(
					$request_context ? $this->format_labeled_value( __( 'Context', 'conflict-debugger' ), $request_context ) : '',
					! empty( $finding['shared_resource'] ) ? $this->format_labeled_value( __( 'Resource', 'conflict-debugger' ), sanitize_text_field( (string) $finding['shared_resource'] ) ) : '',
					! empty( $finding['execution_surface'] ) ? $this->format_labeled_value( __( 'Surface', 'conflict-debugger' ), sanitize_text_field( (string) $finding['execution_surface'] ) ) : '',
				)
			);

			foreach ( array( $primary_slug, $secondary_slug ) as $plugin_slug ) {
				if ( '' === $plugin_slug || ! isset( $drilldown[ $plugin_slug ] ) ) {
					continue;
				}

				$drilldown[ $plugin_slug ]['finding_count']++;
				$drilldown[ $plugin_slug ]['top_severity'] = $this->higher_severity(
					(string) $drilldown[ $plugin_slug ]['top_severity'],
					sanitize_key( (string) ( $finding['severity'] ?? 'info' ) )
				);

				if ( '' !== $request_context ) {
					$drilldown[ $plugin_slug ]['contexts'][ $request_context ] = $request_context;
				}

				$counterpart_name = $plugin_slug === $primary_slug
					? sanitize_text_field( (string) ( $finding['secondary_plugin_name'] ?? '' ) )
					: sanitize_text_field( (string) ( $finding['primary_plugin_name'] ?? '' ) );

				if ( '' !== $counterpart_name ) {
					$drilldown[ $plugin_slug ]['related_plugins'][ $counterpart_name ] = $counterpart_name;
				}

				$drilldown[ $plugin_slug ]['findings'][] = array(
					'severity' => sanitize_key( (string) ( $finding['severity'] ?? 'info' ) ),
					'title'    => sanitize_text_field( (string) ( $finding['title'] ?? '' ) ),
					'summary'  => $summary,
					'meta'     => implode( ' | ', $meta_bits ),
				);
			}
		}

		foreach ( $drilldown as $slug => $plugin_data ) {
			$drilldown[ $slug ]['contexts']        = array_values( $plugin_data['contexts'] );
			$drilldown[ $slug ]['related_plugins'] = array_values( $plugin_data['related_plugins'] );
		}

		uasort(
			$drilldown,
			function ( array $left, array $right ): int {
				$count_compare = (int) ( $right['finding_count'] ?? 0 ) <=> (int) ( $left['finding_count'] ?? 0 );
				if ( 0 !== $count_compare ) {
					return $count_compare;
				}

				return strcasecmp( (string) ( $left['name'] ?? '' ), (string) ( $right['name'] ?? '' ) );
			}
		);

		return $drilldown;
	}

	/**
	 * Builds a comparison between the current scan and the previous stored scan.
	 *
	 * @param array<string, mixed>              $results Latest results.
	 * @param array<int, array<string, mixed>> $history Scan history.
	 * @return array<string, mixed>
	 */
	private function build_scan_comparison( array $results, array $history ): array {
		$current_signatures = $this->findings_snapshot_from_results( $results );
		$previous_entry     = $history[1] ?? array();
		$previous_snapshot  = is_array( $previous_entry['findings_snapshot'] ?? null ) ? $previous_entry['findings_snapshot'] : array();

		if ( empty( $previous_entry ) ) {
			return array(
				'has_previous' => false,
			);
		}

		$current_map  = array();
		$previous_map = array();

		foreach ( $current_signatures as $item ) {
			$signature = sanitize_text_field( (string) ( $item['signature'] ?? '' ) );
			if ( '' !== $signature ) {
				$current_map[ $signature ] = $item;
			}
		}

		foreach ( $previous_snapshot as $item ) {
			$signature = sanitize_text_field( (string) ( $item['signature'] ?? '' ) );
			if ( '' !== $signature ) {
				$previous_map[ $signature ] = $item;
			}
		}

		$new_signatures      = array_diff_key( $current_map, $previous_map );
		$resolved_signatures = array_diff_key( $previous_map, $current_map );

		return array(
			'has_previous'      => true,
			'current_timestamp' => sanitize_text_field( (string) ( $results['scan_timestamp'] ?? '' ) ),
			'previous_timestamp'=> sanitize_text_field( (string) ( $previous_entry['scan_timestamp'] ?? '' ) ),
			'current_conflicts' => (int) ( $results['summary']['likely_conflicts'] ?? 0 ),
			'previous_conflicts'=> (int) ( $previous_entry['summary']['likely_conflicts'] ?? 0 ),
			'new_findings'      => array_values( array_map( array( $this, 'format_compare_finding' ), array_slice( $new_signatures, 0, 6 ) ) ),
			'resolved_findings' => array_values( array_map( array( $this, 'format_compare_finding' ), array_slice( $resolved_signatures, 0, 6 ) ) ),
		);
	}

	/**
	 * Builds a runtime event viewer model and finding links.
	 *
	 * @param array<string, mixed>              $results Latest scan results.
	 * @param array<int, array<string, mixed>> $findings Latest findings.
	 * @param array<string, mixed>              $active_session Active diagnostic session.
	 * @param array<string, mixed>              $last_session Last diagnostic session.
	 * @param array<string, mixed>              $active_validation Active validation mode.
	 * @param array<string, mixed>              $last_validation Last validation mode.
	 * @return array<string, mixed>
	 */
	private function build_runtime_event_view( array $results, array $findings, array $active_session = array(), array $last_session = array(), array $active_validation = array(), array $last_validation = array() ): array {
		$runtime_events      = is_array( $results['runtime_events'] ?? null ) ? $results['runtime_events'] : array();
		$focus_session       = ! empty( $active_session['id'] ) ? $active_session : $last_session;
		$focus_session_id    = sanitize_text_field( (string) ( $focus_session['id'] ?? '' ) );
		$focus_validation    = ! empty( $active_validation['id'] ) ? $active_validation : $last_validation;
		$focus_validation_id = sanitize_text_field( (string) ( $focus_validation['id'] ?? '' ) );
		$focused_events_only = array();
		$validation_events   = array();
		$finding_event_map  = array();
		$annotated_events   = array();
		$summary            = array(
			'total'           => 0,
			'js_errors'       => 0,
			'failed_requests' => 0,
			'mutations'       => 0,
			'validation_matches' => 0,
		);

		if ( '' !== $focus_session_id ) {
			foreach ( $runtime_events as $runtime_event ) {
				if ( $focus_session_id === sanitize_text_field( (string) ( $runtime_event['session_id'] ?? '' ) ) ) {
					$focused_events_only[] = $runtime_event;
				}
			}
		}

		if ( ! empty( $focused_events_only ) ) {
			$runtime_events = $focused_events_only;
		}

		if ( '' !== $focus_validation_id ) {
			foreach ( $runtime_events as $runtime_event ) {
				if (
					$focus_validation_id === sanitize_text_field( (string) ( $runtime_event['validation_mode_id'] ?? '' ) ) &&
					! empty( $runtime_event['validation_matched'] )
				) {
					$validation_events[] = $runtime_event;
				}
			}
		}

		if ( ! empty( $validation_events ) ) {
			$runtime_events = $validation_events;
		}

		foreach ( array_slice( $runtime_events, 0, 18 ) as $index => $runtime_event ) {
			$event_id          = 'pcd-runtime-event-' . ( $index + 1 );
			$matched_findings  = array();
			$owner_labels      = array();
			$actor_slug        = sanitize_key( (string) ( $runtime_event['actor_slug'] ?? '' ) );
			$target_owner_slug = sanitize_key( (string) ( $runtime_event['target_owner_slug'] ?? '' ) );
			$event_owner_slugs = is_array( $runtime_event['owner_slugs'] ?? null ) ? array_values( array_filter( array_map( 'sanitize_key', $runtime_event['owner_slugs'] ) ) ) : array();

			foreach ( $event_owner_slugs as $owner_slug ) {
				$owner_labels[] = $this->humanize_runtime_hint( $owner_slug );
			}

			if ( '' !== $actor_slug ) {
				$owner_labels[] = $this->humanize_runtime_hint( $actor_slug );
			}

			if ( '' !== $target_owner_slug ) {
				$owner_labels[] = $this->humanize_runtime_hint( $target_owner_slug );
			}

			foreach ( $findings as $finding ) {
				if ( ! $this->runtime_event_matches_finding( $runtime_event, $finding ) ) {
					continue;
				}

				$finding_signature = $this->finding_signature( $finding );
				$finding_title     = sanitize_text_field( (string) ( $finding['title'] ?? '' ) );
				$finding_label     = '' !== $finding_title
					? $finding_title
					: trim(
						implode(
							' / ',
							array_filter(
								array(
									sanitize_text_field( (string) ( $finding['primary_plugin_name'] ?? '' ) ),
									sanitize_text_field( (string) ( $finding['secondary_plugin_name'] ?? '' ) ),
								)
							)
						)
					);

				$finding_event_map[ $finding_signature ][] = array(
					'id'    => $event_id,
					'label' => $finding_label,
				);
				$matched_findings[ $finding_label ] = $finding_label;
			}

			$type = sanitize_key( (string) ( $runtime_event['type'] ?? 'runtime' ) );
			if ( in_array( $type, array( 'js_error', 'promise_rejection', 'js_promise', 'missing_asset', 'resource_error' ), true ) ) {
				$summary['js_errors']++;
			}

			if ( in_array( $type, array( 'http_response', 'network_failure' ), true ) || ! empty( $runtime_event['status_code'] ) ) {
				$summary['failed_requests']++;
			}

			if ( '' !== (string) ( $runtime_event['mutation_kind'] ?? '' ) || in_array( $type, array( 'callback_mutation', 'asset_mutation', 'asset_lifecycle' ), true ) ) {
				$summary['mutations']++;
			}

			if ( ! empty( $runtime_event['validation_matched'] ) ) {
				$summary['validation_matches']++;
			}

			$annotated_events[] = array(
				'id'               => $event_id,
				'timestamp'        => sanitize_text_field( (string) ( $runtime_event['timestamp'] ?? '' ) ),
				'type'             => $type,
				'type_label'       => $this->runtime_event_type_label( $runtime_event ),
				'level_badge'      => $this->runtime_event_level_badge( $runtime_event ),
				'title'            => $this->runtime_event_title( $runtime_event ),
				'message'          => sanitize_textarea_field( (string) ( $runtime_event['message'] ?? '' ) ),
				'request_context'  => sanitize_text_field( (string) ( $runtime_event['request_context'] ?? '' ) ),
				'request_uri'      => sanitize_text_field( (string) ( $runtime_event['request_uri'] ?? '' ) ),
				'request_scope'    => sanitize_text_field( (string) ( $runtime_event['request_scope'] ?? '' ) ),
				'scope_type'       => sanitize_key( (string) ( $runtime_event['scope_type'] ?? '' ) ),
				'resource'         => sanitize_text_field( (string) ( $runtime_event['resource'] ?? '' ) ),
				'resource_type'    => sanitize_key( (string) ( $runtime_event['resource_type'] ?? '' ) ),
				'resource_key'     => sanitize_text_field( (string) ( $runtime_event['resource_key'] ?? '' ) ),
				'execution_surface'=> sanitize_text_field( (string) ( $runtime_event['execution_surface'] ?? '' ) ),
				'hook'             => sanitize_text_field( (string) ( $runtime_event['hook'] ?? '' ) ),
				'failure_mode'     => str_replace( '_', ' ', sanitize_key( (string) ( $runtime_event['failure_mode'] ?? '' ) ) ),
				'evidence_source'  => $this->humanize_runtime_hint( sanitize_key( (string) ( $runtime_event['evidence_source'] ?? '' ) ) ),
				'attribution_label'=> $this->humanize_status_token( sanitize_key( (string) ( $runtime_event['attribution_status'] ?? '' ) ) ),
				'mutation_label'   => $this->humanize_status_token( sanitize_key( (string) ( $runtime_event['mutation_status'] ?? '' ) ) ),
				'actor_label'      => '' !== $actor_slug ? $this->humanize_runtime_hint( $actor_slug ) : '',
				'target_owner_label' => '' !== $target_owner_slug ? $this->humanize_runtime_hint( $target_owner_slug ) : '',
				'status_code'      => (int) ( $runtime_event['status_code'] ?? 0 ),
				'resource_hints'   => array_values(
					array_map(
						array( $this, 'humanize_runtime_hint' ),
						is_array( $runtime_event['resource_hints'] ?? null ) ? $runtime_event['resource_hints'] : array()
					)
				),
				'owner_labels'     => array_values( array_unique( array_filter( $owner_labels ) ) ),
				'validation_label' => sanitize_text_field( (string) ( $runtime_event['validation_label'] ?? '' ) ),
				'validation_matched' => ! empty( $runtime_event['validation_matched'] ),
				'state_changes'    => $this->summarize_state_delta(
					is_array( $runtime_event['previous_state'] ?? null ) ? $runtime_event['previous_state'] : array(),
					is_array( $runtime_event['new_state'] ?? null ) ? $runtime_event['new_state'] : array()
				),
				'matched_findings' => array_values( $matched_findings ),
			);
		}

		$summary['total'] = count( $annotated_events );

		return array(
			'summary'           => $summary,
			'events'            => $annotated_events,
			'finding_event_map' => $finding_event_map,
			'focus_label'       => ! empty( $focused_events_only ) ? sanitize_text_field( (string) ( $focus_session['label'] ?? '' ) ) : '',
			'validation_focus_label' => ! empty( $validation_events ) ? sanitize_text_field( (string) ( $focus_validation['label'] ?? '' ) ) : '',
			'validation_matches' => (int) ( $summary['validation_matches'] ?? 0 ),
		);
	}

	/**
	 * Determines whether a runtime event is relevant to a finding.
	 *
	 * @param array<string, mixed> $runtime_event Runtime event.
	 * @param array<string, mixed> $finding Finding.
	 * @return bool
	 */
	private function runtime_event_matches_finding( array $runtime_event, array $finding ): bool {
		$score            = 0;
		$request_context  = strtolower( sanitize_text_field( (string) ( $finding['request_context'] ?? '' ) ) );
		$event_context    = strtolower( sanitize_text_field( (string) ( $runtime_event['request_context'] ?? '' ) ) );
		$shared_resource  = strtolower( sanitize_text_field( (string) ( $finding['shared_resource'] ?? '' ) ) );
		$event_resource   = strtolower( sanitize_text_field( (string) ( $runtime_event['resource'] ?? '' ) ) );
		$event_surface    = strtolower( sanitize_text_field( (string) ( $runtime_event['execution_surface'] ?? '' ) ) );
		$finding_surface  = strtolower( sanitize_text_field( (string) ( $finding['execution_surface'] ?? '' ) ) );
		$owner_slugs      = is_array( $runtime_event['owner_slugs'] ?? null ) ? array_values( array_filter( array_map( 'sanitize_key', $runtime_event['owner_slugs'] ) ) ) : array();
		$actor_slug       = sanitize_key( (string) ( $runtime_event['actor_slug'] ?? '' ) );
		$target_owner_slug = sanitize_key( (string) ( $runtime_event['target_owner_slug'] ?? '' ) );
		$resource_hints   = is_array( $runtime_event['resource_hints'] ?? null ) ? array_values( array_filter( array_map( 'sanitize_text_field', $runtime_event['resource_hints'] ) ) ) : array();
		$plugin_tokens    = array_filter(
			array(
				sanitize_key( (string) ( $finding['primary_plugin'] ?? '' ) ),
				sanitize_key( (string) ( $finding['secondary_plugin'] ?? '' ) ),
				strtolower( sanitize_text_field( (string) ( $finding['primary_plugin_name'] ?? '' ) ) ),
				strtolower( sanitize_text_field( (string) ( $finding['secondary_plugin_name'] ?? '' ) ) ),
			)
		);
		$event_text       = strtolower(
			implode(
				' ',
				array(
					(string) ( $runtime_event['message'] ?? '' ),
					(string) ( $runtime_event['source'] ?? '' ),
					(string) ( $runtime_event['resource'] ?? '' ),
					implode( ' ', $resource_hints ),
					implode( ' ', $owner_slugs ),
				)
			)
		);

		if ( '' !== $request_context && '' !== $event_context && $request_context === $event_context ) {
			$score += 2;
		}

		if ( '' !== $finding_surface && '' !== $event_surface && $finding_surface === $event_surface ) {
			$score += 3;
		}

		if ( '' !== $shared_resource ) {
			if ( '' !== $event_resource && $this->contains_comparable_fragment( $event_resource, $shared_resource ) ) {
				$score += 4;
			}

			foreach ( $resource_hints as $resource_hint ) {
				if ( $this->contains_comparable_fragment( strtolower( $resource_hint ), $shared_resource ) ) {
					$score += 4;
					break;
				}
			}
		}

		if ( '' !== $actor_slug ) {
			$owner_slugs[] = $actor_slug;
		}

		if ( '' !== $target_owner_slug ) {
			$owner_slugs[] = $target_owner_slug;
		}

		$owner_slugs = array_values( array_unique( array_filter( $owner_slugs ) ) );

		foreach ( $plugin_tokens as $plugin_token ) {
			if ( '' === $plugin_token ) {
				continue;
			}

			if ( in_array( sanitize_key( $plugin_token ), $owner_slugs, true ) ) {
				$score += 5;
				continue;
			}

			if ( $this->contains_comparable_fragment( $event_text, strtolower( $plugin_token ) ) ) {
				$score += 2;
			}
		}

		if ( ! empty( $runtime_event['status_code'] ) && in_array( (string) ( $finding['finding_type'] ?? '' ), array( 'confirmed', 'interference' ), true ) ) {
			$score += 1;
		}

		return $score >= 5;
	}

	/**
	 * Returns a UI label for a runtime event type.
	 *
	 * @param array<string, mixed> $runtime_event Runtime event.
	 * @return string
	 */
	private function runtime_event_type_label( array $runtime_event ): string {
		$type = sanitize_key( (string) ( $runtime_event['type'] ?? 'runtime' ) );

		$labels = array(
			'js_error'          => __( 'JS Error', 'conflict-debugger' ),
			'promise_rejection' => __( 'Promise Rejection', 'conflict-debugger' ),
			'js_promise'        => __( 'Promise Rejection', 'conflict-debugger' ),
			'missing_asset'     => __( 'Missing Asset', 'conflict-debugger' ),
			'resource_error'    => __( 'Resource Error', 'conflict-debugger' ),
			'http_response'     => __( 'Failed Request', 'conflict-debugger' ),
			'network_failure'   => __( 'Failed Request', 'conflict-debugger' ),
			'php_runtime'       => __( 'PHP Runtime', 'conflict-debugger' ),
			'callback_mutation' => __( 'Callback Mutation', 'conflict-debugger' ),
			'asset_mutation'    => __( 'Asset Mutation', 'conflict-debugger' ),
			'asset_lifecycle'   => __( 'Asset Lifecycle', 'conflict-debugger' ),
		);

		return $labels[ $type ] ?? __( 'Runtime Event', 'conflict-debugger' );
	}

	/**
	 * Returns a concise title for a runtime event.
	 *
	 * @param array<string, mixed> $runtime_event Runtime event.
	 * @return string
	 */
	private function runtime_event_title( array $runtime_event ): string {
		$resource          = sanitize_text_field( (string) ( $runtime_event['resource'] ?? '' ) );
		$execution_surface = sanitize_text_field( (string) ( $runtime_event['execution_surface'] ?? '' ) );
		$request_context   = sanitize_text_field( (string) ( $runtime_event['request_context'] ?? '' ) );
		$type_label        = $this->runtime_event_type_label( $runtime_event );

		if ( '' !== $resource ) {
			return sprintf(
				/* translators: 1: runtime event type label, 2: runtime resource. */
				__( '%1$s on %2$s', 'conflict-debugger' ),
				$type_label,
				$resource
			);
		}

		if ( '' !== $execution_surface ) {
			return sprintf(
				/* translators: 1: runtime event type label, 2: execution surface. */
				__( '%1$s around %2$s', 'conflict-debugger' ),
				$type_label,
				$execution_surface
			);
		}

		if ( '' !== $request_context ) {
			return sprintf(
				/* translators: 1: runtime event type label, 2: request context. */
				__( '%1$s in %2$s', 'conflict-debugger' ),
				$type_label,
				$request_context
			);
		}

		return $type_label;
	}

	/**
	 * Maps a runtime event to a badge severity.
	 *
	 * @param array<string, mixed> $runtime_event Runtime event.
	 * @return string
	 */
	private function runtime_event_level_badge( array $runtime_event ): string {
		$type       = sanitize_key( (string) ( $runtime_event['type'] ?? 'runtime' ) );
		$status_code = (int) ( $runtime_event['status_code'] ?? 0 );

		if ( in_array( $type, array( 'php_runtime', 'js_error', 'missing_asset', 'resource_error' ), true ) || $status_code >= 500 ) {
			return 'critical';
		}

		if ( in_array( $type, array( 'callback_mutation', 'asset_mutation', 'asset_lifecycle', 'promise_rejection', 'js_promise', 'network_failure' ), true ) || $status_code >= 400 ) {
			return 'warning';
		}

		return 'info';
	}

	/**
	 * Humanizes a runtime hint for the UI.
	 *
	 * @param string $hint Raw hint.
	 * @return string
	 */
	private function humanize_runtime_hint( string $hint ): string {
		$hint = sanitize_text_field( $hint );
		if ( '' === $hint ) {
			return '';
		}

		if ( str_contains( $hint, ':' ) ) {
			list( $prefix, $value ) = array_pad( explode( ':', $hint, 2 ), 2, '' );
			$prefix = ucwords( str_replace( array( '-', '_' ), ' ', sanitize_key( $prefix ) ) );
			$value  = str_replace( array( '-', '_' ), ' ', sanitize_text_field( $value ) );

			return trim( $prefix . ': ' . $value );
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $hint ) );
	}

	/**
	 * Humanizes a status token for runtime metadata.
	 *
	 * @param string $token Raw status token.
	 * @return string
	 */
	private function humanize_status_token( string $token ): string {
		$token = sanitize_key( $token );
		if ( '' === $token ) {
			return '';
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $token ) );
	}

	/**
	 * Humanizes an evidence tier for the finding detail UI.
	 *
	 * @param string $tier Evidence tier.
	 * @return string
	 */
	private function humanize_evidence_tier( string $tier ): string {
		$tier = sanitize_key( $tier );
		if ( '' === $tier ) {
			return __( 'Supporting', 'conflict-debugger' );
		}

		$labels = array(
			'noise'            => __( 'Noise', 'conflict-debugger' ),
			'weak'             => __( 'Weak overlap', 'conflict-debugger' ),
			'supporting'       => __( 'Supporting', 'conflict-debugger' ),
			'strong_proof'     => __( 'Strong proof', 'conflict-debugger' ),
			'runtime_breakage' => __( 'Runtime breakage', 'conflict-debugger' ),
		);

		return $labels[ $tier ] ?? ucwords( str_replace( '_', ' ', $tier ) );
	}

	/**
	 * Summarizes before/after state deltas for trace events.
	 *
	 * @param array<string, mixed> $previous_state Previous state.
	 * @param array<string, mixed> $new_state New state.
	 * @return string[]
	 */
	private function summarize_state_delta( array $previous_state, array $new_state ): array {
		$keys     = array_unique( array_merge( array_keys( $previous_state ), array_keys( $new_state ) ) );
		$changes  = array();

		foreach ( $keys as $key ) {
			$left  = $previous_state[ $key ] ?? '';
			$right = $new_state[ $key ] ?? '';

			if ( wp_json_encode( $left ) === wp_json_encode( $right ) ) {
				continue;
			}

			$changes[] = sprintf(
				/* translators: 1: field, 2: previous value, 3: new value. */
				__( '%1$s changed from %2$s to %3$s', 'conflict-debugger' ),
				$this->humanize_runtime_hint( (string) $key ),
				$this->stringify_state_value( $left ),
				$this->stringify_state_value( $right )
			);
		}

		return array_slice( $changes, 0, 4 );
	}

	/**
	 * Formats a state value for UI display.
	 *
	 * @param mixed $value State value.
	 * @return string
	 */
	private function stringify_state_value( mixed $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_map( 'strval', $value ) );
		}

		$string_value = trim( sanitize_text_field( (string) $value ) );

		return '' === $string_value ? __( '(empty)', 'conflict-debugger' ) : $string_value;
	}

	/**
	 * Checks whether two strings share a meaningful comparable fragment.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle Needle.
	 * @return bool
	 */
	private function contains_comparable_fragment( string $haystack, string $needle ): bool {
		$haystack = strtolower( trim( $haystack ) );
		$needle   = strtolower( trim( $needle ) );

		if ( '' === $haystack || '' === $needle ) {
			return false;
		}

		if ( str_contains( $haystack, $needle ) || str_contains( $needle, $haystack ) ) {
			return true;
		}

		$needle_parts = preg_split( '/[\s:_\-\/\\\\]+/', $needle );
		if ( ! is_array( $needle_parts ) ) {
			return false;
		}

		foreach ( $needle_parts as $needle_part ) {
			$needle_part = trim( (string) $needle_part );
			if ( strlen( $needle_part ) < 4 ) {
				continue;
			}

			if ( str_contains( $haystack, $needle_part ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds a compact finding snapshot from full latest results.
	 *
	 * @param array<string, mixed> $results Latest results.
	 * @return array<int, array<string, mixed>>
	 */
	private function findings_snapshot_from_results( array $results ): array {
		$findings  = is_array( $results['findings'] ?? null ) ? $results['findings'] : array();
		$snapshot = array();

		foreach ( array_slice( $findings, 0, 25 ) as $finding ) {
			$snapshot[] = array(
				'signature'             => $this->finding_signature( $finding ),
				'title'                 => sanitize_text_field( (string) ( $finding['title'] ?? '' ) ),
				'severity'              => sanitize_key( (string) ( $finding['severity'] ?? 'info' ) ),
				'primary_plugin_name'   => sanitize_text_field( (string) ( $finding['primary_plugin_name'] ?? '' ) ),
				'secondary_plugin_name' => sanitize_text_field( (string) ( $finding['secondary_plugin_name'] ?? '' ) ),
				'request_context'       => sanitize_text_field( (string) ( $finding['request_context'] ?? '' ) ),
			);
		}

		return $snapshot;
	}

	/**
	 * Formats a comparison finding label.
	 *
	 * @param array<string, mixed> $finding Finding snapshot.
	 * @return string
	 */
	private function format_compare_finding( array $finding ): string {
		$plugins = array_filter(
			array(
				sanitize_text_field( (string) ( $finding['primary_plugin_name'] ?? '' ) ),
				sanitize_text_field( (string) ( $finding['secondary_plugin_name'] ?? '' ) ),
			)
		);

		$context = sanitize_text_field( (string) ( $finding['request_context'] ?? '' ) );
		$title   = sanitize_text_field( (string) ( $finding['title'] ?? '' ) );

		return trim(
			implode(
				' - ',
				array_filter(
					array(
						'' !== $title ? $title : implode( ' / ', $plugins ),
						! empty( $plugins ) ? implode( ' / ', $plugins ) : '',
						'' !== $context ? $this->format_labeled_value( __( 'Context', 'conflict-debugger' ), $context ) : '',
					)
				)
			)
		);
	}

	/**
	 * Formats a trace metric delta for UI display.
	 *
	 * @param int $delta Delta value.
	 * @return string
	 */
	private function format_trace_delta( int $delta ): string {
		if ( 0 === $delta ) {
			return '0';
		}

		return $delta > 0 ? '+' . (string) $delta : (string) $delta;
	}

	/**
	 * Renders trace-only difference lists.
	 *
	 * @param array<string, mixed> $diff Diff payload.
	 * @return void
	 */
	private function render_trace_diff_lists( array $diff ): void {
		$labels = array(
			'event_types'        => __( 'Event types', 'conflict-debugger' ),
			'execution_surfaces' => __( 'Execution surfaces', 'conflict-debugger' ),
			'owners'             => __( 'Observed owners', 'conflict-debugger' ),
			'resource_hints'     => __( 'Resource hints', 'conflict-debugger' ),
		);
		$has_items = false;

		foreach ( $labels as $key => $label ) {
			$items = is_array( $diff[ $key ] ?? null ) ? array_values( array_filter( array_map( 'strval', $diff[ $key ] ) ) ) : array();
			if ( empty( $items ) ) {
				continue;
			}

			$has_items = true;
			?>
			<div class="pcd-trace-diff-group">
				<strong><?php echo esc_html( $label ); ?></strong>
				<ul class="pcd-meta-list">
					<?php foreach ( $items as $item ) : ?>
						<li><?php echo esc_html( $this->humanize_runtime_hint( (string) $item ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}

		if ( ! $has_items ) {
			echo '<p>' . esc_html__( 'No unique trace-only signals were captured for this side of the comparison.', 'conflict-debugger' ) . '</p>';
		}
	}

	/**
	 * Returns the higher of two severity labels.
	 *
	 * @param string $left Left severity.
	 * @param string $right Right severity.
	 * @return string
	 */
	private function higher_severity( string $left, string $right ): string {
		$ranks = array(
			'info'     => 0,
			'low'      => 1,
			'medium'   => 2,
			'high'     => 3,
			'critical' => 4,
		);

		return ( $ranks[ $right ] ?? 0 ) > ( $ranks[ $left ] ?? 0 ) ? $right : $left;
	}

	/**
	 * Builds a stable finding signature for local comparisons.
	 *
	 * @param array<string, mixed> $finding Finding.
	 * @return string
	 */
	private function finding_signature( array $finding ): string {
		return md5(
			wp_json_encode(
				array(
					'primary_plugin'    => sanitize_key( (string) ( $finding['primary_plugin'] ?? '' ) ),
					'secondary_plugin'  => sanitize_key( (string) ( $finding['secondary_plugin'] ?? '' ) ),
					'surface_key'       => sanitize_key( (string) ( $finding['surface_key'] ?? $finding['issue_category'] ?? '' ) ),
					'finding_type'      => sanitize_key( (string) ( $finding['finding_type'] ?? '' ) ),
					'request_context'   => sanitize_text_field( (string) ( $finding['request_context'] ?? '' ) ),
					'shared_resource'   => sanitize_text_field( (string) ( $finding['shared_resource'] ?? '' ) ),
					'execution_surface' => sanitize_text_field( (string) ( $finding['execution_surface'] ?? '' ) ),
				)
			)
		);
	}
}
