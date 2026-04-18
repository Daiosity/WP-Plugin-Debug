<?php
/**
 * Captures asset lifecycle checkpoints across a request.
 *
 * This tracer is intentionally conservative. It records concrete state changes
 * for handles and adds attribution status only when the mutating actor can be
 * narrowed with reasonable confidence.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

use WP_Dependencies;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AssetLifecycleTracer {
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
	 * Diagnostic sessions.
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
	 * Last captured snapshot for the request.
	 *
	 * @var array<string, mixed>
	 */
	private array $last_snapshot = array();

	/**
	 * Tracks emitted lifecycle fingerprints.
	 *
	 * @var array<string, bool>
	 */
	private array $emitted = array();

	/**
	 * Prevents recursive checkpoint capture while WordPress is building assets.
	 *
	 * @var bool
	 */
	private bool $is_capturing = false;

	/**
	 * Registers tracer hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( $this->tracked_hooks() as $hook_name ) {
			add_action( $hook_name, array( $this, 'capture_baseline_checkpoint' ), -999999, 20 );
			$this->register_priority_checkpoints( $hook_name );
			add_action( $hook_name, array( $this, 'capture_late_checkpoint' ), 9999, 20 );
		}

		add_action( 'shutdown', array( $this, 'capture_final_checkpoint' ), 998 );
	}

	/**
	 * Constructor.
	 *
	 * @param RuntimeTelemetryRepository   $repository Telemetry repository.
	 * @param RegistrySnapshot             $registry Registry snapshot collector.
	 * @param DiagnosticSessionRepository  $sessions Session repository.
	 */
	public function __construct( RuntimeTelemetryRepository $repository, RegistrySnapshot $registry, DiagnosticSessionRepository $sessions, ValidationModeRepository $validation ) {
		$this->repository = $repository;
		$this->registry   = $registry;
		$this->sessions   = $sessions;
		$this->validation = $validation;
	}

	/**
	 * Captures a baseline checkpoint before the active asset hook runs.
	 *
	 * @return void
	 */
	public function capture_baseline_checkpoint(): void {
		$this->capture_checkpoint( 'before', array(), -999999 );
	}

	/**
	 * Captures a priority-level checkpoint for the active asset hook.
	 *
	 * @param int $priority Callback priority being observed.
	 * @return void
	 */
	public function capture_priority_checkpoint( int $priority ): void {
		$this->capture_checkpoint(
			'after_priority_' . $priority,
			$this->hook_actor_candidates( current_filter(), $priority ),
			$priority
		);
	}

	/**
	 * Captures a late-phase checkpoint for the active asset hook.
	 *
	 * @return void
	 */
	public function capture_late_checkpoint(): void {
		$this->capture_checkpoint( 'after', $this->hook_actor_candidates( current_filter() ), 9999 );
	}

	/**
	 * Captures a final checkpoint before shutdown completes.
	 *
	 * @return void
	 */
	public function capture_final_checkpoint(): void {
		$this->capture_checkpoint( 'final' );
	}

	/**
	 * Returns the set of hooks to monitor.
	 *
	 * @return string[]
	 */
	private function tracked_hooks(): array {
		return array(
			'wp_enqueue_scripts',
			'admin_enqueue_scripts',
			'login_enqueue_scripts',
			'wp_print_scripts',
			'wp_print_styles',
			'admin_print_scripts',
			'admin_print_styles',
			'login_print_scripts',
			'login_print_styles',
		);
	}

	/**
	 * Captures one checkpoint and records any lifecycle deltas.
	 *
	 * @param string $phase Phase label.
	 * @return void
	 */
	private function capture_checkpoint( string $phase, array $actor_candidates = array(), ?int $priority = null ): void {
		if ( $this->is_capturing ) {
			return;
		}

		$this->is_capturing = true;
		try {
		$current_snapshot = $this->build_snapshot();

		if ( empty( $this->last_snapshot ) ) {
			$this->last_snapshot = $current_snapshot;
			return;
		}

		$hook_name = current_filter();
		$scope     = $this->current_scope();
		$meta      = array(
			'hook'             => sanitize_text_field( $hook_name ),
			'phase'            => sanitize_key( $phase ),
			'priority'         => null !== $priority ? $priority : 0,
			'request_context'  => (string) $scope['request_context'],
			'request_uri'      => (string) $scope['request_uri'],
			'request_scope'    => (string) $scope['request_scope'],
			'scope_type'       => (string) $scope['scope_type'],
			'request_id'       => (string) $scope['request_id'],
			'session_id'       => (string) $scope['session_id'],
			'resource_hints'   => (array) $scope['resource_hints'],
			'actor_candidates' => $actor_candidates,
		);

		$this->compare_store( 'script', (array) ( $this->last_snapshot['scripts'] ?? array() ), (array) ( $current_snapshot['scripts'] ?? array() ), $meta );
		$this->compare_store( 'style', (array) ( $this->last_snapshot['styles'] ?? array() ), (array) ( $current_snapshot['styles'] ?? array() ), $meta );

		$this->last_snapshot = $current_snapshot;
		} finally {
			$this->is_capturing = false;
		}
	}

	/**
	 * Registers hook checkpoints at each known callback priority.
	 *
	 * @param string $hook_name Hook name.
	 * @return void
	 */
	private function register_priority_checkpoints( string $hook_name ): void {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook_name ] ) || ! $wp_filter[ $hook_name ] instanceof \WP_Hook ) {
			return;
		}

		$priorities = array_map( 'intval', array_keys( $wp_filter[ $hook_name ]->callbacks ) );
		sort( $priorities, SORT_NUMERIC );

		foreach ( $priorities as $priority ) {
			if ( $priority >= 9999 ) {
				continue;
			}

			add_action(
				$hook_name,
				function () use ( $priority ): void {
					$this->capture_priority_checkpoint( $priority );
				},
				$priority,
				20
			);
		}
	}

	/**
	 * Compares one dependency store between checkpoints.
	 *
	 * @param string               $type Asset type.
	 * @param array<string, mixed> $previous Previous snapshot.
	 * @param array<string, mixed> $current Current snapshot.
	 * @param array<string, mixed> $meta Checkpoint metadata.
	 * @return void
	 */
	private function compare_store( string $type, array $previous, array $current, array $meta ): void {
		$previous_registered = (array) ( $previous['registered'] ?? array() );
		$current_registered  = (array) ( $current['registered'] ?? array() );
		$previous_queue      = array_values( array_map( 'strval', (array) ( $previous['queue'] ?? array() ) ) );
		$current_queue       = array_values( array_map( 'strval', (array) ( $current['queue'] ?? array() ) ) );

		foreach ( $current_registered as $handle => $current_state ) {
			$previous_state = $previous_registered[ $handle ] ?? array();
			if ( empty( $previous_state ) ) {
				$this->record_asset_event( $type, 'asset_registered', (string) $handle, array(), (array) $current_state, $meta );
				continue;
			}

			$this->record_changed_asset_properties( $type, (string) $handle, (array) $previous_state, (array) $current_state, $meta );
		}

		foreach ( $previous_registered as $handle => $previous_state ) {
			if ( isset( $current_registered[ $handle ] ) ) {
				continue;
			}

			$this->record_asset_event( $type, 'asset_deregistered', (string) $handle, (array) $previous_state, array(), $meta );
		}

		foreach ( array_diff( $current_queue, $previous_queue ) as $handle ) {
			$this->record_asset_event(
				$type,
				'asset_enqueued',
				(string) $handle,
				(array) ( $previous_registered[ $handle ] ?? array() ),
				(array) ( $current_registered[ $handle ] ?? array() ),
				$meta
			);
		}

		foreach ( array_diff( $previous_queue, $current_queue ) as $handle ) {
			$this->record_asset_event(
				$type,
				'asset_dequeued',
				(string) $handle,
				(array) ( $previous_registered[ $handle ] ?? array() ),
				(array) ( $current_registered[ $handle ] ?? array() ),
				$meta
			);
		}
	}

	/**
	 * Records property-level asset changes.
	 *
	 * @param string               $type Asset type.
	 * @param string               $handle Asset handle.
	 * @param array<string, mixed> $previous_state Previous state.
	 * @param array<string, mixed> $current_state Current state.
	 * @param array<string, mixed> $meta Checkpoint metadata.
	 * @return void
	 */
	private function record_changed_asset_properties( string $type, string $handle, array $previous_state, array $current_state, array $meta ): void {
		$property_map = array(
			'src'   => 'asset_src_changed',
			'deps'  => 'asset_dependency_changed',
			'ver'   => 'asset_version_changed',
			'group' => 'asset_group_changed',
			'media' => 'asset_media_changed',
		);

		foreach ( $property_map as $property => $mutation_kind ) {
			$previous_value = $previous_state[ $property ] ?? '';
			$current_value  = $current_state[ $property ] ?? '';

			if ( $previous_value === $current_value ) {
				continue;
			}

			$this->record_asset_event( $type, $mutation_kind, $handle, $previous_state, $current_state, $meta );
		}
	}

	/**
	 * Records a single asset lifecycle event.
	 *
	 * @param string               $type Asset type.
	 * @param string               $mutation_kind Mutation kind.
	 * @param string               $handle Asset handle.
	 * @param array<string, mixed> $previous_state Previous state.
	 * @param array<string, mixed> $current_state Current state.
	 * @param array<string, mixed> $meta Checkpoint metadata.
	 * @return void
	 */
	private function record_asset_event( string $type, string $mutation_kind, string $handle, array $previous_state, array $current_state, array $meta ): void {
		if ( '' === $handle ) {
			return;
		}

		$owner_slug     = sanitize_key( (string) ( $current_state['owner_slug'] ?? $previous_state['owner_slug'] ?? '' ) );
		$actor_meta     = $this->resolve_actor_meta( $mutation_kind, $owner_slug, (array) ( $meta['actor_candidates'] ?? array() ), (string) ( $meta['phase'] ?? '' ) );
		$actor_slug     = sanitize_key( (string) ( $actor_meta['actor_slug'] ?? '' ) );

		if ( '' === $owner_slug && '' === $actor_slug ) {
			return;
		}

		if ( 'asset_registered' === $mutation_kind && '' === $owner_slug ) {
			return;
		}
		$fingerprint    = md5(
			wp_json_encode(
				array(
					'handle'        => $handle,
					'mutation_kind' => $mutation_kind,
					'hook'          => (string) ( $meta['hook'] ?? '' ),
					'phase'         => (string) ( $meta['phase'] ?? '' ),
					'request_scope' => (string) ( $meta['request_scope'] ?? '' ),
				)
			)
		);

		if ( isset( $this->emitted[ $fingerprint ] ) ) {
			return;
		}

		$this->emitted[ $fingerprint ] = true;

		$resource_hints = array_merge(
			(array) ( $meta['resource_hints'] ?? array() ),
			array( 'asset:' . $handle )
		);
		$owner_slugs    = array_values(
			array_unique(
				array_filter(
					array(
						$owner_slug,
						(string) ( $actor_meta['actor_slug'] ?? '' ),
					)
				)
			)
		);

		$this->repository->record_event(
			$this->validation->decorate_event(
				array(
				'event_id'             => TraceEvent::new_event_id(),
				'request_id'           => (string) ( $meta['request_id'] ?? TraceEvent::current_request_id() ),
				'sequence'             => TraceEvent::next_sequence(),
				'timestamp'            => current_time( 'mysql' ),
				'type'                 => 'asset_lifecycle',
				'evidence_source'      => TraceEvent::SOURCE_TRACE,
				'level'                => in_array( $mutation_kind, array( 'asset_dequeued', 'asset_deregistered' ), true ) ? 'warning' : 'info',
				'message'              => $this->build_asset_message( $type, $mutation_kind, $handle, $owner_slug, $actor_meta, $meta ),
				'request_context'      => (string) ( $meta['request_context'] ?? 'runtime' ),
				'request_uri'          => (string) ( $meta['request_uri'] ?? '/' ),
				'request_scope'        => (string) ( $meta['request_scope'] ?? '' ),
				'scope_type'           => (string) ( $meta['scope_type'] ?? '' ),
				'resource'             => $handle,
				'resource_type'        => $type . '_handle',
				'resource_key'         => $handle,
				'execution_surface'    => (string) ( $meta['hook'] ?? '' ),
				'hook'                 => (string) ( $meta['hook'] ?? '' ),
				'callback'             => '',
				'priority'             => (int) ( $meta['priority'] ?? 0 ),
				'mutation_kind'        => $mutation_kind,
				'mutation_status'      => in_array( $mutation_kind, array( 'asset_dequeued', 'asset_deregistered' ), true ) ? TraceEvent::MUTATION_OBSERVED : TraceEvent::MUTATION_SUSPECTED,
				'attribution_status'   => (string) ( $actor_meta['attribution_status'] ?? TraceEvent::ATTRIBUTION_UNKNOWN ),
				'contamination_status' => TraceEvent::CONTAMINATION_NONE,
				'actor_slug'           => $actor_slug,
				'target_owner_slug'    => $owner_slug,
				'resource_hints'       => array_values( array_unique( array_filter( $resource_hints ) ) ),
				'owner_slugs'          => $owner_slugs,
				'previous_state'       => TraceEvent::sanitize_state( $previous_state ),
				'new_state'            => TraceEvent::sanitize_state( $current_state ),
				'session_id'           => (string) ( $meta['session_id'] ?? '' ),
				)
			)
		);
	}

	/**
	 * Resolves actor attribution for a lifecycle event.
	 *
	 * @param string   $mutation_kind Mutation kind.
	 * @param string   $owner_slug Resource owner slug.
	 * @param string[] $actor_candidates Actor candidates on the hook.
	 * @param string   $phase Checkpoint phase.
	 * @return array<string, string>
	 */
	private function resolve_actor_meta( string $mutation_kind, string $owner_slug, array $actor_candidates, string $phase ): array {
		$actor_candidates = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $actor_candidates )
				)
			)
		);

		if (
			in_array( $mutation_kind, array( 'asset_registered', 'asset_enqueued' ), true )
			&& '' !== $owner_slug
			&& 1 === count( $actor_candidates )
			&& in_array( $owner_slug, $actor_candidates, true )
		) {
			return array(
				'actor_slug'          => $owner_slug,
				'attribution_status'  => TraceEvent::ATTRIBUTION_DIRECT,
			);
		}

		if ( in_array( $mutation_kind, array( 'asset_registered', 'asset_enqueued' ), true ) ) {
			return array(
				'actor_slug'         => '',
				'attribution_status' => TraceEvent::ATTRIBUTION_UNKNOWN,
			);
		}

		$filtered_candidates = array_values(
			array_filter(
				$actor_candidates,
				static fn( string $candidate ): bool => '' !== $candidate && $candidate !== $owner_slug
			)
		);

		if ( 1 === count( $filtered_candidates ) ) {
			return array(
				'actor_slug'         => $filtered_candidates[0],
				'attribution_status' => 0 === strpos( $phase, 'after_priority_' ) ? TraceEvent::ATTRIBUTION_DIRECT : TraceEvent::ATTRIBUTION_PARTIAL,
			);
		}

		return array(
			'actor_slug'         => '',
			'attribution_status' => TraceEvent::ATTRIBUTION_UNKNOWN,
		);
	}

	/**
	 * Builds a concrete lifecycle message.
	 *
	 * @param string               $type Asset type.
	 * @param string               $mutation_kind Mutation kind.
	 * @param string               $handle Asset handle.
	 * @param string               $owner_slug Resource owner.
	 * @param array<string, mixed> $actor_meta Actor attribution.
	 * @param array<string, mixed> $meta Checkpoint metadata.
	 * @return string
	 */
	private function build_asset_message( string $type, string $mutation_kind, string $handle, string $owner_slug, array $actor_meta, array $meta ): string {
		$hook_name    = sanitize_text_field( (string) ( $meta['hook'] ?? __( 'asset lifecycle', 'conflict-debugger' ) ) );
		$owner_label  = '' !== $owner_slug ? $owner_slug : __( 'unknown owner', 'conflict-debugger' );
		$actor_slug   = sanitize_key( (string) ( $actor_meta['actor_slug'] ?? '' ) );
		$actor_status = (string) ( $actor_meta['attribution_status'] ?? TraceEvent::ATTRIBUTION_UNKNOWN );

		if ( 'asset_registered' === $mutation_kind ) {
			return sprintf(
				/* translators: 1: handle, 2: owner slug, 3: hook name. */
				__( 'Handle %1$s was registered for %2$s during %3$s.', 'conflict-debugger' ),
				$handle,
				$owner_label,
				$hook_name
			);
		}

		if ( 'asset_enqueued' === $mutation_kind ) {
			return sprintf(
				/* translators: 1: type, 2: handle, 3: hook name. */
				__( 'The %1$s handle %2$s entered the queue during %3$s.', 'conflict-debugger' ),
				$type,
				$handle,
				$hook_name
			);
		}

		if ( in_array( $mutation_kind, array( 'asset_dequeued', 'asset_deregistered' ), true ) && '' !== $actor_slug && TraceEvent::ATTRIBUTION_UNKNOWN !== $actor_status ) {
			return sprintf(
				/* translators: 1: handle, 2: owner slug, 3: mutation kind, 4: actor slug, 5: hook name. */
				__( 'Handle %1$s owned by %2$s was %3$s while %4$s was the clearest mutator candidate on %5$s.', 'conflict-debugger' ),
				$handle,
				$owner_label,
				str_replace( '_', ' ', str_replace( 'asset_', '', $mutation_kind ) ),
				$actor_slug,
				$hook_name
			);
		}

		if ( in_array( $mutation_kind, array( 'asset_dequeued', 'asset_deregistered' ), true ) ) {
			return sprintf(
				/* translators: 1: handle, 2: owner slug, 3: mutation kind, 4: hook name. */
				__( 'Handle %1$s owned by %2$s was %3$s during %4$s, but the mutating callback could not yet be attributed with certainty.', 'conflict-debugger' ),
				$handle,
				$owner_label,
				str_replace( '_', ' ', str_replace( 'asset_', '', $mutation_kind ) ),
				$hook_name
			);
		}

		return sprintf(
			/* translators: 1: handle, 2: mutation kind, 3: hook name. */
			__( 'Handle %1$s changed state (%2$s) during %3$s.', 'conflict-debugger' ),
			$handle,
			str_replace( '_', ' ', str_replace( 'asset_', '', $mutation_kind ) ),
			$hook_name
		);
	}

	/**
	 * Builds a detailed snapshot of the current dependency stores.
	 *
	 * @return array<string, mixed>
	 */
	private function build_snapshot(): array {
		global $wp_scripts, $wp_styles;

		return array(
			'scripts' => $this->snapshot_dependency_store( $wp_scripts instanceof WP_Dependencies ? $wp_scripts : null, 'script' ),
			'styles'  => $this->snapshot_dependency_store( $wp_styles instanceof WP_Dependencies ? $wp_styles : null, 'style' ),
		);
	}

	/**
	 * Snapshots a dependency store.
	 *
	 * @param WP_Dependencies|false|null $store Dependency store.
	 * @param string                     $type Asset type.
	 * @return array<string, mixed>
	 */
	private function snapshot_dependency_store( $store, string $type ): array {
		if ( ! $store instanceof WP_Dependencies ) {
			return array(
				'queue'      => array(),
				'registered' => array(),
			);
		}

		$registry_snapshot = $this->registry->get_asset_snapshot();
		$queue             = array_map( static fn( $handle ): string => sanitize_key( (string) $handle ), (array) ( $store->queue ?? array() ) );
		$queue_lookup      = array_fill_keys( $queue, true );
		$registered        = array();

		foreach ( (array) $store->registered as $handle => $dependency ) {
			$handle     = sanitize_key( (string) $handle );
			$src        = sanitize_text_field( (string) ( $dependency->src ?? '' ) );
			$owner_slug = sanitize_key( (string) ( $registry_snapshot[ $handle ]['owner_slug'] ?? $this->resolve_plugin_slug_from_path( $src ) ) );
			$group      = isset( $dependency->extra['group'] ) ? sanitize_text_field( (string) $dependency->extra['group'] ) : '';
			$media      = 'style' === $type ? sanitize_text_field( (string) ( $dependency->args ?? '' ) ) : '';
			$in_queue   = isset( $queue_lookup[ $handle ] );

			// Keep the snapshot lean. For attribution we mainly care about plugin-owned
			// handles and the handles that are actually live in the queue right now.
			if ( '' === $owner_slug && ! $in_queue ) {
				continue;
			}

			$registered[ $handle ] = array(
				'handle'     => $handle,
				'owner_slug' => $owner_slug,
				'type'       => $type,
				'src'        => $src,
				'deps'       => array_slice( array_values( array_map( 'sanitize_key', (array) ( $dependency->deps ?? array() ) ) ), 0, 8 ),
				'ver'        => sanitize_text_field( (string) ( $dependency->ver ?? '' ) ),
				'group'      => $group,
				'media'      => $media,
				'in_queue'   => $in_queue,
			);
		}

		return array(
			'queue'      => $queue,
			'registered' => $registered,
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
			$action = $this->current_ajax_action();
			if ( '' !== $action ) {
				$scope_type     = 'ajax';
				$request_scope  = 'ajax:' . $action;
				$resource_hints[] = $request_scope;
			}
		} elseif ( $this->is_rest_request() ) {
			$route = $this->detect_rest_route();
			if ( '' !== $route ) {
				$scope_type     = 'rest';
				$request_scope  = 'rest:' . $route;
				$resource_hints[] = $request_scope;
			}
		} elseif ( is_admin() && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && ! empty( $screen->id ) ) {
				$scope_type     = 'screen';
				$request_scope  = 'screen:' . sanitize_key( (string) $screen->id );
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
	 * Returns plugin owners attached to a hook.
	 *
	 * @param string $hook_name Hook name.
	 * @return string[]
	 */
	private function hook_actor_candidates( string $hook_name, ?int $priority = null ): array {
		global $wp_filter;

		if ( '' === $hook_name || empty( $wp_filter[ $hook_name ] ) || ! $wp_filter[ $hook_name ] instanceof \WP_Hook ) {
			return array();
		}

		$owners = array();

		foreach ( $wp_filter[ $hook_name ]->callbacks as $registered_priority => $callbacks ) {
			if ( null !== $priority && (int) $registered_priority !== $priority ) {
				continue;
			}

			foreach ( $callbacks as $callback ) {
				if ( $this->is_tracer_callback( $callback['function'] ?? null ) ) {
					continue;
				}

				$owner_slug = $this->resolve_plugin_slug_from_callback( $callback['function'] ?? null );
				if ( '' !== $owner_slug ) {
					$owners[] = $owner_slug;
				}
			}
		}

		return array_values( array_unique( $owners ) );
	}

	/**
	 * Ignores the tracer's own callbacks when resolving actor candidates.
	 *
	 * @param mixed $callback Callback definition.
	 * @return bool
	 */
	private function is_tracer_callback( mixed $callback ): bool {
		if ( is_array( $callback ) && isset( $callback[0] ) && $callback[0] instanceof self ) {
			return true;
		}

		if ( $callback instanceof \Closure ) {
			try {
				$reflection = new \ReflectionFunction( $callback );
				return wp_normalize_path( (string) $reflection->getFileName() ) === wp_normalize_path( __FILE__ );
			} catch ( \Throwable $exception ) {
				return false;
			}
		}

		return false;
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request classification for trace scope capture.
		if ( ! isset( $_REQUEST['action'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request classification for trace scope capture.
		return sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) );
	}

	/**
	 * Detects the current REST route.
	 *
	 * @return string
	 */
	private function detect_rest_route(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route detection for trace scope capture.
		if ( isset( $_REQUEST['rest_route'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route detection for trace scope capture.
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
				$reflection = new \ReflectionMethod( $callback[0], (string) $callback[1] );
				return $this->resolve_plugin_slug_from_path( (string) $reflection->getFileName() );
			}

			if ( is_string( $callback ) && function_exists( $callback ) ) {
				$reflection = new \ReflectionFunction( $callback );
				return $this->resolve_plugin_slug_from_path( (string) $reflection->getFileName() );
			}

			if ( $callback instanceof \Closure ) {
				$reflection = new \ReflectionFunction( $callback );
				return $this->resolve_plugin_slug_from_path( (string) $reflection->getFileName() );
			}
		} catch ( \Throwable $exception ) {
			return '';
		}

		return '';
	}

	/**
	 * Resolves a plugin slug from a file path or URL.
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
}
