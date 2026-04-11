<?php
/**
 * Weighted heuristic rules with strict severity caps.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Heuristics {
	/**
	 * Weighted rules by signal key.
	 *
	 * These scores are intentionally conservative. Weak overlap can raise
	 * awareness, but it must not inflate into high-severity findings without
	 * concrete interference or observed breakage.
	 *
	 * @var array<string, int>
	 */
	private array $weights = array(
		'surface_hook_overlap'       => 2,
		'surface_category_match'     => 2,
		'recent_change'              => 2,
		'known_risk_pattern'         => 1,
		'extreme_priority'           => 3,
		'surface_context_match'      => 8,
		'callback_chain_churn'       => 8,
		'callback_order_sensitivity' => 10,
		'repeated_observer_pattern'  => 8,
		'global_anomaly_pattern'     => 8,
		'duplicate_assets'           => 10,
		'optimization_stack_overlap' => 8,
		'cron_overlap'               => 8,
		'background_overlap'         => 8,
		'output_filter_overlap'      => 10,
		'admin_screen_overlap'       => 10,
		'editor_overlap'             => 10,
		'auth_overlap'               => 10,
		'seo_overlap'                => 10,
		'email_overlap'              => 10,
		'security_overlap'           => 10,
		'exact_hook_collision'       => 18,
		'rest_route_overlap'         => 30,
		'ajax_action_overlap'        => 30,
		'routing_overlap'            => 24,
		'content_model_overlap'      => 24,
		'direct_callback_mutation'   => 40,
		'asset_state_mutation'       => 35,
		'error_log_match'            => 45,
		'generic_runtime_noise'      => 8,
		'pair_specific_runtime_breakage' => 50,
		'third_party_contamination'  => 1,
		'extension_relationship'     => 0,
	);

	/**
	 * Evidence tiers by signal key.
	 *
	 * @var array<string, string>
	 */
	private array $tiers = array(
		'surface_hook_overlap'       => 'noise',
		'surface_category_match'     => 'noise',
		'recent_change'              => 'noise',
		'known_risk_pattern'         => 'noise',
		'extreme_priority'           => 'noise',
		'surface_context_match'      => 'supporting',
		'callback_chain_churn'       => 'supporting',
		'callback_order_sensitivity' => 'supporting',
		'repeated_observer_pattern'  => 'supporting',
		'global_anomaly_pattern'     => 'supporting',
		'duplicate_assets'           => 'supporting',
		'optimization_stack_overlap' => 'supporting',
		'cron_overlap'               => 'supporting',
		'background_overlap'         => 'supporting',
		'output_filter_overlap'      => 'supporting',
		'admin_screen_overlap'       => 'supporting',
		'editor_overlap'             => 'supporting',
		'auth_overlap'               => 'supporting',
		'seo_overlap'                => 'supporting',
		'email_overlap'              => 'supporting',
		'security_overlap'           => 'supporting',
		'exact_hook_collision'       => 'strong_proof',
		'rest_route_overlap'         => 'strong_proof',
		'ajax_action_overlap'        => 'strong_proof',
		'routing_overlap'            => 'strong_proof',
		'content_model_overlap'      => 'strong_proof',
		'direct_callback_mutation'   => 'strong_proof',
		'asset_state_mutation'       => 'strong_proof',
		'error_log_match'            => 'runtime_breakage',
		'generic_runtime_noise'      => 'supporting',
		'pair_specific_runtime_breakage' => 'runtime_breakage',
		'third_party_contamination'  => 'noise',
	);

	/**
	 * Common hooks that should stay low-value unless exact shared resources exist.
	 *
	 * @var string[]
	 */
	private array $common_hooks = array(
		'init',
		'plugins_loaded',
		'wp_loaded',
		'template_redirect',
		'wp_enqueue_scripts',
		'admin_enqueue_scripts',
		'shutdown',
		'the_content',
		'widgets_init',
		'admin_init',
	);

	/**
	 * Admin lifecycle hooks that commonly overlap on large plugins.
	 *
	 * @var string[]
	 */
	private array $common_admin_hooks = array(
		'admin_menu',
		'admin_init',
		'current_screen',
		'admin_enqueue_scripts',
		'load-post.php',
		'load-edit.php',
		'load-post-new.php',
	);

	/**
	 * Hooks that can matter more when same-context or same-resource proof exists.
	 *
	 * @var string[]
	 */
	private array $context_sensitive_hooks = array(
		'current_screen',
		'parse_request',
		'pre_get_posts',
		'save_post',
		'rest_api_init',
		'authenticate',
		'login_init',
		'login_redirect',
		'enqueue_block_editor_assets',
	);

	/**
	 * Returns the tier for a given signal key.
	 *
	 * @param string $signal_key Signal key.
	 * @return string
	 */
	public function tier_for( string $signal_key ): string {
		return $this->normalize_tier( $this->tiers[ $signal_key ] ?? 'noise' );
	}

	/**
	 * Normalizes legacy evidence tier names.
	 *
	 * @param string $tier Evidence tier.
	 * @return string
	 */
	public function normalize_tier( string $tier ): string {
		$map = array(
			'weak'            => 'noise',
			'contextual'      => 'supporting',
			'concrete'        => 'strong_proof',
			'observed'        => 'runtime_breakage',
			'context'         => 'supporting',
			'strong'          => 'runtime_breakage',
			'noise'           => 'noise',
			'supporting'      => 'supporting',
			'strong_proof'    => 'strong_proof',
			'runtime_breakage' => 'runtime_breakage',
		);

		return $map[ $tier ] ?? 'noise';
	}

	/**
	 * Returns conservative WordPress-aware hook metadata.
	 *
	 * @param string $hook_name Hook name.
	 * @return array<string, string|int>
	 */
	public function hook_profile( string $hook_name ): array {
		$hook_name = strtolower( trim( $hook_name ) );

		if ( '' === $hook_name ) {
			return array(
				'risk'            => 'noise',
				'overlap_weight'  => 2,
				'priority_weight' => 2,
			);
		}

		if ( 0 === strpos( $hook_name, 'wp_ajax_' ) || 0 === strpos( $hook_name, 'wp_ajax_nopriv_' ) ) {
			return array(
				'risk'            => 'strong_capable',
				'overlap_weight'  => 10,
				'priority_weight' => 5,
			);
		}

		if ( in_array( $hook_name, $this->common_admin_hooks, true ) ) {
			return array(
				'risk'            => 'noise',
				'overlap_weight'  => 2,
				'priority_weight' => 2,
			);
		}

		if ( in_array( $hook_name, $this->common_hooks, true ) ) {
			return array(
				'risk'            => 'shutdown' === $hook_name ? 'noise_minus' : 'noise',
				'overlap_weight'  => 'shutdown' === $hook_name ? 1 : 2,
				'priority_weight' => 'shutdown' === $hook_name ? 1 : 2,
			);
		}

		if ( in_array( $hook_name, $this->context_sensitive_hooks, true ) ) {
			return array(
				'risk'            => 'supporting',
				'overlap_weight'  => 8,
				'priority_weight' => 4,
			);
		}

		if ( 0 === strpos( $hook_name, 'woocommerce_' ) ) {
			return array(
				'risk'            => 'supporting',
				'overlap_weight'  => 8,
				'priority_weight' => 4,
			);
		}

		return array(
			'risk'            => 'supporting',
			'overlap_weight'  => 6,
			'priority_weight' => 3,
		);
	}

	/**
	 * Scores structured evidence items with per-tier caps.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return int
	 */
	public function score_evidence_items( array $evidence_items ): int {
		$tier_scores = array(
			'noise'           => 0,
			'supporting'      => 0,
			'strong_proof'    => 0,
			'runtime_breakage' => 0,
		);
		$seen        = array();

		foreach ( $evidence_items as $evidence_item ) {
			$signal_key      = (string) ( $evidence_item['signal_key'] ?? '' );
			$shared_resource = (string) ( $evidence_item['shared_resource'] ?? '' );
			$tier            = $this->normalize_tier( (string) ( $evidence_item['tier'] ?? $this->tier_for( $signal_key ) ) );
			$fingerprint     = $signal_key . '|' . $shared_resource . '|' . (string) ( $evidence_item['message'] ?? '' );

			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}

			$seen[ $fingerprint ] = true;
			$tier_scores[ $tier ] += $this->weight_for_evidence_item( $evidence_item );
		}

		$score  = min( 16, $tier_scores['noise'] );
		$score += min( 32, $tier_scores['supporting'] );
		$score += min( 55, $tier_scores['strong_proof'] );
		$score += min( 75, $tier_scores['runtime_breakage'] );

		return (int) min( 100, $score );
	}

	/**
	 * Returns a finding type from the strongest evidence tier present.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return string
	 */
	public function finding_type_for( array $evidence_items ): string {
		foreach ( $evidence_items as $evidence_item ) {
			$finding_type_hint = (string) ( $evidence_item['finding_type_hint'] ?? '' );
			if ( '' !== $finding_type_hint ) {
				return $finding_type_hint;
			}
		}

		if ( $this->has_tier( $evidence_items, 'runtime_breakage' ) ) {
			return 'confirmed_conflict';
		}

		if ( $this->has_tier( $evidence_items, 'strong_proof' ) ) {
			return $this->has_direct_interference_signal( $evidence_items ) ? 'probable_conflict' : 'potential_interference';
		}

		if ( $this->has_tier( $evidence_items, 'supporting' ) ) {
			return 'shared_surface';
		}

		return 'overlap';
	}

	/**
	 * Returns a severity label with strict hard caps.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @param int                              $confidence Confidence score.
	 * @return string
	 */
	public function severity_for( array $evidence_items, int $confidence ): string {
		$has_observed   = $this->has_tier( $evidence_items, 'runtime_breakage' );
		$has_concrete   = $this->has_tier( $evidence_items, 'strong_proof' );
		$has_contextual = $this->has_tier( $evidence_items, 'supporting' );
		$has_weak       = $this->has_tier( $evidence_items, 'noise' );

		if ( ! $has_observed && ! $has_concrete && ! $has_contextual && ! $has_weak ) {
			return 'info';
		}

		if ( $has_observed ) {
			return $confidence >= 91 ? 'critical' : 'high';
		}

		if ( $has_concrete ) {
			return $confidence >= 70 ? 'high' : 'medium';
		}

		if ( $has_contextual ) {
			return $confidence >= 40 ? 'medium' : 'low';
		}

		return 'low';
	}

	/**
	 * Returns a structured evidence strength breakdown for UI use.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return array<string, int>
	 */
	public function evidence_breakdown( array $evidence_items ): array {
		$breakdown = array(
			'strong_proof'     => 0,
			'supporting'       => 0,
			'noise'            => 0,
			'runtime_breakage' => 0,
		);

		foreach ( $evidence_items as $evidence_item ) {
			$tier = $this->normalize_tier( (string) ( $evidence_item['tier'] ?? '' ) );
			if ( isset( $breakdown[ $tier ] ) ) {
				$breakdown[ $tier ]++;
			}
		}

		return $breakdown;
	}

	/**
	 * Builds a concise explanation of why a finding landed where it did.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @param int                              $confidence Confidence score.
	 * @param string                           $category Finding category.
	 * @param string                           $severity Severity label.
	 * @return string
	 */
	public function scoring_summary( array $evidence_items, int $confidence, string $category, string $severity ): string {
		$breakdown = $this->evidence_breakdown( $evidence_items );
		$strong_count = (int) ( $breakdown['strong_proof'] ?? 0 );
		$runtime_count = (int) ( $breakdown['runtime_breakage'] ?? 0 );

		if ( 'overlap' === $category ) {
			return __( 'Scored conservatively because the evidence stays in normal overlap territory without same-resource proof.', 'plugin-conflict-debugger' );
		}

		if ( 'shared_surface' === $category ) {
			return 0 === $strong_count
				? __( 'Scored as a shared surface because the finding is driven by common lifecycle overlap and supporting context only. No pair-specific mutation evidence was observed.', 'plugin-conflict-debugger' )
				: __( 'Scored as a shared surface because the plugins overlap in the same context, but no exact shared resource or direct mutation was proven.', 'plugin-conflict-debugger' );
		}

		if ( 'potential_interference' === $category ) {
			return 0 === $strong_count
				? __( 'Scored as potential interference because supporting indicators cluster on one request path, but no pair-specific mutation evidence was observed.', 'plugin-conflict-debugger' )
				: __( 'Scored as potential interference because supporting indicators cluster in one request context, but the proof is still incomplete.', 'plugin-conflict-debugger' );
		}

		if ( 'probable_conflict' === $category ) {
			return sprintf(
				/* translators: 1: confidence, 2: severity. */
				__( 'Scored as %2$s at %1$d%% because the finding includes direct shared-resource or mutation evidence, but not enough observed breakage to call it confirmed.', 'plugin-conflict-debugger' ),
				$confidence,
				$severity
			);
		}

		if ( 'confirmed_conflict' === $category ) {
			return sprintf(
				/* translators: %d strong proof count. */
				__( 'Scored as confirmed because runtime breakage and direct interference signals appear together on the same execution path (%d strong proof signals recorded).', 'plugin-conflict-debugger' ),
				$strong_count + $runtime_count
			);
		}

		return __( 'Scored conservatively based on the strongest evidence tier available.', 'plugin-conflict-debugger' );
	}

	/**
	 * Returns a UI status bucket used by the dashboard chrome.
	 *
	 * @param string $severity Severity label.
	 * @return string
	 */
	public function ui_status_for( string $severity ): string {
		if ( 'critical' === $severity ) {
			return 'critical';
		}

		if ( in_array( $severity, array( 'medium', 'high' ), true ) ) {
			return 'warning';
		}

		return 'healthy';
	}

	/**
	 * Returns a severity rank for sorting.
	 *
	 * @param string $severity Severity label.
	 * @return int
	 */
	public function severity_rank( string $severity ): int {
		$ranks = array(
			'info'     => 0,
			'low'      => 1,
			'medium'   => 2,
			'high'     => 3,
			'critical' => 4,
		);

		return $ranks[ $severity ] ?? 0;
	}

	/**
	 * Returns a recommendation for a conflict surface.
	 *
	 * @param string $surface_key Conflict surface key.
	 * @return string
	 */
	public function suggestion_for( string $surface_key ): string {
		$suggestions = array(
			'frontend_rendering'     => __( 'Reproduce the affected frontend view in staging and compare output filters, shortcodes, and template overrides one plugin at a time.', 'plugin-conflict-debugger' ),
			'asset_loading'          => __( 'Inspect the affected page in staging, review duplicated handles or libraries, and disable one asset or optimization layer at a time.', 'plugin-conflict-debugger' ),
			'admin_screen'           => __( 'Open the affected admin screen in staging and compare menu/page slugs, save handlers, and admin assets one plugin at a time.', 'plugin-conflict-debugger' ),
			'editor'                 => __( 'Reproduce the issue in the editor context first, then test metaboxes, editor assets, and serialization behavior with one plugin disabled.', 'plugin-conflict-debugger' ),
			'authentication_account' => __( 'Retest login, registration, or profile flows in staging and compare redirect, auth, and account hooks one plugin at a time.', 'plugin-conflict-debugger' ),
			'rest_api_ajax'          => __( 'Call the affected REST route or AJAX action in staging and verify which plugin owns the endpoint, auth logic, or nonce flow.', 'plugin-conflict-debugger' ),
			'forms_submission'       => __( 'Submit the affected form in staging and compare validation, anti-spam, and processing handlers with one plugin disabled.', 'plugin-conflict-debugger' ),
			'caching_optimization'   => __( 'Retest the affected page with one caching or optimization layer disabled and review minification, defer, delay, and lazy-load settings.', 'plugin-conflict-debugger' ),
			'seo_metadata'           => __( 'Inspect page source and sitemap output in staging to confirm which plugin should own canonicals, schema, robots, and metadata.', 'plugin-conflict-debugger' ),
			'rewrite_routing'        => __( 'Flush permalinks in staging and retest the affected route, endpoint, or query var with one routing-related plugin disabled.', 'plugin-conflict-debugger' ),
			'content_model'          => __( 'Review the duplicate registration key in staging and decide which plugin should own the post type, taxonomy, or content registration.', 'plugin-conflict-debugger' ),
			'email_notifications'    => __( 'Trigger the affected notification path in staging and verify whether more than one plugin is altering or sending the same mail flow.', 'plugin-conflict-debugger' ),
			'security_access'        => __( 'Retest the blocked route or login flow in staging and compare redirect, capability, and access-control behavior one plugin at a time.', 'plugin-conflict-debugger' ),
			'background_processing'  => __( 'Review the affected cron or background task in staging and check whether multiple plugins are queueing or mutating the same workflow.', 'plugin-conflict-debugger' ),
			'commerce_checkout'      => __( 'Reproduce the issue on the affected cart, checkout, or product flow in staging and test one commerce customization layer at a time.', 'plugin-conflict-debugger' ),
		);

		return $suggestions[ $surface_key ] ?? __( 'Reproduce the affected request in staging first, then test one owner of the shared resource at a time.', 'plugin-conflict-debugger' );
	}

	/**
	 * Checks whether evidence items contain a tier.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @param string                           $tier Tier label.
	 * @return bool
	 */
	private function has_tier( array $evidence_items, string $tier ): bool {
		$tier = $this->normalize_tier( $tier );

		foreach ( $evidence_items as $evidence_item ) {
			if ( $tier === $this->normalize_tier( (string) ( $evidence_item['tier'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the effective weight for one evidence item.
	 *
	 * @param array<string, mixed> $evidence_item Evidence item.
	 * @return int
	 */
	private function weight_for_evidence_item( array $evidence_item ): int {
		$signal_key        = (string) ( $evidence_item['signal_key'] ?? '' );
		$execution_surface = strtolower( (string) ( $evidence_item['execution_surface'] ?? '' ) );
		$base_weight       = (int) ( $this->weights[ $signal_key ] ?? 0 );

		if ( '' === $execution_surface ) {
			return $base_weight;
		}

		$hook_profile = $this->hook_profile( $execution_surface );

		if ( 'surface_hook_overlap' === $signal_key ) {
			return (int) $hook_profile['overlap_weight'];
		}

		if ( 'extreme_priority' === $signal_key ) {
			return (int) $hook_profile['priority_weight'];
		}

		if ( 'surface_context_match' === $signal_key && in_array( (string) $hook_profile['risk'], array( 'noise', 'noise_minus' ), true ) ) {
			return min( $base_weight, 5 );
		}

		return $base_weight;
	}

	/**
	 * Returns whether evidence contains direct interference signals.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return bool
	 */
	private function has_direct_interference_signal( array $evidence_items ): bool {
		$signal_keys = array(
			'rest_route_overlap',
			'ajax_action_overlap',
			'routing_overlap',
			'content_model_overlap',
			'direct_callback_mutation',
			'asset_state_mutation',
		);

		foreach ( $evidence_items as $evidence_item ) {
			if ( in_array( sanitize_key( (string) ( $evidence_item['signal_key'] ?? '' ) ), $signal_keys, true ) ) {
				return true;
			}
		}

		return false;
	}
}
