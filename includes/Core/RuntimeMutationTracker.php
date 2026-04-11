<?php
/**
 * Tracks callback lifecycle mutations on sensitive hooks.
 *
 * This tracer stays intentionally conservative. It records concrete callback
 * state changes and only emits pair-oriented attribution when the mutating
 * actor can be narrowed with reasonable confidence from the same request.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

use ReflectionFunction;
use ReflectionMethod;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RuntimeMutationTracker {
	/**
	 * Telemetry repository.
	 *
	 * @var RuntimeTelemetryRepository
	 */
	private RuntimeTelemetryRepository $repository;

	/**
	 * Diagnostic sessions.
	 *
	 * @var DiagnosticSessionRepository
	 */
	private DiagnosticSessionRepository $sessions;

	/**
	 * Last captured callback snapshots per hook.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $hook_snapshots = array();

	/**
	 * Emitted mutation fingerprints.
	 *
	 * @var array<string, bool>
	 */
	private array $emitted = array();

	/**
	 * Sensitive hooks to monitor for callback mutations.
	 *
	 * @var string[]
	 */
	private array $sensitive_hooks = array(
		'template_redirect',
		'the_content',
		'admin_init',
		'current_screen',
		'admin_menu',
		'enqueue_block_editor_assets',
		'authenticate',
		'login_redirect',
		'rest_api_init',
		'wp_enqueue_scripts',
		'admin_enqueue_scripts',
		'script_loader_tag',
		'style_loader_tag',
	);

	/**
	 * Constructor.
	 *
	 * @param RuntimeTelemetryRepository  $repository Telemetry repository.
	 * @param DiagnosticSessionRepository $sessions Session repository.
	 */
	public function __construct( RuntimeTelemetryRepository $repository, DiagnosticSessionRepository $sessions ) {
		$this->repository = $repository;
		$this->sessions   = $sessions;
	}

	/**
	 * Registers runtime mutation hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'capture_hook_baseline' ), 1 );
		add_action( 'wp_loaded', array( $this, 'capture_runtime_checkpoint' ), 999 );
		add_action( 'shutdown', array( $this, 'capture_final_checkpoint' ), 997 );
	}

	/**
	 * Captures an early hook baseline before late plugin mutations occur.
	 *
	 * @return void
	 */
	public function capture_hook_baseline(): void {
		foreach ( $this->sensitive_hooks as $hook_name ) {
			$this->hook_snapshots[ $hook_name ] = $this->snapshot_hook_callbacks( $hook_name );
		}
	}

	/**
	 * Captures the main runtime checkpoint.
	 *
	 * @return void
	 */
	public function capture_runtime_checkpoint(): void {
		$this->compare_hook_snapshots( 'runtime' );
	}

	/**
	 * Captures the final request checkpoint.
	 *
	 * @return void
	 */
	public function capture_final_checkpoint(): void {
		$this->compare_hook_snapshots( 'final' );
	}

	/**
	 * Compares the current callback state against the latest snapshot.
	 *
	 * @param string $phase Phase label.
	 * @return void
	 */
	private function compare_hook_snapshots( string $phase ): void {
		foreach ( $this->sensitive_hooks as $hook_name ) {
			$previous = is_array( $this->hook_snapshots[ $hook_name ] ?? null ) ? $this->hook_snapshots[ $hook_name ] : array();
			$current  = $this->snapshot_hook_callbacks( $hook_name );

			if ( empty( $previous ) ) {
				$this->hook_snapshots[ $hook_name ] = $current;
				continue;
			}

			$this->analyze_hook_delta( $hook_name, $previous, $current, $phase );
			$this->hook_snapshots[ $hook_name ] = $current;
		}
	}

	/**
	 * Analyzes the delta between two hook snapshots.
	 *
	 * @param string               $hook_name Hook name.
	 * @param array<string, mixed> $previous Previous snapshot.
	 * @param array<string, mixed> $current Current snapshot.
	 * @param string               $phase Phase label.
	 * @return void
	 */
	private function analyze_hook_delta( string $hook_name, array $previous, array $current, string $phase ): void {
		$previous_callbacks = is_array( $previous['callbacks'] ?? null ) ? $previous['callbacks'] : array();
		$current_callbacks  = is_array( $current['callbacks'] ?? null ) ? $current['callbacks'] : array();

		if ( empty( $previous_callbacks ) && empty( $current_callbacks ) ) {
			return;
		}

		$scope            = $this->current_scope();
		$current_owners   = is_array( $current['owners'] ?? null ) ? array_values( array_filter( array_map( 'sanitize_key', $current['owners'] ) ) ) : array();
		$consumed_current = array();
		$removed          = array();
		$added            = array();

		foreach ( $previous_callbacks as $fingerprint => $callback_meta ) {
			if ( isset( $current_callbacks[ $fingerprint ] ) ) {
				continue;
			}

			$matched_fingerprint = $this->find_priority_shift_match( (array) $callback_meta, $current_callbacks, $consumed_current );
			if ( '' !== $matched_fingerprint ) {
				$consumed_current[ $matched_fingerprint ] = true;
				$this->record_callback_event(
					$hook_name,
					'callback_priority_changed',
					(array) $callback_meta,
					(array) $current_callbacks[ $matched_fingerprint ],
					$phase,
					array(
						'actor_slug'         => '',
						'attribution_status' => TraceEvent::ATTRIBUTION_UNKNOWN,
					),
					$scope
				);
				continue;
			}

			$removed[] = (array) $callback_meta;
		}

		foreach ( $current_callbacks as $fingerprint => $callback_meta ) {
			if ( isset( $previous_callbacks[ $fingerprint ] ) || isset( $consumed_current[ $fingerprint ] ) ) {
				continue;
			}

			$added[] = (array) $callback_meta;
		}

		foreach ( $removed as $removed_meta ) {
			$actor_meta    = $this->resolve_removed_actor_meta( $removed_meta, $added, $current_owners );
			$replacement   = $this->resolve_replacement_candidate( $removed_meta, $added, (string) ( $actor_meta['actor_slug'] ?? '' ) );
			$mutation_kind = empty( $replacement ) ? 'callback_removed' : 'callback_replaced';

			$this->record_callback_event(
				$hook_name,
				$mutation_kind,
				$removed_meta,
				$replacement,
				$phase,
				$actor_meta,
				$scope
			);
		}
	}

	/**
	 * Snapshots a hook callback list.
	 *
	 * @param string $hook_name Hook name.
	 * @return array<string, mixed>
	 */
	private function snapshot_hook_callbacks( string $hook_name ): array {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook_name ] ) || ! $wp_filter[ $hook_name ] instanceof \WP_Hook ) {
			return array(
				'callbacks' => array(),
				'owners'    => array(),
			);
		}

		$callbacks = array();
		$owners    = array();

		foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $hook_callbacks ) {
			foreach ( $hook_callbacks as $callback ) {
				$callback_function = $callback['function'] ?? null;
				$owner_slug        = $this->resolve_plugin_slug_from_callback( $callback_function );
				if ( '' === $owner_slug ) {
					continue;
				}

				$fingerprint = $this->fingerprint_callback( $callback_function, (int) $priority, $hook_name );
				if ( '' === $fingerprint ) {
					continue;
				}

				$callback_label = $this->describe_callback( $callback_function );
				$callback_key   = md5( $hook_name . '|' . $owner_slug . '|' . $callback_label );

				$callbacks[ $fingerprint ] = array(
					'fingerprint'    => $fingerprint,
					'callback_key'   => $callback_key,
					'owner_slug'     => $owner_slug,
					'priority'       => (int) $priority,
					'hook'           => $hook_name,
					'callback_label' => $callback_label,
				);
				$owners[] = $owner_slug;
			}
		}

		return array(
			'callbacks' => $callbacks,
			'owners'    => array_values( array_unique( $owners ) ),
		);
	}

	/**
	 * Finds a callback in the current snapshot that matches by owner and label.
	 *
	 * @param array<string, mixed>              $callback_meta Callback metadata.
	 * @param array<string, array<string, mixed>> $current_callbacks Current callbacks.
	 * @param array<string, bool>               $consumed_current Already consumed fingerprints.
	 * @return string
	 */
	private function find_priority_shift_match( array $callback_meta, array $current_callbacks, array $consumed_current ): string {
		$callback_key = (string) ( $callback_meta['callback_key'] ?? '' );
		if ( '' === $callback_key ) {
			return '';
		}

		foreach ( $current_callbacks as $fingerprint => $current_meta ) {
			if ( isset( $consumed_current[ $fingerprint ] ) ) {
				continue;
			}

			if ( $callback_key === (string) ( $current_meta['callback_key'] ?? '' ) ) {
				return (string) $fingerprint;
			}
		}

		return '';
	}

	/**
	 * Resolves the most conservative actor attribution possible for a removal.
	 *
	 * @param array<string, mixed>          $removed_meta Removed callback metadata.
	 * @param array<int, array<string, mixed>> $added Added callbacks in the same phase.
	 * @param string[]                      $current_owners Current owners still attached to the hook.
	 * @return array<string, string>
	 */
	private function resolve_removed_actor_meta( array $removed_meta, array $added, array $current_owners ): array {
		$target_owner = sanitize_key( (string) ( $removed_meta['owner_slug'] ?? '' ) );
		$added_owners = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( array $meta ): string => sanitize_key( (string) ( $meta['owner_slug'] ?? '' ) ),
						$added
					)
				)
			)
		);
		$added_owners = array_values(
			array_filter(
				$added_owners,
				static fn( string $owner ): bool => '' !== $owner && $owner !== $target_owner
			)
		);

		if ( 1 === count( $added_owners ) ) {
			return array(
				'actor_slug'         => $added_owners[0],
				'attribution_status' => TraceEvent::ATTRIBUTION_PARTIAL,
			);
		}

		$current_candidates = array_values(
			array_filter(
				array_map( 'sanitize_key', $current_owners ),
				static fn( string $owner ): bool => '' !== $owner && $owner !== $target_owner
			)
		);

		if ( 1 === count( $current_candidates ) ) {
			return array(
				'actor_slug'         => $current_candidates[0],
				'attribution_status' => TraceEvent::ATTRIBUTION_PARTIAL,
			);
		}

		return array(
			'actor_slug'         => '',
			'attribution_status' => TraceEvent::ATTRIBUTION_UNKNOWN,
		);
	}

	/**
	 * Attempts to find a plausible replacement callback.
	 *
	 * @param array<string, mixed>          $removed_meta Removed callback metadata.
	 * @param array<int, array<string, mixed>> $added Added callbacks.
	 * @param string                        $actor_slug Attributed actor slug.
	 * @return array<string, mixed>
	 */
	private function resolve_replacement_candidate( array $removed_meta, array $added, string $actor_slug ): array {
		if ( '' === $actor_slug ) {
			return array();
		}

		$matches = array_values(
			array_filter(
				$added,
				static function ( array $candidate ) use ( $removed_meta, $actor_slug ): bool {
					if ( sanitize_key( (string) ( $candidate['owner_slug'] ?? '' ) ) !== $actor_slug ) {
						return false;
					}

					return (string) ( $candidate['hook'] ?? '' ) === (string) ( $removed_meta['hook'] ?? '' )
						&& (int) ( $candidate['priority'] ?? 0 ) === (int) ( $removed_meta['priority'] ?? 0 );
				}
			)
		);

		if ( 1 !== count( $matches ) ) {
			return array();
		}

		return (array) $matches[0];
	}

	/**
	 * Records a callback mutation event once per request.
	 *
	 * @param string                $hook_name Hook name.
	 * @param string                $mutation_kind Mutation kind.
	 * @param array<string, mixed>  $previous_meta Previous callback metadata.
	 * @param array<string, mixed>  $current_meta Current callback metadata.
	 * @param string                $phase Phase label.
	 * @param array<string, string> $actor_meta Actor metadata.
	 * @param array<string, mixed>  $scope Current request scope.
	 * @return void
	 */
	private function record_callback_event( string $hook_name, string $mutation_kind, array $previous_meta, array $current_meta, string $phase, array $actor_meta, array $scope ): void {
		$callback_label = sanitize_text_field( (string) ( $previous_meta['callback_label'] ?? $current_meta['callback_label'] ?? '' ) );
		$target_owner   = sanitize_key( (string) ( $previous_meta['owner_slug'] ?? $current_meta['owner_slug'] ?? '' ) );
		$actor_slug     = sanitize_key( (string) ( $actor_meta['actor_slug'] ?? '' ) );

		if ( '' === $callback_label || '' === $target_owner ) {
			return;
		}

		$resource_hints = array_merge(
			(array) ( $scope['resource_hints'] ?? array() ),
			array(
				'hook:' . $hook_name,
				'callback:' . $callback_label,
			)
		);
		$owner_slugs    = array_values(
			array_unique(
				array_filter(
					array(
						$target_owner,
						$actor_slug,
					)
				)
			)
		);

		$this->record_event_once(
			array(
				'event_id'             => TraceEvent::new_event_id(),
				'request_id'           => (string) ( $scope['request_id'] ?? TraceEvent::current_request_id() ),
				'sequence'             => TraceEvent::next_sequence(),
				'type'                 => 'callback_mutation',
				'evidence_source'      => TraceEvent::SOURCE_TRACE,
				'level'                => in_array( $mutation_kind, array( 'callback_removed', 'callback_replaced' ), true ) ? 'warning' : 'info',
				'message'              => $this->build_callback_message(
					$mutation_kind,
					$hook_name,
					$callback_label,
					$previous_meta,
					$current_meta,
					$target_owner,
					$actor_slug,
					(string) ( $actor_meta['attribution_status'] ?? TraceEvent::ATTRIBUTION_UNKNOWN )
				),
				'request_context'      => (string) ( $scope['request_context'] ?? $this->detect_request_context() ),
				'request_uri'          => (string) ( $scope['request_uri'] ?? $this->current_request_uri() ),
				'request_scope'        => (string) ( $scope['request_scope'] ?? $this->current_request_uri() ),
				'scope_type'           => (string) ( $scope['scope_type'] ?? 'path' ),
				'resource'             => $callback_label,
				'resource_type'        => 'callback',
				'resource_key'         => $callback_label,
				'execution_surface'    => $hook_name,
				'hook'                 => $hook_name,
				'callback'             => $callback_label,
				'priority'             => (int) ( $current_meta['priority'] ?? $previous_meta['priority'] ?? 0 ),
				'callback_identifier'  => $callback_label,
				'mutation_kind'        => $mutation_kind,
				'mutation_status'      => TraceEvent::MUTATION_OBSERVED,
				'attribution_status'   => (string) ( $actor_meta['attribution_status'] ?? TraceEvent::ATTRIBUTION_UNKNOWN ),
				'contamination_status' => TraceEvent::CONTAMINATION_NONE,
				'actor_slug'           => $actor_slug,
				'target_owner_slug'    => $target_owner,
				'resource_hints'       => array_values( array_unique( array_filter( $resource_hints ) ) ),
				'owner_slugs'          => $owner_slugs,
				'previous_state'       => TraceEvent::sanitize_state(
					array_merge(
						array( 'phase' => $phase ),
						$previous_meta
					)
				),
				'new_state'            => TraceEvent::sanitize_state(
					array_merge(
						array( 'phase' => $phase ),
						$current_meta
					)
				),
				'session_id'           => (string) ( $scope['session_id'] ?? '' ),
			)
		);
	}

	/**
	 * Builds a readable callback mutation message.
	 *
	 * @param string               $mutation_kind Mutation kind.
	 * @param string               $hook_name Hook name.
	 * @param string               $callback_label Callback label.
	 * @param array<string, mixed> $previous_meta Previous callback state.
	 * @param array<string, mixed> $current_meta Current callback state.
	 * @param string               $target_owner Target owner slug.
	 * @param string               $actor_slug Actor slug.
	 * @param string               $attribution_status Attribution status.
	 * @return string
	 */
	private function build_callback_message( string $mutation_kind, string $hook_name, string $callback_label, array $previous_meta, array $current_meta, string $target_owner, string $actor_slug, string $attribution_status ): string {
		if ( 'callback_priority_changed' === $mutation_kind ) {
			return sprintf(
				/* translators: 1: callback label, 2: owner slug, 3: old priority, 4: new priority, 5: hook name. */
				__( 'Callback %1$s owned by %2$s moved from priority %3$d to %4$d on %5$s during this request.', 'plugin-conflict-debugger' ),
				$callback_label,
				$target_owner,
				(int) ( $previous_meta['priority'] ?? 0 ),
				(int) ( $current_meta['priority'] ?? 0 ),
				$hook_name
			);
		}

		if ( 'callback_replaced' === $mutation_kind && '' !== $actor_slug && TraceEvent::ATTRIBUTION_UNKNOWN !== $attribution_status ) {
			return sprintf(
				/* translators: 1: callback label, 2: target owner slug, 3: hook name, 4: actor slug. */
				__( 'Callback %1$s owned by %2$s disappeared from %3$s while %4$s introduced a replacement candidate at the same priority in the same request.', 'plugin-conflict-debugger' ),
				$callback_label,
				$target_owner,
				$hook_name,
				$actor_slug
			);
		}

		if ( 'callback_removed' === $mutation_kind && '' !== $actor_slug && TraceEvent::ATTRIBUTION_UNKNOWN !== $attribution_status ) {
			return sprintf(
				/* translators: 1: callback label, 2: target owner slug, 3: hook name, 4: actor slug. */
				__( 'Callback %1$s owned by %2$s was present earlier on %3$s and later disappeared while %4$s was the clearest mutator candidate on the same request path.', 'plugin-conflict-debugger' ),
				$callback_label,
				$target_owner,
				$hook_name,
				$actor_slug
			);
		}

		return sprintf(
			/* translators: 1: callback label, 2: target owner slug, 3: hook name. */
			__( 'Callback %1$s owned by %2$s was present earlier on %3$s but absent in a later snapshot. The mutating actor could not yet be attributed with confidence.', 'plugin-conflict-debugger' ),
			$callback_label,
			$target_owner,
			$hook_name
		);
	}

	/**
	 * Records a runtime event once per request.
	 *
	 * @param array<string, mixed> $event Event payload.
	 * @return void
	 */
	private function record_event_once( array $event ): void {
		$fingerprint = md5(
			wp_json_encode(
				array(
					'type'        => (string) ( $event['type'] ?? '' ),
					'resource'    => (string) ( $event['resource'] ?? '' ),
					'owners'      => (array) ( $event['owner_slugs'] ?? array() ),
					'request_uri' => (string) ( $event['request_uri'] ?? '' ),
					'phase'       => (string) ( $event['previous_state']['phase'] ?? '' ),
					'mutation'    => (string) ( $event['mutation_kind'] ?? '' ),
				)
			)
		);

		if ( isset( $this->emitted[ $fingerprint ] ) ) {
			return;
		}

		$this->emitted[ $fingerprint ] = true;
		$this->repository->record_event(
			array_merge(
				array(
					'timestamp' => current_time( 'mysql' ),
				),
				$event
			)
		);
	}

	/**
	 * Returns the current request scope payload.
	 *
	 * @return array<string, mixed>
	 */
	private function current_scope(): array {
		$request_context = $this->detect_request_context();
		$request_uri     = $this->current_request_uri();
		$scope_type      = 'path';
		$request_scope   = $this->comparable_path( $request_uri );
		$resource_hints  = array();

		if ( wp_doing_ajax() ) {
			$action = sanitize_key( (string) ( $_REQUEST['action'] ?? '' ) );
			if ( '' !== $action ) {
				$scope_type       = 'ajax';
				$request_scope    = 'ajax:' . $action;
				$resource_hints[] = $request_scope;
			}
		} elseif ( $this->is_rest_request() ) {
			$route = $this->detect_rest_route();
			if ( '' !== $route ) {
				$scope_type       = 'rest';
				$request_scope    = 'rest:' . $route;
				$resource_hints[] = $request_scope;
			}
		} elseif ( is_admin() && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && ! empty( $screen->id ) ) {
				$scope_type       = 'screen';
				$request_scope    = 'screen:' . sanitize_key( (string) $screen->id );
				$resource_hints[] = $request_scope;
			}
		}

		$session = $this->resolve_active_session_for_context( $request_context );

		return array(
			'request_id'      => TraceEvent::current_request_id(),
			'request_context' => $request_context,
			'request_uri'     => $request_uri,
			'request_scope'   => $request_scope,
			'scope_type'      => $scope_type,
			'resource_hints'  => $resource_hints,
			'session_id'      => sanitize_text_field( (string) ( $session['id'] ?? '' ) ),
		);
	}

	/**
	 * Returns current request context.
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
	 * Checks whether the current request is REST.
	 *
	 * @return bool
	 */
	private function is_rest_request(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
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
	 * Resolves active session if the context matches.
	 *
	 * @param string $request_context Current request context.
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
	 * Normalizes a request path for grouping.
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
	 * Resolves a plugin slug from a callback.
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private function resolve_plugin_slug_from_callback( mixed $callback ): string {
		try {
			if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$reflection = new ReflectionMethod( $callback[0], (string) $callback[1] );
				return $this->resolve_plugin_slug_from_path( (string) $reflection->getFileName() );
			}

			if ( is_string( $callback ) && function_exists( $callback ) ) {
				$reflection = new ReflectionFunction( $callback );
				return $this->resolve_plugin_slug_from_path( (string) $reflection->getFileName() );
			}

			if ( $callback instanceof \Closure ) {
				$reflection = new ReflectionFunction( $callback );
				return $this->resolve_plugin_slug_from_path( (string) $reflection->getFileName() );
			}
		} catch ( Throwable $exception ) {
			return '';
		}

		return '';
	}

	/**
	 * Resolves a plugin slug from a file path.
	 *
	 * @param string $path File path or URL.
	 * @return string
	 */
	private function resolve_plugin_slug_from_path( string $path ): string {
		if ( false !== strpos( $path, WP_PLUGIN_URL ) ) {
			$relative = wp_make_link_relative( $path );
			$parts    = explode( '/', trim( $relative, '/' ) );
			$index    = array_search( 'plugins', $parts, true );

			if ( false !== $index && isset( $parts[ $index + 1 ] ) ) {
				return sanitize_key( $parts[ $index + 1 ] );
			}
		}

		$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR );
		$normalized = wp_normalize_path( $path );

		if ( 0 !== strpos( $normalized, $plugin_dir ) ) {
			return '';
		}

		$relative = ltrim( substr( $normalized, strlen( $plugin_dir ) ), '/' );
		$parts    = explode( '/', $relative );

		return isset( $parts[0] ) ? sanitize_key( $parts[0] ) : '';
	}

	/**
	 * Builds a stable callback fingerprint.
	 *
	 * @param mixed  $callback Callback.
	 * @param int    $priority Callback priority.
	 * @param string $hook_name Hook name.
	 * @return string
	 */
	private function fingerprint_callback( mixed $callback, int $priority, string $hook_name ): string {
		if ( is_array( $callback ) && isset( $callback[1] ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return md5( $hook_name . '|' . $priority . '|' . $class . '::' . (string) $callback[1] );
		}

		if ( is_string( $callback ) ) {
			return md5( $hook_name . '|' . $priority . '|' . $callback );
		}

		if ( $callback instanceof \Closure ) {
			return md5( $hook_name . '|' . $priority . '|closure' );
		}

		return '';
	}

	/**
	 * Builds a readable callback identifier for runtime telemetry.
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private function describe_callback( mixed $callback ): string {
		if ( is_array( $callback ) && isset( $callback[1] ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return sanitize_text_field( $class . '::' . (string) $callback[1] );
		}

		if ( is_string( $callback ) ) {
			return sanitize_text_field( $callback );
		}

		if ( $callback instanceof \Closure ) {
			return 'Closure';
		}

		return '';
	}
}
