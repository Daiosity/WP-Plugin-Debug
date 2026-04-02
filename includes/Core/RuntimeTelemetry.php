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
	 * Constructor.
	 *
	 * @param RuntimeTelemetryRepository $repository Telemetry repository.
	 * @param RegistrySnapshot           $registry Registry snapshot service.
	 */
	public function __construct( RuntimeTelemetryRepository $repository, RegistrySnapshot $registry, DiagnosticSessionRepository $sessions ) {
		$this->repository = $repository;
		$this->registry   = $registry;
		$this->sessions   = $sessions;
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

		$event = array(
			'timestamp'       => current_time( 'mysql' ),
			'type'            => sanitize_key( (string) ( $_POST['type'] ?? 'client' ) ),
			'level'           => sanitize_key( (string) ( $_POST['level'] ?? 'error' ) ),
			'message'         => sanitize_textarea_field( (string) ( $_POST['message'] ?? '' ) ),
			'request_context' => sanitize_text_field( (string) ( $_POST['request_context'] ?? '' ) ),
			'request_uri'     => sanitize_text_field( (string) ( $_POST['request_uri'] ?? '' ) ),
			'source'          => sanitize_text_field( (string) ( $_POST['source'] ?? '' ) ),
			'resource'        => sanitize_text_field( (string) ( $_POST['resource'] ?? '' ) ),
			'status_code'     => absint( $_POST['status_code'] ?? 0 ),
			'session_id'      => sanitize_text_field( (string) ( $_POST['session_id'] ?? '' ) ),
			'resource_hints'  => $this->normalize_resource_hints( wp_unslash( $_POST['resource_hints'] ?? '' ) ),
		);

		$active_session = $this->resolve_active_session_for_context( (string) $event['request_context'] );
		if ( empty( $event['session_id'] ) && ! empty( $active_session['id'] ) ) {
			$event['session_id'] = (string) $active_session['id'];
		}

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

		$context = $this->build_request_context();
		$this->repository->record_request_context( $context );

		if ( ! empty( $context['session_id'] ) ) {
			$this->sessions->touch_activity( (string) $context['session_id'] );
		}

		$last_error = error_get_last();
		if ( is_array( $last_error ) && $this->is_fatal_error( (int) ( $last_error['type'] ?? 0 ) ) ) {
			$this->repository->record_event(
				array(
					'timestamp'       => current_time( 'mysql' ),
					'type'            => 'php_runtime',
					'level'           => 'fatal',
					'message'         => (string) ( $last_error['message'] ?? '' ),
					'request_context' => (string) ( $context['request_context'] ?? '' ),
					'request_uri'     => (string) ( $context['request_uri'] ?? '' ),
					'source'          => sanitize_text_field( (string) ( $last_error['file'] ?? '' ) ),
					'resource'        => sanitize_text_field( basename( (string) ( $last_error['file'] ?? '' ) ) ),
					'status_code'     => 500,
					'session_id'      => (string) ( $context['session_id'] ?? '' ),
				)
			)
		}

		$status_code = http_response_code();
		if ( is_int( $status_code ) && $status_code >= 400 ) {
			$this->repository->record_event(
				array(
					'timestamp'       => current_time( 'mysql' ),
					'type'            => 'http_response',
					'level'           => $status_code >= 500 ? 'server-error' : 'client-error',
					'message'         => sprintf(
						/* translators: %d status code. */
						__( 'Observed HTTP response %d during request execution.', 'plugin-conflict-debugger' ),
						$status_code
					),
					'request_context' => (string) ( $context['request_context'] ?? '' ),
					'request_uri'     => (string) ( $context['request_uri'] ?? '' ),
					'resource'        => (string) ( $context['resource'] ?? '' ),
					'status_code'     => $status_code,
					'session_id'      => (string) ( $context['session_id'] ?? '' ),
				)
			)
		}
	}

	/**
	 * Enqueues the runtime telemetry script.
	 *
	 * @param string $default_context Default request context.
	 * @return void
	 */
	private function enqueue_runtime_script( string $default_context ): void {
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
				'requestContext' => $default_context,
				'requestUri'     => $this->current_request_uri(),
				'resourceHints'  => $this->detect_resource_hints(),
				'activeSession'  => $this->session_payload_for_client( $default_context ),
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
		$context = array(
			'timestamp'       => current_time( 'mysql' ),
			'request_context' => $this->detect_request_context(),
			'request_uri'     => $this->current_request_uri(),
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
			$context['ajax_action'] = sanitize_key( (string) ( $_REQUEST['action'] ?? '' ) );
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
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
		return sanitize_text_field( $request_uri );
	}

	/**
	 * Detects the current REST route.
	 *
	 * @return string
	 */
	private function detect_rest_route(): string {
		$rest_route = isset( $_REQUEST['rest_route'] ) ? wp_unslash( (string) $_REQUEST['rest_route'] ) : '';
		if ( '' !== $rest_route ) {
			return sanitize_text_field( $rest_route );
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
			$action = sanitize_key( (string) ( $_REQUEST['action'] ?? '' ) );
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
}
