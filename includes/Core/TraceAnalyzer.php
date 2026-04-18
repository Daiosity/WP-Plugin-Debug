<?php
/**
 * Builds request-trace snapshots and comparisons from runtime telemetry.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TraceAnalyzer {
	/**
	 * Builds a trace snapshot from captured request contexts and runtime events.
	 *
	 * @param array<int, array<string, mixed>> $request_contexts Request contexts.
	 * @param array<int, array<string, mixed>> $runtime_events Runtime events.
	 * @param array<string, mixed>              $focus_session Focused diagnostic session.
	 * @return array<string, mixed>
	 */
	public function build_snapshot( array $request_contexts, array $runtime_events, array $focus_session = array() ): array {
		$focus_session_id = sanitize_text_field( (string) ( $focus_session['id'] ?? '' ) );
		$focused_contexts = array();
		$focused_events   = array();

		if ( '' !== $focus_session_id ) {
			foreach ( $request_contexts as $request_context ) {
				if ( $focus_session_id === sanitize_text_field( (string) ( $request_context['session_id'] ?? '' ) ) ) {
					$focused_contexts[] = $request_context;
				}
			}

			foreach ( $runtime_events as $runtime_event ) {
				if ( $focus_session_id === sanitize_text_field( (string) ( $runtime_event['session_id'] ?? '' ) ) ) {
					$focused_events[] = $runtime_event;
				}
			}
		}

		if ( ! empty( $focused_contexts ) || ! empty( $focused_events ) ) {
			$request_contexts = ! empty( $focused_contexts ) ? $focused_contexts : $request_contexts;
			$runtime_events   = ! empty( $focused_events ) ? $focused_events : $runtime_events;
		}

		$traces = $this->build_traces( $request_contexts, $runtime_events );
		$traces = array_values( $traces );

		usort(
			$traces,
			function ( array $left, array $right ): int {
				$risk_compare = (int) ( $right['risk_score'] ?? 0 ) <=> (int) ( $left['risk_score'] ?? 0 );
				if ( 0 !== $risk_compare ) {
					return $risk_compare;
				}

				$event_compare = (int) ( $right['event_count'] ?? 0 ) <=> (int) ( $left['event_count'] ?? 0 );
				if ( 0 !== $event_compare ) {
					return $event_compare;
				}

				return strcmp( (string) ( $right['last_seen'] ?? '' ), (string) ( $left['last_seen'] ?? '' ) );
			}
		);

		$comparison = $this->build_comparison( $traces );

		return array(
			'focus_label'      => ! empty( $focused_contexts ) || ! empty( $focused_events ) ? sanitize_text_field( (string) ( $focus_session['label'] ?? '' ) ) : '',
			'focused_session'  => ! empty( $focus_session['id'] ) ? $this->sanitize_focus_session( $focus_session ) : array(),
			'trace_count'      => count( $traces ),
			'traces'           => array_slice( $traces, 0, 8 ),
			'comparison'       => $comparison,
		);
	}

	/**
	 * Builds a compact snapshot suitable for scan history.
	 *
	 * @param array<string, mixed> $trace_snapshot Trace snapshot.
	 * @return array<string, mixed>
	 */
	public function build_history_snapshot( array $trace_snapshot ): array {
		$traces      = is_array( $trace_snapshot['traces'] ?? null ) ? $trace_snapshot['traces'] : array();
		$comparison  = is_array( $trace_snapshot['comparison'] ?? null ) ? $trace_snapshot['comparison'] : array();
		$trace_items = array();

		foreach ( array_slice( $traces, 0, 4 ) as $trace ) {
			$trace_items[] = array(
				'signature'      => sanitize_text_field( (string) ( $trace['signature'] ?? '' ) ),
				'label'          => sanitize_text_field( (string) ( $trace['label'] ?? '' ) ),
				'request_uri'    => sanitize_text_field( (string) ( $trace['request_uri'] ?? '' ) ),
				'request_context'=> sanitize_text_field( (string) ( $trace['request_context'] ?? '' ) ),
				'risk_score'     => (int) ( $trace['risk_score'] ?? 0 ),
				'health'         => sanitize_key( (string) ( $trace['health'] ?? 'info' ) ),
			);
		}

		return array(
			'focus_label' => sanitize_text_field( (string) ( $trace_snapshot['focus_label'] ?? '' ) ),
			'trace_count' => (int) ( $trace_snapshot['trace_count'] ?? count( $traces ) ),
			'traces'      => $trace_items,
			'comparison'  => array(
				'has_comparison' => ! empty( $comparison['has_comparison'] ),
				'primary_label'  => sanitize_text_field( (string) ( $comparison['primary']['label'] ?? '' ) ),
				'secondary_label'=> sanitize_text_field( (string) ( $comparison['secondary']['label'] ?? '' ) ),
			),
		);
	}

	/**
	 * Builds normalized traces keyed by trace signature.
	 *
	 * @param array<int, array<string, mixed>> $request_contexts Request contexts.
	 * @param array<int, array<string, mixed>> $runtime_events Runtime events.
	 * @return array<string, array<string, mixed>>
	 */
	private function build_traces( array $request_contexts, array $runtime_events ): array {
		$traces = array();

		foreach ( $request_contexts as $request_context ) {
			$key = $this->trace_key( $request_context );
			if ( '' === $key ) {
				continue;
			}

			if ( ! isset( $traces[ $key ] ) ) {
				$traces[ $key ] = $this->empty_trace( $request_context, $key );
			}

			$traces[ $key ]['request_count']++;
			$traces[ $key ]['request_uri'] = $this->pick_better_uri(
				(string) $traces[ $key ]['request_uri'],
				(string) ( $request_context['request_uri'] ?? '' )
			);
			$traces[ $key ]['request_scope'] = $this->pick_better_resource(
				(string) ( $traces[ $key ]['request_scope'] ?? '' ),
				(string) ( $request_context['request_scope'] ?? '' ),
				$request_context
			);
			$traces[ $key ]['resource'] = $this->pick_better_resource(
				(string) $traces[ $key ]['resource'],
				(string) ( $request_context['resource'] ?? '' ),
				$request_context
			);
			$traces[ $key ]['first_seen'] = $this->earlier_timestamp(
				(string) $traces[ $key ]['first_seen'],
				(string) ( $request_context['timestamp'] ?? '' )
			);
			$traces[ $key ]['last_seen'] = $this->later_timestamp(
				(string) $traces[ $key ]['last_seen'],
				(string) ( $request_context['timestamp'] ?? '' )
			);
			$traces[ $key ]['resource_hints'] = $this->merge_unique_strings(
				(array) $traces[ $key ]['resource_hints'],
				is_array( $request_context['resource_hints'] ?? null ) ? $request_context['resource_hints'] : array()
			);
		}

		foreach ( $runtime_events as $runtime_event ) {
			$key = $this->trace_key( $runtime_event );
			if ( '' === $key ) {
				continue;
			}

			if ( ! isset( $traces[ $key ] ) ) {
				$traces[ $key ] = $this->empty_trace( $runtime_event, $key );
			}

			$type        = sanitize_key( (string) ( $runtime_event['type'] ?? 'runtime' ) );
			$status_code = (int) ( $runtime_event['status_code'] ?? 0 );

			$traces[ $key ]['event_count']++;
			$traces[ $key ]['request_uri'] = $this->pick_better_uri(
				(string) $traces[ $key ]['request_uri'],
				(string) ( $runtime_event['request_uri'] ?? '' )
			);
			$traces[ $key ]['request_scope'] = $this->pick_better_resource(
				(string) ( $traces[ $key ]['request_scope'] ?? '' ),
				(string) ( $runtime_event['request_scope'] ?? '' ),
				$runtime_event
			);
			$traces[ $key ]['resource'] = $this->pick_better_resource(
				(string) $traces[ $key ]['resource'],
				(string) ( $runtime_event['resource'] ?? '' ),
				$runtime_event
			);
			$traces[ $key ]['first_seen'] = $this->earlier_timestamp(
				(string) $traces[ $key ]['first_seen'],
				(string) ( $runtime_event['timestamp'] ?? '' )
			);
			$traces[ $key ]['last_seen'] = $this->later_timestamp(
				(string) $traces[ $key ]['last_seen'],
				(string) ( $runtime_event['timestamp'] ?? '' )
			);
			$traces[ $key ]['event_types'] = $this->merge_unique_strings(
				(array) $traces[ $key ]['event_types'],
				array( $type )
			);
			$traces[ $key ]['execution_surfaces'] = $this->merge_unique_strings(
				(array) $traces[ $key ]['execution_surfaces'],
				array( (string) ( $runtime_event['execution_surface'] ?? '' ) )
			);
			$traces[ $key ]['resource_hints'] = $this->merge_unique_strings(
				(array) $traces[ $key ]['resource_hints'],
				is_array( $runtime_event['resource_hints'] ?? null ) ? $runtime_event['resource_hints'] : array()
			);
			$traces[ $key ]['owner_slugs'] = $this->merge_unique_strings(
				(array) $traces[ $key ]['owner_slugs'],
				is_array( $runtime_event['owner_slugs'] ?? null ) ? $runtime_event['owner_slugs'] : array()
			);

			if ( $this->is_failure_event( $runtime_event ) ) {
				$traces[ $key ]['failure_count']++;
			}

			if ( $this->is_mutation_event( $runtime_event ) ) {
				$traces[ $key ]['mutation_count']++;
			}

			if ( $this->is_js_event( $runtime_event ) ) {
				$traces[ $key ]['js_error_count']++;
			}

			if ( $status_code > 0 ) {
				$traces[ $key ]['status_codes'] = array_values(
					array_unique(
						array_merge(
							(array) $traces[ $key ]['status_codes'],
							array( $status_code )
						)
					)
				);
			}
		}

		foreach ( $traces as $key => $trace ) {
			$traces[ $key ] = $this->finalize_trace( $trace );
		}

		return $traces;
	}

	/**
	 * Builds a comparison between the most abnormal trace and the closest calmer trace.
	 *
	 * @param array<int, array<string, mixed>> $traces Sorted traces.
	 * @return array<string, mixed>
	 */
	private function build_comparison( array $traces ): array {
		if ( count( $traces ) < 2 ) {
			return array(
				'has_comparison' => false,
			);
		}

		$primary   = $traces[0];
		$secondary = $this->pick_comparison_trace( $primary, array_slice( $traces, 1 ) );

		if ( empty( $secondary ) ) {
			return array(
				'has_comparison' => false,
			);
		}

		return array(
			'has_comparison' => true,
			'primary'        => $primary,
			'secondary'      => $secondary,
			'metric_delta'   => array(
				'requests'  => (int) ( $primary['request_count'] ?? 0 ) - (int) ( $secondary['request_count'] ?? 0 ),
				'events'    => (int) ( $primary['event_count'] ?? 0 ) - (int) ( $secondary['event_count'] ?? 0 ),
				'failures'  => (int) ( $primary['failure_count'] ?? 0 ) - (int) ( $secondary['failure_count'] ?? 0 ),
				'mutations' => (int) ( $primary['mutation_count'] ?? 0 ) - (int) ( $secondary['mutation_count'] ?? 0 ),
			),
			'only_in_primary' => $this->build_trace_diff( $primary, $secondary ),
			'only_in_secondary' => $this->build_trace_diff( $secondary, $primary ),
			'explanation'    => $this->build_comparison_explanation( $primary, $secondary ),
		);
	}

	/**
	 * Returns an empty trace scaffold.
	 *
	 * @param array<string, mixed> $record Source record.
	 * @param string               $key Trace key.
	 * @return array<string, mixed>
	 */
	private function empty_trace( array $record, string $key ): array {
		return array(
			'signature'          => md5( $key ),
			'trace_key'          => $key,
			'request_context'    => sanitize_text_field( (string) ( $record['request_context'] ?? __( 'runtime', 'conflict-debugger' ) ) ),
			'request_uri'        => sanitize_text_field( (string) ( $record['request_uri'] ?? '/' ) ),
			'request_scope'      => sanitize_text_field( (string) ( $record['request_scope'] ?? '' ) ),
			'resource'           => sanitize_text_field( (string) ( $record['resource'] ?? '' ) ),
			'resource_family'    => $this->resource_family_for_record( $record ),
			'request_count'      => 0,
			'event_count'        => 0,
			'failure_count'      => 0,
			'mutation_count'     => 0,
			'js_error_count'     => 0,
			'status_codes'       => array(),
			'event_types'        => array(),
			'execution_surfaces' => array(),
			'resource_hints'     => array(),
			'owner_slugs'        => array(),
			'first_seen'         => sanitize_text_field( (string) ( $record['timestamp'] ?? '' ) ),
			'last_seen'          => sanitize_text_field( (string) ( $record['timestamp'] ?? '' ) ),
		);
	}

	/**
	 * Finalizes a trace with scores and display labels.
	 *
	 * @param array<string, mixed> $trace Raw trace.
	 * @return array<string, mixed>
	 */
	private function finalize_trace( array $trace ): array {
		$risk_score = ( (int) $trace['failure_count'] * 20 ) + ( (int) $trace['mutation_count'] * 16 ) + ( (int) $trace['js_error_count'] * 12 ) + ( (int) $trace['event_count'] * 2 ) + count( (array) $trace['status_codes'] );
		$health     = 'info';

		if ( (int) $trace['failure_count'] > 0 || (int) $trace['mutation_count'] >= 2 ) {
			$health = 'critical';
		} elseif ( (int) $trace['mutation_count'] > 0 || (int) $trace['event_count'] >= 3 ) {
			$health = 'warning';
		}

		$trace['risk_score'] = $risk_score;
		$trace['health']     = $health;
		$trace['label']      = $this->build_trace_label( $trace );
		$trace['summary']    = $this->build_trace_summary( $trace );

		return $trace;
	}

	/**
	 * Chooses the best comparison trace.
	 *
	 * @param array<string, mixed>              $primary Primary trace.
	 * @param array<int, array<string, mixed>> $candidates Candidate traces.
	 * @return array<string, mixed>
	 */
	private function pick_comparison_trace( array $primary, array $candidates ): array {
		$best_candidate = array();
		$best_score     = -999;

		foreach ( $candidates as $candidate ) {
			$score = 0;

			if ( (string) ( $candidate['request_context'] ?? '' ) === (string) ( $primary['request_context'] ?? '' ) ) {
				$score += 5;
			}

			if ( (string) ( $candidate['resource_family'] ?? '' ) !== '' && (string) ( $candidate['resource_family'] ?? '' ) === (string) ( $primary['resource_family'] ?? '' ) ) {
				$score += 6;
			}

			if ( $this->comparable_path( (string) ( $candidate['request_uri'] ?? '' ) ) === $this->comparable_path( (string) ( $primary['request_uri'] ?? '' ) ) ) {
				$score += 4;
			}

			$score -= abs( (int) ( $candidate['risk_score'] ?? 0 ) - (int) ( $primary['risk_score'] ?? 0 ) );

			if ( $score > $best_score ) {
				$best_score     = $score;
				$best_candidate = $candidate;
			}
		}

		return $best_candidate;
	}

	/**
	 * Builds a difference set from one trace to another.
	 *
	 * @param array<string, mixed> $left Left trace.
	 * @param array<string, mixed> $right Right trace.
	 * @return array<string, array<int, string>>
	 */
	private function build_trace_diff( array $left, array $right ): array {
		return array(
			'event_types'        => $this->array_difference( (array) ( $left['event_types'] ?? array() ), (array) ( $right['event_types'] ?? array() ) ),
			'execution_surfaces' => $this->array_difference( (array) ( $left['execution_surfaces'] ?? array() ), (array) ( $right['execution_surfaces'] ?? array() ) ),
			'owners'             => $this->array_difference( (array) ( $left['owner_slugs'] ?? array() ), (array) ( $right['owner_slugs'] ?? array() ) ),
			'resource_hints'     => $this->array_difference( (array) ( $left['resource_hints'] ?? array() ), (array) ( $right['resource_hints'] ?? array() ) ),
		);
	}

	/**
	 * Builds a short comparison explanation.
	 *
	 * @param array<string, mixed> $primary Primary trace.
	 * @param array<string, mixed> $secondary Secondary trace.
	 * @return string
	 */
	private function build_comparison_explanation( array $primary, array $secondary ): string {
		return sprintf(
			/* translators: 1: primary label, 2: secondary label. */
			__( 'This comparison highlights what changed between the most abnormal captured trace (%1$s) and the closest calmer trace (%2$s).', 'conflict-debugger' ),
			(string) ( $primary['label'] ?? __( 'affected trace', 'conflict-debugger' ) ),
			(string) ( $secondary['label'] ?? __( 'comparison trace', 'conflict-debugger' ) )
		);
	}

	/**
	 * Builds a display label for a trace.
	 *
	 * @param array<string, mixed> $trace Trace.
	 * @return string
	 */
	private function build_trace_label( array $trace ): string {
		$context  = sanitize_text_field( (string) ( $trace['request_context'] ?? __( 'runtime', 'conflict-debugger' ) ) );
		$scope    = sanitize_text_field( (string) ( $trace['request_scope'] ?? '' ) );
		$resource = sanitize_text_field( (string) ( $trace['resource'] ?? '' ) );
		$uri      = sanitize_text_field( (string) ( $trace['request_uri'] ?? '' ) );

		if ( '' !== $resource ) {
			return sprintf(
				/* translators: 1: request context, 2: resource. */
				__( '%1$s trace on %2$s', 'conflict-debugger' ),
				$context,
				$resource
			);
		}

		if ( '' !== $scope ) {
			return sprintf(
				/* translators: 1: request context, 2: request scope. */
				__( '%1$s trace on %2$s', 'conflict-debugger' ),
				$context,
				$scope
			);
		}

		if ( '' !== $uri ) {
			return sprintf(
				/* translators: 1: request context, 2: request URI. */
				__( '%1$s trace on %2$s', 'conflict-debugger' ),
				$context,
				$uri
			);
		}

		return sprintf(
			/* translators: %s request context. */
			__( '%s trace', 'conflict-debugger' ),
			$context
		);
	}

	/**
	 * Builds a trace summary sentence.
	 *
	 * @param array<string, mixed> $trace Trace.
	 * @return string
	 */
	private function build_trace_summary( array $trace ): string {
		return sprintf(
			/* translators: 1: requests, 2: events, 3: failures, 4: mutations. */
			__( '%1$d requests, %2$d runtime events, %3$d observed failures, %4$d mutation signals.', 'conflict-debugger' ),
			(int) ( $trace['request_count'] ?? 0 ),
			(int) ( $trace['event_count'] ?? 0 ),
			(int) ( $trace['failure_count'] ?? 0 ),
			(int) ( $trace['mutation_count'] ?? 0 )
		);
	}

	/**
	 * Builds a stable trace key.
	 *
	 * @param array<string, mixed> $record Source record.
	 * @return string
	 */
	private function trace_key( array $record ): string {
		$request_context = strtolower( sanitize_text_field( (string) ( $record['request_context'] ?? '' ) ) );
		$request_uri     = sanitize_text_field( (string) ( $record['request_uri'] ?? '' ) );
		$request_scope   = sanitize_text_field( (string) ( $record['request_scope'] ?? '' ) );
		$session_id      = sanitize_text_field( (string) ( $record['session_id'] ?? '' ) );
		$resource_family = $this->resource_family_for_record( $record );
		$path            = '' !== $request_scope ? strtolower( $request_scope ) : $this->comparable_path( $request_uri );

		if ( '' === $request_context && '' === $resource_family && '' === $path ) {
			return '';
		}

		return implode(
			'|',
			array(
				$session_id,
				$request_context,
				$resource_family,
				$path,
			)
		);
	}

	/**
	 * Returns a resource family for trace grouping.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return string
	 */
	private function resource_family_for_record( array $record ): string {
		$resource = sanitize_text_field( (string) ( $record['resource'] ?? '' ) );
		if ( '' !== $resource ) {
			return strtolower( $resource );
		}

		$request_scope = sanitize_text_field( (string) ( $record['request_scope'] ?? '' ) );
		if ( '' !== $request_scope ) {
			return strtolower( $request_scope );
		}

		$resource_hints = is_array( $record['resource_hints'] ?? null ) ? $record['resource_hints'] : array();
		foreach ( $resource_hints as $resource_hint ) {
			$resource_hint = sanitize_text_field( (string) $resource_hint );
			if ( '' === $resource_hint ) {
				continue;
			}

			if ( str_starts_with( $resource_hint, 'ajax:' ) || str_starts_with( $resource_hint, 'rest:' ) || str_starts_with( $resource_hint, 'screen:' ) ) {
				return strtolower( $resource_hint );
			}
		}

		return $this->comparable_path( sanitize_text_field( (string) ( $record['request_uri'] ?? '' ) ) );
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
	 * Picks the better URI to display.
	 *
	 * @param string $current Current URI.
	 * @param string $candidate Candidate URI.
	 * @return string
	 */
	private function pick_better_uri( string $current, string $candidate ): string {
		$current   = sanitize_text_field( $current );
		$candidate = sanitize_text_field( $candidate );

		if ( '' === $current ) {
			return $candidate;
		}

		if ( strlen( $candidate ) > strlen( $current ) ) {
			return $candidate;
		}

		return $current;
	}

	/**
	 * Picks the better resource string.
	 *
	 * @param string               $current Current resource.
	 * @param string               $candidate Candidate resource.
	 * @param array<string, mixed> $record Source record.
	 * @return string
	 */
	private function pick_better_resource( string $current, string $candidate, array $record ): string {
		$current   = sanitize_text_field( $current );
		$candidate = sanitize_text_field( $candidate );

		if ( '' !== $candidate ) {
			return $candidate;
		}

		if ( '' !== $current ) {
			return $current;
		}

		$resource_hints = is_array( $record['resource_hints'] ?? null ) ? $record['resource_hints'] : array();
		foreach ( $resource_hints as $resource_hint ) {
			$resource_hint = sanitize_text_field( (string) $resource_hint );
			if ( str_contains( $resource_hint, ':' ) ) {
				return $resource_hint;
			}
		}

		return '';
	}

	/**
	 * Returns whether an event is an observed failure.
	 *
	 * @param array<string, mixed> $runtime_event Runtime event.
	 * @return bool
	 */
	private function is_failure_event( array $runtime_event ): bool {
		$type        = sanitize_key( (string) ( $runtime_event['type'] ?? 'runtime' ) );
		$status_code = (int) ( $runtime_event['status_code'] ?? 0 );

		return in_array( $type, array( 'php_runtime', 'js_error', 'promise_rejection', 'missing_asset', 'http_response' ), true ) || $status_code >= 400;
	}

	/**
	 * Returns whether an event is a mutation signal.
	 *
	 * @param array<string, mixed> $runtime_event Runtime event.
	 * @return bool
	 */
	private function is_mutation_event( array $runtime_event ): bool {
		$type = sanitize_key( (string) ( $runtime_event['type'] ?? 'runtime' ) );

		return '' !== (string) ( $runtime_event['mutation_kind'] ?? '' ) || in_array( $type, array( 'callback_mutation', 'asset_mutation' ), true );
	}

	/**
	 * Returns whether an event is a JavaScript-side failure.
	 *
	 * @param array<string, mixed> $runtime_event Runtime event.
	 * @return bool
	 */
	private function is_js_event( array $runtime_event ): bool {
		$type = sanitize_key( (string) ( $runtime_event['type'] ?? 'runtime' ) );

		return in_array( $type, array( 'js_error', 'promise_rejection', 'missing_asset' ), true );
	}

	/**
	 * Returns the earlier of two timestamps.
	 *
	 * @param string $current Current timestamp.
	 * @param string $candidate Candidate timestamp.
	 * @return string
	 */
	private function earlier_timestamp( string $current, string $candidate ): string {
		if ( '' === $current ) {
			return $candidate;
		}

		$current_time   = strtotime( $current );
		$candidate_time = strtotime( $candidate );

		if ( false === $current_time || false === $candidate_time ) {
			return $current;
		}

		return $candidate_time < $current_time ? $candidate : $current;
	}

	/**
	 * Returns the later of two timestamps.
	 *
	 * @param string $current Current timestamp.
	 * @param string $candidate Candidate timestamp.
	 * @return string
	 */
	private function later_timestamp( string $current, string $candidate ): string {
		if ( '' === $current ) {
			return $candidate;
		}

		$current_time   = strtotime( $current );
		$candidate_time = strtotime( $candidate );

		if ( false === $current_time || false === $candidate_time ) {
			return $current;
		}

		return $candidate_time > $current_time ? $candidate : $current;
	}

	/**
	 * Merges sanitized unique strings.
	 *
	 * @param array<int, mixed> $left Left values.
	 * @param array<int, mixed> $right Right values.
	 * @return string[]
	 */
	private function merge_unique_strings( array $left, array $right ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $value ): string => sanitize_text_field( (string) $value ),
						array_merge( $left, $right )
					)
				)
			)
		);
	}

	/**
	 * Returns strings present in left but not right.
	 *
	 * @param array<int, mixed> $left Left values.
	 * @param array<int, mixed> $right Right values.
	 * @return string[]
	 */
	private function array_difference( array $left, array $right ): array {
		$left  = $this->merge_unique_strings( $left, array() );
		$right = $this->merge_unique_strings( $right, array() );

		return array_values( array_diff( $left, $right ) );
	}

	/**
	 * Sanitizes session data for snapshot storage.
	 *
	 * @param array<string, mixed> $focus_session Focus session.
	 * @return array<string, mixed>
	 */
	private function sanitize_focus_session( array $focus_session ): array {
		return array(
			'id'             => sanitize_text_field( (string) ( $focus_session['id'] ?? '' ) ),
			'label'          => sanitize_text_field( (string) ( $focus_session['label'] ?? '' ) ),
			'target_context' => sanitize_key( (string) ( $focus_session['target_context'] ?? '' ) ),
			'started_at'     => sanitize_text_field( (string) ( $focus_session['started_at'] ?? '' ) ),
			'ended_at'       => sanitize_text_field( (string) ( $focus_session['ended_at'] ?? '' ) ),
			'status'         => sanitize_key( (string) ( $focus_session['status'] ?? '' ) ),
		);
	}
}
