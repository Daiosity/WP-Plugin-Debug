<?php
/**
 * Captures request contexts and lightweight observed breakage telemetry.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RuntimeTelemetry {
	/**
	 * Telemetry repository.
	 *
	 * @var RuntimeTelemetryRepository
	 */
	private RuntimeTelemetryRepository $repository;

	/**
	 * Registry snapshot service.
	 *
	 * @var RegistrySnapshot
	 */
	private RegistrySnapshot $registry;

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
	 * @param RuntimeTelemetryRepository $repository Telemetry repository.
	 * @param RegistrySnapshot           $registry Registry snapshot service.
	 */
	public function __construct( RuntimeTelemetryRepository $repository, RegistrySnapshot $registry, DiagnosticSessionRepository $sessions, ValidationModeRepository $validation ) {
		$this->repository = $repository;
		$this->registry   = $registry;
		$this->sessions   = $sessions;
		$this->validation = $validation;
	}

	/**
	 * Registers runtime hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_script' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_script' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_script' ) );
		add_action( 'wp_ajax_pcd_report_runtime_event', array( $this, 'handle_runtime_event' ) );
		add_action( 'wp_ajax_nopriv_pcd_report_runtime_event', array( $this, 'handle_runtime_event' ) );
		add_action( 'shutdown', array( $this, 'capture_request_state' ), 999 );
	}

	/**
	 * Enqueues telemetry on frontend requests.
	 *
	 * @return void
	 */
	public function enqueue_frontend_script(): void {
		if ( is_admin() || wp_doing_ajax() || $this->is_rest_request() ) {
			return;
		}

		$this->enqueue_runtime_script( 'frontend' );
	}

	/**
	 * Enqueues telemetry on admin requests.
	 *
	 * @return void
	 */
	public function enqueue_admin_script(): void {
		if ( wp_doing_ajax() || $this->is_rest_request() ) {
			return;
		}

		$this->enqueue_runtime_script( 'admin' );
	}

	/**
	 * Enqueues telemetry on the login screen.
	 *
	 * @return void
	 */
	public function enqueue_login_script(): void {
		$this->enqueue_runtime_script( 'login' );
	}

	/**
	 * Handles client-side runtime event reports.
	 *
	 * @return void
	 */
	public function handle_runtime_event(): void {
		check_ajax_referer( 'pcd_runtime_event', 'nonce' );

		$posted_data    = wp_unslash( $_POST );
		$posted_context = sanitize_text_field( (string) ( $posted_data['request_context'] ?? '' ) );
		$server_context = $this->build_request_context();
		$request_scope  = sanitize_text_field( (string) ( $posted_data['request_scope'] ?? $server_context['request_scope'] ?? '' ) );
		$scope_type     = sanitize_key( (string) ( $posted_data['scope_type'] ?? $server_context['scope_type'] ?? '' ) );
		$request_id     = sanitize_text_field( (string) ( $posted_data['request_id'] ?? '' ) );
		if ( '' === $request_id ) {
			$request_id = sanitize_text_field( (string) ( $server_context['request_id'] ?? TraceEvent::current_request_id() ) );
		}

		$event = array(
			'event_id'             => TraceEvent::new_event_id(),
			'request_id'           => $request_id,
			'sequence'             => 0,
			'timestamp'            => current_time( 'mysql' ),
			'type'                 => sanitize_key( (string) ( $posted_data['type'] ?? 'client' ) ),
			'evidence_source'      => TraceEvent::SOURCE_CLIENT,
			'level'                => sanitize_key( (string) ( $posted_data['level'] ?? 'error' ) ),
			'message'              => sanitize_textarea_field( (string) ( $posted_data['message'] ?? '' ) ),
			'request_context'      => '' !== $posted_context ? $posted_context : sanitize_text_field( (string) ( $server_context['request_context'] ?? '' ) ),
			'request_uri'          => sanitize_text_field( (string) ( $posted_data['request_uri'] ?? $server_context['request_uri'] ?? '' ) ),
			'request_scope'        => $request_scope,
			'scope_type'           => $scope_type,
			'source'               => sanitize_text_field( (string) ( $posted_data['source'] ?? '' ) ),
			'resource'             => sanitize_text_field( (string) ( $posted_data['resource'] ?? '' ) ),
			'resource_type'        => sanitize_key( (string) ( $posted_data['resource_type'] ?? '' ) ),
			'resource_key'         => sanitize_text_field( (string) ( $posted_data['resource_key'] ?? '' ) ),
			'execution_surface'    => sanitize_text_field( (string) ( $posted_data['execution_surface'] ?? '' ) ),
			'hook'                 => sanitize_text_field( (string) ( $posted_data['hook'] ?? '' ) ),
			'callback'             => sanitize_text_field( (string) ( $posted_data['callback'] ?? '' ) ),
			'priority'             => absint( $posted_data['priority'] ?? 0 ),
			'status_code'          => absint( $posted_data['status_code'] ?? 0 ),
			'session_id'           => sanitize_text_field( (string) ( $posted_data['session_id'] ?? '' ) ),
			'resource_hints'       => $this->normalize_resource_hints( $posted_data['resource_hints'] ?? '' ),
			'attribution_status'   => sanitize_key( (string) ( $posted_data['attribution_status'] ?? TraceEvent::ATTRIBUTION_UNKNOWN ) ),
			'contamination_status' => sanitize_key( (string) ( $posted_data['contamination_status'] ?? TraceEvent::CONTAMINATION_NONE ) ),
			'mutation_status'      => sanitize_key( (string) ( $posted_data['mutation_status'] ?? TraceEvent::MUTATION_NONE ) ),
		);

		$active_session = $this->resolve_active_session_for_context( (string) $event['request_context'] );
		if ( empty( $event['session_id'] ) && ! empty( $active_session['id'] ) ) {
			$event['session_id'] = (string) $active_session['id'];
		}

		$event = $this->validation->decorate_event( $event );

		if ( '' !== $event['message'] ) {
			$this->repository->record_event( $event );

			if ( ! empty( $event['session_id'] ) ) {
				$this->sessions->touch_activity( (string) $event['session_id'] );
			}
		}

		wp_send_json_success();
	}

	/**
	 * Captures the current request context and any direct server-side failures.
	 *
	 * @return void
	 */
	public function capture_request_state(): void {
		if ( headers_sent() && wp_doing_cron() ) {
			return;
		}

		$context = $this->validation->decorate_context( $this->build_request_context() );
		$this->repository->record_request_context( $context );

		if ( ! empty( $context['session_id'] ) ) {
			$this->sessions->touch_activity( (string) $context['session_id'] );
		}

		$last_error = error_get_last();
		if ( is_array( $last_error ) && $this->is_fatal_error( (int) ( $last_error['type'] ?? 0 ) ) ) {
			$this->repository->record_event(
				$this->validation->decorate_event(
					array(
					'event_id'             => TraceEvent::new_event_id(),
					'request_id'           => (string) ( $context['request_id'] ?? TraceEvent::current_request_id() ),
					'sequence'             => TraceEvent::next_sequence(),
					'timestamp'            => current_time( 'mysql' ),
					'type'                 => 'php_runtime',
					'evidence_source'      => TraceEvent::SOURCE_RUNTIME,
					'level'                => 'fatal',
					'message'              => (string) ( $last_error['message'] ?? '' ),
					'request_context'      => (string) ( $context['request_context'] ?? '' ),
					'request_uri'          => (string) ( $context['request_uri'] ?? '' ),
					'request_scope'        => (string) ( $context['request_scope'] ?? '' ),
					'scope_type'           => (string) ( $context['scope_type'] ?? '' ),
					'source'               => sanitize_text_field( (string) ( $last_error['file'] ?? '' ) ),
					'resource'             => sanitize_text_field( basename( (string) ( $last_error['file'] ?? '' ) ) ),
					'resource_type'        => 'php_file',
					'resource_key'         => sanitize_text_field( basename( (string) ( $last_error['file'] ?? '' ) ) ),
					'status_code'          => 500,
					'session_id'           => (string) ( $context['session_id'] ?? '' ),
					'resource_hints'       => is_array( $context['resource_hints'] ?? null ) ? $context['resource_hints'] : array(),
					)
				)
			);
		}

		$status_code = http_response_code();
		if ( is_int( $status_code ) && $status_code >= 400 ) {
			$this->repository->record_event(
				$this->validation->decorate_event(
					array(
					'event_id'             => TraceEvent::new_event_id(),
					'request_id'           => (string) ( $context['request_id'] ?? TraceEvent::current_request_id() ),
					'sequence'             => TraceEvent::next_sequence(),
					'timestamp'            => current_time( 'mysql' ),
					'type'                 => 'http_response',
					'evidence_source'      => TraceEvent::SOURCE_RUNTIME,
					'level'                => $status_code >= 500 ? 'server-error' : 'client-error',
					'message'              => sprintf(
						/* translators: %d status code. */
						__( 'Observed HTTP response %d during request execution.', 'conflict-debugger' ),
						$status_code
					),
					'request_context'      => (string) ( $context['request_context'] ?? '' ),
					'request_uri'          => (string) ( $context['request_uri'] ?? '' ),
					'request_scope'        => (string) ( $context['request_scope'] ?? '' ),
					'scope_type'           => (string) ( $context['scope_type'] ?? '' ),
					'resource'             => (string) ( $context['resource'] ?? '' ),
					'resource_key'         => (string) ( $context['resource'] ?? '' ),
					'status_code'          => $status_code,
					'session_id'           => (string) ( $context['session_id'] ?? '' ),
					'resource_hints'       => is_array( $context['resource_hints'] ?? null ) ? $context['resource_hints'] : array(),
					)
				)
			);
		}
	}

	/**
	 * Enqueues the runtime telemetry script.
	 *
	 * @param string $default_context Default request context.
	 * @return void
	 */
	private function enqueue_runtime_script( string $default_context ): void {
		$request_scope = $this->current_request_scope();

		wp_enqueue_script(
			'pcd-runtime-telemetry',
			PCD_URL . 'assets/js/runtime-telemetry.js',
			array(),
			PCD_VERSION,
			true
		);

		wp_localize_script(
			'pcd-runtime-telemetry',
			'pcdRuntime',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'pcd_runtime_event' ),
				'requestId'      => TraceEvent::current_request_id(),
				'requestContext' => $default_context,
				'requestUri'     => $this->current_request_uri(),
				'requestScope'   => $request_scope['scope'],
				'scopeType'      => $request_scope['type'],
				'resourceHints'  => $this->detect_resource_hints(),
				'activeSession'  => $this->session_payload_for_client( $default_context ),
				'activeValidation' => $this->validation_payload_for_client(),
				'restPrefix'     => trailingslashit( rest_get_url_prefix() ),
				'ajaxMarkers'    => array( 'admin-ajax.php' ),
			)
		);
	}

	/**
	 * Builds a normalized request context snapshot.
	 *
	 * @return array<string, mixed>
	 */
	private function build_request_context(): array {
		$request_scope = $this->current_request_scope();

		$context = array(
			'request_id'      => TraceEvent::current_request_id(),
			'timestamp'       => current_time( 'mysql' ),
			'request_context' => $this->detect_request_context(),
			'request_uri'     => $this->current_request_uri(),
			'request_scope'   => $request_scope['scope'],
			'scope_type'      => $request_scope['type'],
			'screen_id'       => '',
			'ajax_action'     => '',
			'rest_route'      => '',
			'resource'        => '',
			'resource_hints'  => $this->detect_resource_hints(),
			'session_id'      => '',
		);

		$active_session = $this->resolve_active_session_for_context( (string) $context['request_context'] );
		if ( ! empty( $active_session['id'] ) ) {
			$context['session_id'] = (string) $active_session['id'];
		}

		if ( wp_doing_ajax() ) {
			$context['ajax_action'] = $this->current_ajax_action();
			$context['resource']    = (string) $context['ajax_action'];
		}

		if ( $this->is_rest_request() ) {
			$context['rest_route'] = $this->detect_rest_route();
			$context['resource']   = (string) $context['rest_route'];
		}

		if ( is_admin() && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen ) {
				$context['screen_id'] = sanitize_key( (string) $screen->id );
				$context['resource']  = (string) $context['screen_id'];
			}
		}

		return $context;
	}

	/**
	 * Returns the most specific request scope available.
	 *
	 * @return array{scope:string,type:string}
	 */
	private function current_request_scope(): array {
		if ( wp_doing_ajax() ) {
			$action = $this->current_ajax_action();
			if ( '' !== $action ) {
				return array(
					'scope' => 'ajax:' . $action,
					'type'  => 'ajax',
				);
			}
		}

		if ( $this->is_rest_request() ) {
			$route = $this->detect_rest_route();
			if ( '' !== $route ) {
				return array(
					'scope' => 'rest:' . $route,
					'type'  => 'rest',
				);
			}
		}

		if ( is_admin() && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && ! empty( $screen->id ) ) {
				return array(
					'scope' => 'screen:' . sanitize_key( (string) $screen->id ),
					'type'  => 'screen',
				);
			}
		}

		return array(
			'scope' => $this->comparable_path( $this->current_request_uri() ),
			'type'  => 'path',
		);
	}

	/**
	 * Detects the current request context.
	 *
	 * @return string
	 */
	private function detect_request_context(): string {
		global $pagenow;

		if ( wp_doing_cron() ) {
			return 'cron';
		}

		if ( wp_doing_ajax() ) {
			return 'ajax';
		}

		if ( $this->is_rest_request() ) {
			return 'REST';
		}

		if ( 'wp-login.php' === $pagenow ) {
			return 'login';
		}

		if ( is_admin() ) {
			if ( function_exists( 'get_current_screen' ) ) {
				$screen = get_current_screen();
				if ( $screen ) {
					if ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
						return 'block editor';
					}

					if ( false !== strpos( (string) $screen->id, 'elementor' ) ) {
						return 'Elementor editor';
					}
				}
			}

			return 'admin';
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return 'checkout';
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return 'cart';
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}

		return 'frontend';
	}

	/**
	 * Returns the current request URI.
	 *
	 * @return string
	 */
	private function current_request_uri(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}

		return sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) );
	}

	/**
	 * Returns the current AJAX action name.
	 *
	 * @return string
	 */
	private function current_ajax_action(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request classification for runtime context capture.
		if ( ! isset( $_REQUEST['action'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request classification for runtime context capture.
		return sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) );
	}

	/**
	 * Detects the current REST route.
	 *
	 * @return string
	 */
	private function detect_rest_route(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route detection for runtime context capture.
		if ( isset( $_REQUEST['rest_route'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route detection for runtime context capture.
			return sanitize_text_field( wp_unslash( (string) $_REQUEST['rest_route'] ) );
		}

		$request_uri = $this->current_request_uri();
		$prefix      = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
		$position    = strpos( $request_uri, $prefix );

		if ( false === $position ) {
			return '';
		}

		return sanitize_text_field( substr( $request_uri, $position + strlen( $prefix ) - 1 ) );
	}

	/**
	 * Returns a normalized comparable path.
	 *
	 * @param string $request_uri Request URI.
	 * @return string
	 */
	private function comparable_path( string $request_uri ): string {
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			$path = $request_uri;
		}

		$path = '/' . ltrim( trim( $path ), '/' );
		return '/' === $path ? $path : untrailingslashit( $path );
	}

	/**
	 * Checks whether the current request is REST.
	 *
	 * @return bool
	 */
	private function is_rest_request(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Checks whether the error type is fatal.
	 *
	 * @param int $type PHP error type.
	 * @return bool
	 */
	private function is_fatal_error( int $type ): bool {
		return in_array( $type, array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true );
	}

	/**
	 * Detects exact resources active on the current request.
	 *
	 * @return string[]
	 */
	private function detect_resource_hints(): array {
		$hints = array();

		if ( wp_doing_ajax() ) {
			$action = $this->current_ajax_action();
			if ( '' !== $action ) {
				$hints[] = 'ajax:' . $action;
			}
		}

		if ( $this->is_rest_request() ) {
			$route = $this->detect_rest_route();
			if ( '' !== $route ) {
				$hints[] = 'rest:' . $route;
			}
		}

		if ( is_admin() && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen ) {
				$hints[] = 'screen:' . sanitize_key( (string) $screen->id );
			}
		}

		$post = get_post();
		if ( $post && is_string( $post->post_content ) && '' !== $post->post_content ) {
			foreach ( $this->extract_shortcode_hints( $post->post_content ) as $hint ) {
				$hints[] = $hint;
			}

			foreach ( $this->extract_block_hints( $post->post_content ) as $hint ) {
				$hints[] = $hint;
			}
		}

		$scripts = wp_scripts();
		if ( $scripts && ! empty( $scripts->queue ) ) {
			foreach ( (array) $scripts->queue as $handle ) {
				$hints[] = 'asset:' . sanitize_key( (string) $handle );
			}
		}

		$styles = wp_styles();
		if ( $styles && ! empty( $styles->queue ) ) {
			foreach ( (array) $styles->queue as $handle ) {
				$hints[] = 'asset:' . sanitize_key( (string) $handle );
			}
		}

		return array_values( array_unique( array_filter( $hints ) ) );
	}

	/**
	 * Extracts shortcode hints from current content.
	 *
	 * @param string $content Post content.
	 * @return string[]
	 */
	private function extract_shortcode_hints( string $content ): array {
		$tags       = array_keys( $this->registry->get_shortcode_snapshot() );
		$shortcodes = array();

		if ( empty( $tags ) || ! function_exists( 'get_shortcode_regex' ) ) {
			return array();
		}

		if ( preg_match_all( '/' . get_shortcode_regex( $tags ) . '/', $content, $matches ) ) {
			foreach ( (array) ( $matches[2] ?? array() ) as $tag ) {
				$tag = sanitize_key( (string) $tag );
				if ( '' !== $tag ) {
					$shortcodes[] = 'shortcode:' . $tag;
				}
			}
		}

		return array_values( array_unique( $shortcodes ) );
	}

	/**
	 * Extracts block hints from current content.
	 *
	 * @param string $content Post content.
	 * @return string[]
	 */
	private function extract_block_hints( string $content ): array {
		if ( ! function_exists( 'parse_blocks' ) || false === strpos( $content, '<!-- wp:' ) ) {
			return array();
		}

		$names = array();
		$this->collect_block_names( parse_blocks( $content ), $names );

		return array_values(
			array_unique(
				array_map(
					static fn( string $name ): string => 'block:' . sanitize_text_field( $name ),
					array_filter( $names )
				)
			)
		);
	}

	/**
	 * Collects block names recursively.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param string[]                         $names Names.
	 * @return void
	 */
	private function collect_block_names( array $blocks, array &$names ): void {
		foreach ( $blocks as $block ) {
			$block_name = sanitize_text_field( (string) ( $block['blockName'] ?? '' ) );
			if ( '' !== $block_name ) {
				$names[] = $block_name;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->collect_block_names( $block['innerBlocks'], $names );
			}
		}
	}

	/**
	 * Normalizes resource hints from request input.
	 *
	 * @param mixed $resource_hints Resource hints.
	 * @return string[]
	 */
	private function normalize_resource_hints( mixed $resource_hints ): array {
		if ( is_string( $resource_hints ) && '' !== $resource_hints ) {
			$decoded = json_decode( $resource_hints, true );
			if ( is_array( $decoded ) ) {
				$resource_hints = $decoded;
			} else {
				$resource_hints = explode( ',', $resource_hints );
			}
		}

		if ( ! is_array( $resource_hints ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $hint ): string => sanitize_text_field( (string) $hint ),
						$resource_hints
					)
				)
			)
		);
	}

	/**
	 * Returns active session data if the current context matches.
	 *
	 * @param string $request_context Request context.
	 * @return array<string, mixed>
	 */
	private function resolve_active_session_for_context( string $request_context ): array {
		$session = $this->sessions->get_active();
		if ( empty( $session['id'] ) ) {
			return array();
		}

		$target_context = sanitize_key( (string) ( $session['target_context'] ?? 'all' ) );
		$current        = strtolower( trim( $request_context ) );

		if ( 'all' === $target_context ) {
			return $session;
		}

		$map = array(
			'rest'     => array( 'rest' ),
			'ajax'     => array( 'ajax', 'rest/ajax' ),
			'admin'    => array( 'admin' ),
			'frontend' => array( 'frontend', 'product', 'cart' ),
			'editor'   => array( 'editor', 'block editor', 'elementor editor' ),
			'login'    => array( 'login' ),
			'checkout' => array( 'checkout', 'cart', 'product' ),
			'cron'     => array( 'cron' ),
		);

		$allowed = $map[ $target_context ] ?? array( $target_context );
		foreach ( $allowed as $allowed_context ) {
			if ( $allowed_context === $current ) {
				return $session;
			}
		}

		return array();
	}

	/**
	 * Returns session payload for localized runtime script config.
	 *
	 * @param string $request_context Current request context.
	 * @return array<string, string>
	 */
	private function session_payload_for_client( string $request_context ): array {
		$session = $this->resolve_active_session_for_context( $request_context );
		if ( empty( $session['id'] ) ) {
			return array();
		}

		return array(
			'id'             => sanitize_text_field( (string) $session['id'] ),
			'target_context' => sanitize_key( (string) ( $session['target_context'] ?? 'all' ) ),
			'label'          => sanitize_text_field( (string) ( $session['label'] ?? '' ) ),
		);
	}

	/**
	 * Returns the active validation mode for client-side telemetry.
	 *
	 * @return array<string, mixed>
	 */
	private function validation_payload_for_client(): array {
		$mode = $this->validation->get_active();
		if ( empty( $mode['id'] ) ) {
			return array();
		}

		return array(
			'id'            => sanitize_text_field( (string) $mode['id'] ),
			'targetType'    => sanitize_key( (string) ( $mode['target_type'] ?? '' ) ),
			'targetValue'   => sanitize_text_field( (string) ( $mode['target_value'] ?? '' ) ),
			'pluginA'       => sanitize_key( (string) ( $mode['plugin_a'] ?? '' ) ),
			'pluginB'       => sanitize_key( (string) ( $mode['plugin_b'] ?? '' ) ),
			'label'         => sanitize_text_field( (string) ( $mode['label'] ?? '' ) ),
		);
	}
}
