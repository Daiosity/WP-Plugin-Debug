<?php
/**
 * Conflict-surface based heuristic detector.
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

final class ConflictDetector {
	/**
	 * Conflict surface definitions.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $surfaces;

	/**
	 * Light internal risk patterns.
	 *
	 * @var array<string, string[]>
	 */
	private array $known_risk_rules = array(
		'woocommerce' => array( 'checkout', 'cart', 'shipping', 'payment' ),
		'elementor'   => array( 'widget', 'template', 'popup' ),
		'security'    => array( 'firewall', 'captcha', 'login' ),
		'forms'       => array( 'validation', 'submit', 'spam' ),
	);

	/**
	 * Heuristics service.
	 *
	 * @var Heuristics
	 */
	private Heuristics $heuristics;

	/**
	 * Registry snapshot collector.
	 *
	 * @var RegistrySnapshot
	 */
	private RegistrySnapshot $registry;

	/**
	 * Constructor.
	 *
	 * @param Heuristics $heuristics Heuristic scorer.
	 */
	public function __construct( Heuristics $heuristics, RegistrySnapshot $registry ) {
		$this->heuristics = $heuristics;
		$this->registry   = $registry;
	}

	/**
	 * Detects likely conflicts.
	 *
	 * @param array<int, array<string, mixed>> $plugins Active plugins.
	 * @param array<string, mixed>              $error_signals Error signals.
	 * @param array<string, mixed>              $environment Environment snapshot.
	 * @return array<int, array<string, mixed>>
	 */
	public function detect( array $plugins, array $error_signals, array $environment ): array {
		$this->ensure_surface_definitions();

		$findings          = array();
		$error_entries     = is_array( $error_signals['entries'] ?? null ) ? $error_signals['entries'] : array();
		$plugin_index      = $this->index_plugins_by_slug( $plugins );
		$hook_analyses     = $this->analyze_hook_surface_overlaps();
		$asset_analyses    = $this->analyze_asset_surface_overlaps();
		$rest_analyses     = $this->analyze_rest_surface_overlaps();
		$cron_analyses     = $this->analyze_cron_surface_overlaps( $plugins );
		$registry_analyses = $this->analyze_registry_surface_overlaps();
		$mutation_analyses = $this->analyze_runtime_mutation_entries( $error_entries );
		$pattern_groups    = $this->collect_repeated_pattern_groups( $error_entries, $plugin_index );

		for ( $i = 0, $plugin_count = count( $plugins ); $i < $plugin_count; $i++ ) {
			for ( $j = $i + 1; $j < $plugin_count; $j++ ) {
				$plugin_a = $plugins[ $i ];
				$plugin_b = $plugins[ $j ];
				$pair_key = $this->build_pair_key( (string) $plugin_a['slug'], (string) $plugin_b['slug'] );
				$observer_pattern = $this->find_repeated_pattern_group_for_pair(
					$pattern_groups,
					sanitize_key( (string) $plugin_a['slug'] ),
					sanitize_key( (string) $plugin_b['slug'] )
				);
				$observer_involved = $this->is_observer_plugin( $plugin_a ) || $this->is_observer_plugin( $plugin_b );

				$surface_map = array();
				$this->merge_pair_analysis( $surface_map, $hook_analyses[ $pair_key ] ?? array() );
				$this->merge_pair_analysis( $surface_map, $asset_analyses[ $pair_key ] ?? array() );
				$this->merge_pair_analysis( $surface_map, $rest_analyses[ $pair_key ] ?? array() );
				$this->merge_pair_analysis( $surface_map, $cron_analyses[ $pair_key ] ?? array() );
				$this->merge_pair_analysis( $surface_map, $registry_analyses[ $pair_key ] ?? array() );
				$this->merge_pair_analysis( $surface_map, $mutation_analyses[ $pair_key ] ?? array() );

				$this->add_category_surface_overlap( $surface_map, $plugin_a, $plugin_b );
				$this->add_context_surface_overlap( $surface_map, $plugin_a, $plugin_b, $error_entries, $environment );

				$global_evidence  = array();
				$error_match      = $this->match_errors_to_plugins( $error_entries, $plugin_a, $plugin_b );
				$relationship     = $this->detect_extension_relationship( $plugin_a, $plugin_b );
				$known_risk       = $this->match_known_risk_patterns( $plugin_a, $plugin_b );
				$has_runtime_flag = ! empty( $error_match ) || ! empty( $rest_analyses[ $pair_key ] ) || ! empty( $asset_analyses[ $pair_key ] ) || ! empty( $registry_analyses[ $pair_key ] ) || ! empty( $mutation_analyses[ $pair_key ] );

				if ( ! empty( $error_match ) ) {
					$pair_specific_entries = array_values(
						array_filter(
							$error_match,
							static fn( array $entry ): bool => 'pair_specific_runtime_breakage' === (string) ( $entry['runtime_signal_type'] ?? '' )
						)
					);
					$observed_entry        = ! empty( $pair_specific_entries ) ? $pair_specific_entries[0] : $error_match[0];
					$observed_context      = sanitize_text_field( (string) ( $observed_entry['request_context'] ?? __( 'runtime', 'conflict-debugger' ) ) );
					$observed_resource     = $this->summarize_observed_resource( $observed_entry );
					$execution_surface     = $this->execution_surface_for_entry( $observed_entry );
					$runtime_signal_type   = sanitize_key( (string) ( $observed_entry['runtime_signal_type'] ?? 'generic_runtime_noise' ) );

					$global_evidence[] = $this->create_evidence_item(
						'generic',
						$runtime_signal_type,
						$this->format_observed_breakage_message( $observed_entry ),
						'pair_specific_runtime_breakage' === $runtime_signal_type ? 'strong' : 'context',
						array(
							'tier'              => 'pair_specific_runtime_breakage' === $runtime_signal_type ? 'runtime_breakage' : 'supporting',
							'request_context'   => '' !== $observed_context ? $observed_context : __( 'runtime', 'conflict-debugger' ),
							'shared_resource'   => 'pair_specific_runtime_breakage' === $runtime_signal_type ? $observed_resource : '',
							'execution_surface' => $execution_surface,
							'pair_specific'     => ! empty( $observed_entry['pair_specific'] ),
							'same_trace'        => ! empty( $observed_entry['same_trace'] ),
							'failure_mode'      => sanitize_key( (string) ( $observed_entry['failure_mode'] ?? '' ) ),
							'contaminated'      => ! empty( $observed_entry['contaminated'] ),
						)
					);

					if ( ! empty( $observed_entry['contaminated'] ) ) {
						$global_evidence[] = $this->create_evidence_item(
							'generic',
							'third_party_contamination',
							__( 'The runtime clue includes third-party screen or resource context, so it was treated as contaminated support instead of direct pair-specific proof.', 'conflict-debugger' ),
							'weak',
							array(
								'tier'              => 'noise',
								'request_context'   => '' !== $observed_context ? $observed_context : __( 'runtime', 'conflict-debugger' ),
								'execution_surface' => $execution_surface,
								'contaminated'      => true,
							)
						);
					}
				}

				if ( ! empty( $plugin_a['recently_changed'] ) || ! empty( $plugin_b['recently_changed'] ) ) {
					$global_evidence[] = $this->create_evidence_item(
						'generic',
						'recent_change',
						__( 'One or both plugins changed recently. That can help narrow the timeline, but it is not proof of a conflict by itself.', 'conflict-debugger' ),
						'weak',
						array(
							'tier'            => 'weak',
							'request_context' => __( 'runtime', 'conflict-debugger' ),
						)
					);
				}

				if ( $known_risk ) {
					$global_evidence[] = $this->create_evidence_item(
						'generic',
						'known_risk_pattern',
						$known_risk,
						'weak',
						array(
							'tier'            => 'weak',
							'request_context' => __( 'runtime', 'conflict-debugger' ),
						)
					);
				}

				if ( ! empty( $relationship['is_extension'] ) && ! $has_runtime_flag ) {
					continue;
				}

				$best_surface_key  = '';
				$best_surface_data = array();
				$best_score        = 0;
				$best_severity     = 'info';
				$best_category     = 'overlap';
				$best_evidence     = array();

				foreach ( $surface_map as $surface_key => $surface_data ) {
					if ( empty( $surface_data['evidence_items'] ) ) {
						continue;
					}

					$evidence_items = array_merge(
						(array) ( $surface_data['evidence_items'] ?? array() ),
						$global_evidence
					);

					if ( ! empty( $relationship['is_extension'] ) ) {
						$evidence_items[] = $this->create_evidence_item(
							(string) $surface_key,
							'extension_relationship',
							(string) $relationship['message'],
							'context',
							array(
								'tier'            => 'weak',
								'request_context' => $this->request_context_for_surface( (string) $surface_key ),
							)
						);
					}

					$evidence_items = $this->enrich_evidence_items( $evidence_items, (string) $surface_key );
					$evidence_items = $this->deduplicate_evidence_items( $evidence_items );
					$evidence_items = $this->select_primary_context_evidence( $evidence_items );
					$score          = $this->heuristics->score_evidence_items( $evidence_items );
					if ( ! empty( $relationship['is_extension'] ) ) {
						$score = max( 0, $score - 20 );
					}

					$severity = $this->heuristics->severity_for( $evidence_items, $score );
					$category = $this->heuristics->finding_type_for( $evidence_items );

					if (
						$score > $best_score ||
						(
							$score === $best_score &&
							$this->heuristics->severity_rank( $severity ) > $this->heuristics->severity_rank( $best_severity )
						)
					) {
						$best_score        = $score;
						$best_surface_key  = (string) $surface_key;
						$best_surface_data = $surface_data;
						$best_severity     = $severity;
						$best_category     = $category;
						$best_evidence     = $evidence_items;
					}
				}

				if ( ! $best_surface_key || $best_score < 15 ) {
					continue;
				}

				$has_pair_specific_causality = $this->has_pair_specific_causality( $best_evidence );

				if ( ! empty( $observer_pattern ) && ! $has_pair_specific_causality ) {
					continue;
				}

				list( $best_category, $best_severity, $best_score ) = $this->normalize_pairwise_classification(
					$best_category,
					$best_severity,
					$best_score,
					$best_evidence,
					$observer_involved
				);

				$surface_meta      = $this->surfaces[ $best_surface_key ] ?? array();
				$shared_resource   = $this->find_shared_resource( $best_evidence );
				$request_context   = $this->find_request_context( $best_evidence, $best_surface_key );
				$execution_surface = $this->find_execution_surface( $best_evidence );

				$evidence_breakdown = $this->heuristics->evidence_breakdown( $best_evidence );

				$findings[] = array(
					'primary_plugin'                    => $plugin_a['slug'],
					'primary_plugin_name'               => $plugin_a['name'],
					'secondary_plugin'                  => $plugin_b['slug'],
					'secondary_plugin_name'             => $plugin_b['name'],
					'issue_category'                    => $best_surface_key,
					'surface_key'                       => $best_surface_key,
					'surface_label'                     => (string) ( $surface_meta['label'] ?? $best_surface_key ),
					'affected_area'                     => (string) ( $surface_meta['affected_area'] ?? __( 'generic site behavior', 'conflict-debugger' ) ),
					'title'                             => $this->build_title( $plugin_a, $plugin_b, $best_category, $best_surface_key, $shared_resource, $request_context ),
					'severity'                          => $best_severity,
					'status'                            => $this->heuristics->ui_status_for( $best_severity ),
					'confidence'                        => $best_score,
					'category'                          => $best_category,
					'finding_type'                      => $best_category,
					'shared_resource'                   => $shared_resource,
					'execution_surface'                 => $execution_surface,
					'request_context'                   => $request_context,
					'evidence'                          => array_values( array_map( static fn( array $item ): string => (string) $item['message'], $best_evidence ) ),
					'evidence_items'                    => $best_evidence,
					'evidence_strength_breakdown'       => $evidence_breakdown,
					'explanation'                       => $this->build_explanation( $plugin_a, $plugin_b, $best_category, $best_surface_key, $shared_resource, $request_context, $execution_surface ),
					'why_scored_this_way'               => $this->heuristics->scoring_summary( $best_evidence, $best_score, $best_category, $best_severity ),
					'why_this_is_not_or_is_actionable'  => $this->build_actionability_note( $best_category, $shared_resource, $request_context, $execution_surface ),
					'recommended_next_step'             => $this->build_recommended_next_step( $best_surface_key, $request_context, $execution_surface, $shared_resource, $best_evidence ),
				);
			}
		}

		$findings = array_merge(
			$findings,
			$this->build_grouped_anomaly_findings( $pattern_groups, $plugin_index )
		);

		usort(
			$findings,
			fn( array $left, array $right ): int => $this->compare_findings( $left, $right )
		);

		return array_slice( $findings, 0, 25 );
	}

	/**
	 * Lazily initializes translated surface definitions after WordPress has booted.
	 *
	 * @return void
	 */
	private function ensure_surface_definitions(): void {
		if ( ! empty( $this->surfaces ) ) {
			return;
		}

		$this->surfaces = $this->get_surface_definitions();
	}

	/**
	 * Returns surface definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_surface_definitions(): array {
		return array(
			'frontend_rendering' => array(
				'label'         => __( 'Frontend Rendering', 'conflict-debugger' ),
				'affected_area' => __( 'frontend rendering', 'conflict-debugger' ),
				'signal_key'    => 'output_filter_overlap',
				'hooks'         => array( 'the_content', 'template_redirect', 'template_include', 'render_block', 'pre_do_shortcode_tag', 'do_shortcode_tag', 'wp_head', 'wp_footer' ),
				'hook_prefixes' => array(),
				'categories'    => array( 'page-builder' ),
				'context_terms' => array( 'shortcode', 'template', 'render', 'content', 'header', 'footer', 'wrapper' ),
			),
			'asset_loading' => array(
				'label'         => __( 'Asset Loading', 'conflict-debugger' ),
				'affected_area' => __( 'asset loading', 'conflict-debugger' ),
				'signal_key'    => 'surface_hook_overlap',
				'hooks'         => array( 'wp_enqueue_scripts', 'wp_print_scripts', 'wp_print_styles', 'script_loader_tag', 'style_loader_tag', 'wp_default_scripts', 'wp_default_styles' ),
				'hook_prefixes' => array(),
				'categories'    => array( 'optimization', 'page-builder' ),
				'context_terms' => array( 'script', 'style', 'asset', 'dependency', 'enqueue', 'lazy', 'defer', 'delay' ),
			),
			'admin_screen' => array(
				'label'         => __( 'Admin Screen', 'conflict-debugger' ),
				'affected_area' => __( 'admin settings', 'conflict-debugger' ),
				'signal_key'    => 'admin_screen_overlap',
				'hooks'         => array( 'admin_menu', 'admin_init', 'current_screen', 'admin_enqueue_scripts' ),
				'hook_prefixes' => array(
					/* translators: %s: admin page load hook name. */
					array( 'prefix' => 'load-', 'signal_key' => 'admin_screen_overlap', 'template' => __( 'Both plugins attach to the same admin page load hook: %s.', 'conflict-debugger' ) ),
					/* translators: %s: admin postback hook name. */
					array( 'prefix' => 'admin_post_', 'signal_key' => 'admin_screen_overlap', 'template' => __( 'Both plugins attach to the same admin save or postback action: %s.', 'conflict-debugger' ) ),
				),
				'categories'    => array( 'admin-tools' ),
				'context_terms' => array( 'settings', 'admin', 'options', 'screen', 'save settings', 'tools page', 'menu slug' ),
			),
			'editor' => array(
				'label'         => __( 'Editor', 'conflict-debugger' ),
				'affected_area' => __( 'editor', 'conflict-debugger' ),
				'signal_key'    => 'editor_overlap',
				'hooks'         => array( 'enqueue_block_editor_assets', 'add_meta_boxes', 'save_post', 'quicktags_settings', 'mce_buttons', 'block_editor_settings_all', 'use_block_editor_for_post' ),
				'hook_prefixes' => array(),
				'categories'    => array( 'editor', 'page-builder' ),
				'context_terms' => array( 'editor', 'block', 'metabox', 'classic editor', 'serialization', 'gutenberg' ),
			),
			'authentication_account' => array(
				'label'         => __( 'Authentication / Account', 'conflict-debugger' ),
				'affected_area' => __( 'login', 'conflict-debugger' ),
				'signal_key'    => 'auth_overlap',
				'hooks'         => array( 'login_init', 'authenticate', 'login_redirect', 'registration_redirect', 'register_post', 'user_register', 'profile_update', 'set_auth_cookie' ),
				'hook_prefixes' => array(),
				'categories'    => array( 'authentication', 'membership', 'security' ),
				'context_terms' => array( 'login', 'register', 'profile', 'session', 'mfa', 'redirect', 'auth' ),
			),
			'rest_api_ajax' => array(
				'label'         => __( 'REST API / AJAX', 'conflict-debugger' ),
				'affected_area' => __( 'API/AJAX', 'conflict-debugger' ),
				'signal_key'    => 'surface_hook_overlap',
				'hooks'         => array( 'rest_api_init' ),
				'hook_prefixes' => array(
					/* translators: %s: authenticated AJAX action name. */
					array( 'prefix' => 'wp_ajax_', 'signal_key' => 'ajax_action_overlap', 'template' => __( 'Both plugins register the same authenticated AJAX action: %s.', 'conflict-debugger' ) ),
					/* translators: %s: public AJAX action name. */
					array( 'prefix' => 'wp_ajax_nopriv_', 'signal_key' => 'ajax_action_overlap', 'template' => __( 'Both plugins register the same public AJAX action: %s.', 'conflict-debugger' ) ),
				),
				'categories'    => array( 'api' ),
				'context_terms' => array( 'rest', 'ajax', 'nonce', 'endpoint', 'api', 'request' ),
			),
			'forms_submission' => array(
				'label'         => __( 'Forms / Submission Workflows', 'conflict-debugger' ),
				'affected_area' => __( 'forms', 'conflict-debugger' ),
				'signal_key'    => 'surface_hook_overlap',
				'hooks'         => array( 'template_redirect', 'init' ),
				'hook_prefixes' => array(
					/* translators: %s: admin postback hook name. */
					array( 'prefix' => 'admin_post_', 'signal_key' => 'exact_hook_collision', 'template' => __( 'Both plugins appear to process the same submit/postback action: %s.', 'conflict-debugger' ) ),
				),
				'categories'    => array( 'forms' ),
				'context_terms' => array( 'form', 'submit', 'validation', 'captcha', 'spam', 'processing' ),
			),
			'caching_optimization' => array(
				'label'         => __( 'Caching / Optimization', 'conflict-debugger' ),
				'affected_area' => __( 'caching', 'conflict-debugger' ),
				'signal_key'    => 'optimization_stack_overlap',
				'hooks'         => array( 'template_redirect', 'shutdown', 'script_loader_tag', 'style_loader_tag' ),
				'hook_prefixes' => array(),
				'categories'    => array( 'cache', 'optimization' ),
				'context_terms' => array( 'cache', 'minify', 'defer', 'delay', 'lazy', 'buffer', 'optimization' ),
			),
			'seo_metadata' => array(
				'label'         => __( 'SEO / Metadata', 'conflict-debugger' ),
				'affected_area' => __( 'SEO', 'conflict-debugger' ),
				'signal_key'    => 'seo_overlap',
				'hooks'         => array( 'wp_head', 'pre_get_document_title', 'document_title_parts', 'wp_sitemaps_init', 'do_robots', 'redirect_canonical' ),
				'hook_prefixes' => array(),
				'categories'    => array( 'seo', 'schema' ),
				'context_terms' => array( 'meta', 'schema', 'canonical', 'robots', 'sitemap', 'og:' ),
			),
			'rewrite_routing' => array(
				'label'         => __( 'Rewrite / Routing', 'conflict-debugger' ),
				'affected_area' => __( 'routing', 'conflict-debugger' ),
				'signal_key'    => 'routing_overlap',
				'hooks'         => array( 'init', 'parse_request', 'request', 'query_vars', 'template_include', 'rewrite_rules_array' ),
				'hook_prefixes' => array(),
				'categories'    => array( 'content-model', 'api' ),
				'context_terms' => array( 'rewrite', 'route', 'endpoint', 'permalink', 'query var', 'template routing' ),
			),
			'content_model' => array(
				'label'         => __( 'Content Model', 'conflict-debugger' ),
				'affected_area' => __( 'content model', 'conflict-debugger' ),
				'signal_key'    => 'content_model_overlap',
				'hooks'         => array( 'init', 'registered_post_type', 'registered_taxonomy' ),
				'hook_prefixes' => array(),
				'categories'    => array( 'content-model' ),
				'context_terms' => array( 'post type', 'taxonomy', 'custom post', 'cpt', 'rewrite slug', 'content model' ),
			),
			'email_notifications' => array(
				'label'         => __( 'Email / Notifications', 'conflict-debugger' ),
				'affected_area' => __( 'notifications', 'conflict-debugger' ),
				'signal_key'    => 'email_overlap',
				'hooks'         => array( 'phpmailer_init', 'wp_mail', 'transition_post_status', 'comment_post' ),
				'hook_prefixes' => array(),
				'categories'    => array( 'email' ),
				'context_terms' => array( 'mail', 'email', 'smtp', 'notification', 'delivery' ),
			),
			'security_access' => array(
				'label'         => __( 'Security / Access Control', 'conflict-debugger' ),
				'affected_area' => __( 'security', 'conflict-debugger' ),
				'signal_key'    => 'security_overlap',
				'hooks'         => array( 'authenticate', 'rest_authentication_errors', 'login_init', 'admin_init', 'template_redirect' ),
				'hook_prefixes' => array(),
				'categories'    => array( 'security', 'authentication' ),
				'context_terms' => array( 'firewall', 'blocked', 'permission', 'capability', 'access denied', 'lockout' ),
			),
			'background_processing' => array(
				'label'         => __( 'Cron / Background Jobs', 'conflict-debugger' ),
				'affected_area' => __( 'background processing', 'conflict-debugger' ),
				'signal_key'    => 'background_overlap',
				'hooks'         => array( 'shutdown' ),
				'hook_prefixes' => array(),
				'categories'    => array( 'backup', 'optimization' ),
				'context_terms' => array( 'cron', 'queue', 'background', 'scheduled', 'job', 'worker' ),
			),
			'commerce_checkout' => array(
				'label'         => __( 'Commerce / Checkout', 'conflict-debugger' ),
				'affected_area' => __( 'forms', 'conflict-debugger' ),
				'signal_key'    => 'surface_hook_overlap',
				'hooks'         => array( 'woocommerce_before_calculate_totals', 'woocommerce_checkout_process', 'woocommerce_cart_loaded_from_session' ),
				'hook_prefixes' => array(),
				'categories'    => array( 'ecommerce', 'checkout' ),
				'context_terms' => array( 'woocommerce', 'checkout', 'cart', 'order', 'payment' ),
			),
		);
	}

	/**
	 * Analyzes explicit hook collisions by surface.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function analyze_hook_surface_overlaps(): array {
		global $wp_filter;

		if ( ! is_array( $wp_filter ) && ! $wp_filter instanceof \ArrayAccess ) {
			return array();
		}

		$results   = array();
		$hook_keys = array_keys( (array) $wp_filter );
		/* translators: %s: WordPress hook name. */
		$default_surface_template = __( 'Both plugins attach to the %s hook.', 'conflict-debugger' );

		foreach ( $this->surfaces as $surface_key => $surface ) {
			$matched_hooks = array();

			foreach ( (array) $surface['hooks'] as $hook_name ) {
				if ( isset( $wp_filter[ $hook_name ] ) && $wp_filter[ $hook_name ] instanceof \WP_Hook ) {
					$matched_hooks[] = array(
						'name'       => (string) $hook_name,
						'signal_key' => 'surface_hook_overlap',
						'template'   => $default_surface_template,
						'dynamic'    => false,
					);
				}
			}

			foreach ( (array) $surface['hook_prefixes'] as $prefix_config ) {
				$prefix   = (string) ( $prefix_config['prefix'] ?? '' );
				$template = (string) ( $prefix_config['template'] ?? $default_surface_template );
				$signal   = (string) ( $prefix_config['signal_key'] ?? $surface['signal_key'] );

				foreach ( $hook_keys as $hook_name ) {
					if ( 0 === strpos( (string) $hook_name, $prefix ) && $wp_filter[ $hook_name ] instanceof \WP_Hook ) {
						$matched_hooks[] = array(
							'name'       => (string) $hook_name,
							'signal_key' => $signal,
							'template'   => $template,
							'dynamic'    => true,
						);
					}
				}
			}

			foreach ( $matched_hooks as $matched_hook ) {
				$hook_name        = (string) $matched_hook['name'];
				$plugin_callbacks = array();
				$extreme_priority = false;

				foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
					if ( $priority <= 1 || $priority >= 999 ) {
						$extreme_priority = true;
					}

					foreach ( $callbacks as $callback ) {
						$slug = $this->resolve_plugin_slug_from_callback( $callback['function'] ?? null );
						if ( ! $slug ) {
							continue;
						}

						if ( ! isset( $plugin_callbacks[ $slug ] ) ) {
							$plugin_callbacks[ $slug ] = 0;
						}

						$plugin_callbacks[ $slug ]++;
					}
				}

				if ( count( $plugin_callbacks ) < 2 ) {
					continue;
				}

				$slugs = array_keys( $plugin_callbacks );
				for ( $i = 0, $count = count( $slugs ); $i < $count; $i++ ) {
					for ( $j = $i + 1; $j < $count; $j++ ) {
						$slug_a = $slugs[ $i ];
						$slug_b = $slugs[ $j ];
						$total  = $plugin_callbacks[ $slug_a ] + $plugin_callbacks[ $slug_b ];

						if ( $total < ( ! empty( $matched_hook['dynamic'] ) ? 2 : 3 ) ) {
							continue;
						}

						$pair_key = $this->build_pair_key( $slug_a, $slug_b );
						$hook_profile      = $this->heuristics->hook_profile( $hook_name );
						$hook_risk         = (string) ( $hook_profile['risk'] ?? 'noise' );
						$is_exact_surface  = in_array( (string) $matched_hook['signal_key'], array( 'ajax_action_overlap', 'exact_hook_collision' ), true );
						$evidence_tier     = $is_exact_surface ? 'strong_proof' : ( in_array( $hook_risk, array( 'supporting', 'strong_capable' ), true ) ? 'supporting' : 'noise' );
						$evidence_strength = 'strong_proof' === $evidence_tier ? 'concrete' : ( 'supporting' === $evidence_tier ? 'context' : 'weak' );

						$this->add_analysis_item(
							$results,
							$pair_key,
							(string) $surface_key,
							(string) $matched_hook['signal_key'],
							sprintf( (string) $matched_hook['template'], $hook_name ),
							$evidence_strength,
							array(
								'tier'              => $evidence_tier,
								'request_context'   => $this->request_context_for_surface( (string) $surface_key ),
								'shared_resource'   => $is_exact_surface ? $hook_name : '',
								'execution_surface' => $hook_name,
							)
						);

						if ( $extreme_priority ) {
							$this->add_analysis_item(
								$results,
								$pair_key,
								(string) $surface_key,
								'extreme_priority',
								__( 'One or more callbacks on this surface run at extreme priorities, which can change load order in hard-to-debug ways.', 'conflict-debugger' ),
								'weak',
								array(
									'tier'              => 'noise',
									'request_context'   => $this->request_context_for_surface( (string) $surface_key ),
									'shared_resource'   => '',
									'execution_surface' => $hook_name,
								)
							);
						}
					}
				}
			}
		}

		return $results;
	}

	/**
	 * Detects asset loading overlap.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function analyze_asset_surface_overlaps(): array {
		$results = array();
		$assets  = array();
		$stores  = array( wp_scripts(), wp_styles() );

		foreach ( $stores as $dependency_store ) {
			if ( ! $dependency_store || empty( $dependency_store->registered ) ) {
				continue;
			}

			foreach ( $dependency_store->registered as $handle => $dependency ) {
				if ( empty( $dependency->src ) ) {
					continue;
				}

				$slug = $this->resolve_plugin_slug_from_path( (string) $dependency->src );
				if ( ! $slug ) {
					continue;
				}

				$family = $this->classify_library( (string) $dependency->src, (string) $handle );
				if ( $family ) {
					$assets['families'][ $family ][ $slug ][] = (string) $handle;
				}

				$optimization_flag = $this->classify_optimization_asset( (string) $dependency->src, (string) $handle );
				if ( $optimization_flag ) {
					$assets['optimization'][ $optimization_flag ][ $slug ][] = (string) $handle;
				}
			}
		}

		foreach ( (array) ( $assets['families'] ?? array() ) as $family => $plugin_handles ) {
			$slugs = array_keys( $plugin_handles );
			for ( $i = 0, $count = count( $slugs ); $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$pair_key = $this->build_pair_key( $slugs[ $i ], $slugs[ $j ] );
					$this->add_analysis_item(
						$results,
						$pair_key,
						'asset_loading',
						'duplicate_assets',
						sprintf(
							/* translators: %s library family. */
							__( 'Both plugins register assets from the same library family: %s.', 'conflict-debugger' ),
							$family
						),
						'contextual',
						array(
							'request_context' => $this->request_context_for_surface( 'asset_loading' ),
							'shared_resource' => (string) $family,
						)
					);
				}
			}
		}

		foreach ( (array) ( $assets['optimization'] ?? array() ) as $flag => $plugin_handles ) {
			$slugs = array_keys( $plugin_handles );
			for ( $i = 0, $count = count( $slugs ); $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$pair_key = $this->build_pair_key( $slugs[ $i ], $slugs[ $j ] );
					$this->add_analysis_item(
						$results,
						$pair_key,
						'caching_optimization',
						'optimization_stack_overlap',
						sprintf(
							/* translators: %s optimization family. */
							__( 'Both plugins appear to modify the same optimization layer: %s.', 'conflict-debugger' ),
							$flag
						),
						'contextual',
						array(
							'request_context' => $this->request_context_for_surface( 'caching_optimization' ),
							'shared_resource' => (string) $flag,
						)
					);
				}
			}
		}

		return $results;
	}

	/**
	 * Detects REST route overlap.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function analyze_rest_surface_overlaps(): array {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return array();
		}

		$results = array();
		$server  = rest_get_server();
		$routes  = method_exists( $server, 'get_routes' ) ? $server->get_routes() : array();

		foreach ( $routes as $route => $handlers ) {
			$plugin_slugs = array();

			foreach ( (array) $handlers as $handler ) {
				$slug = $this->resolve_plugin_slug_from_callback( $handler['callback'] ?? null );
				if ( $slug ) {
					$plugin_slugs[ $slug ] = true;
				}
			}

			$slugs = array_keys( $plugin_slugs );
			if ( count( $slugs ) < 2 ) {
				continue;
			}

			for ( $i = 0, $count = count( $slugs ); $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$pair_key = $this->build_pair_key( $slugs[ $i ], $slugs[ $j ] );
					$this->add_analysis_item(
						$results,
						$pair_key,
						'rest_api_ajax',
						'rest_route_overlap',
						sprintf(
							/* translators: %s REST route. */
							__( 'Both plugins register callbacks against the same REST route family: %s.', 'conflict-debugger' ),
							$route
						),
						'concrete',
						array(
							'tier'            => 'concrete',
							'request_context' => $this->request_context_for_surface( 'rest_api_ajax' ),
							'shared_resource' => (string) $route,
						)
					);
				}
			}
		}

		return $results;
	}

	/**
	 * Detects broad background job overlap from cron hooks.
	 *
	 * @param array<int, array<string, mixed>> $plugins Active plugins.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function analyze_cron_surface_overlaps( array $plugins ): array {
		if ( ! function_exists( '_get_cron_array' ) ) {
			return array();
		}

		$results       = array();
		$cron_array    = _get_cron_array();
		$plugin_tokens = array();
		$families      = array( 'cache', 'cleanup', 'sync', 'queue', 'email', 'notify', 'backup', 'import', 'export', 'index', 'process' );

		foreach ( $plugins as $plugin ) {
			$slug                   = strtolower( (string) ( $plugin['slug'] ?? '' ) );
			$plugin_tokens[ $slug ] = preg_split( '/[-_]/', $slug ) ?: array( $slug );
		}

		foreach ( (array) $cron_array as $events ) {
			foreach ( (array) $events as $hook_name => $instances ) {
				unset( $instances );
				$matched_plugins = array();

				foreach ( $plugin_tokens as $slug => $tokens ) {
					foreach ( $tokens as $token ) {
						if ( $token && strlen( (string) $token ) >= 4 && false !== strpos( strtolower( (string) $hook_name ), strtolower( (string) $token ) ) ) {
							$matched_plugins[ $slug ] = true;
						}
					}
				}

				if ( count( $matched_plugins ) < 2 ) {
					continue;
				}

				$family_name = __( 'scheduled processing', 'conflict-debugger' );
				foreach ( $families as $family ) {
					if ( false !== strpos( strtolower( (string) $hook_name ), $family ) ) {
						$family_name = $family;
						break;
					}
				}

				$slugs = array_keys( $matched_plugins );
				for ( $i = 0, $count = count( $slugs ); $i < $count; $i++ ) {
					for ( $j = $i + 1; $j < $count; $j++ ) {
						$pair_key = $this->build_pair_key( $slugs[ $i ], $slugs[ $j ] );
						$this->add_analysis_item(
							$results,
							$pair_key,
							'background_processing',
							'cron_overlap',
							sprintf(
								/* translators: 1: cron hook, 2: family. */
								__( 'Both plugins appear to schedule similar background work around %1$s (%2$s).', 'conflict-debugger' ),
								$hook_name,
								$family_name
							),
							'contextual',
							array(
								'request_context' => $this->request_context_for_surface( 'background_processing' ),
								'shared_resource' => (string) $hook_name,
							)
						);
					}
				}
			}
		}

		return $results;
	}

	/**
	 * Detects exact registry collisions from post types, taxonomies, rewrites, and admin menus.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function analyze_registry_surface_overlaps(): array {
		$results               = array();
		$post_type_regs        = $this->registry->get_post_type_registrations();
		$taxonomy_regs         = $this->registry->get_taxonomy_registrations();
		$admin_menu_snapshot   = $this->registry->get_admin_menu_snapshot();
		$rewrite_slug_registry = array();
		$rest_base_registry    = array();
		$query_var_registry    = array();

		foreach ( $post_type_regs as $post_type => $registrations ) {
			$this->add_exact_registry_pairs(
				$results,
				$registrations,
				'content_model',
				'content_model_overlap',
				(string) $post_type,
				sprintf(
					/* translators: %s post type key. */
					__( 'Both plugins register the same custom post type key: %s.', 'conflict-debugger' ),
					$post_type
				)
			);

			foreach ( $registrations as $registration ) {
				if ( ! empty( $registration['rewrite_slug'] ) ) {
					$rewrite_slug_registry[ (string) $registration['rewrite_slug'] ][] = $registration;
				}
				if ( ! empty( $registration['rest_base'] ) ) {
					$rest_base_registry[ (string) $registration['rest_base'] ][] = $registration;
				}
				if ( ! empty( $registration['query_var'] ) ) {
					$query_var_registry[ (string) $registration['query_var'] ][] = $registration;
				}
			}
		}

		foreach ( $taxonomy_regs as $taxonomy => $registrations ) {
			$this->add_exact_registry_pairs(
				$results,
				$registrations,
				'content_model',
				'content_model_overlap',
				(string) $taxonomy,
				sprintf(
					/* translators: %s taxonomy key. */
					__( 'Both plugins register the same taxonomy key: %s.', 'conflict-debugger' ),
					$taxonomy
				)
			);

			foreach ( $registrations as $registration ) {
				if ( ! empty( $registration['rewrite_slug'] ) ) {
					$rewrite_slug_registry[ (string) $registration['rewrite_slug'] ][] = $registration;
				}
				if ( ! empty( $registration['rest_base'] ) ) {
					$rest_base_registry[ (string) $registration['rest_base'] ][] = $registration;
				}
				if ( ! empty( $registration['query_var'] ) ) {
					$query_var_registry[ (string) $registration['query_var'] ][] = $registration;
				}
			}
		}

		foreach ( $rewrite_slug_registry as $rewrite_slug => $registrations ) {
			$this->add_exact_registry_pairs(
				$results,
				$registrations,
				'rewrite_routing',
				'routing_overlap',
				(string) $rewrite_slug,
				sprintf(
					/* translators: %s rewrite slug. */
					__( 'Both plugins register the same rewrite slug: %s.', 'conflict-debugger' ),
					$rewrite_slug
				)
			);
		}

		foreach ( $rest_base_registry as $rest_base => $registrations ) {
			$this->add_exact_registry_pairs(
				$results,
				$registrations,
				'rest_api_ajax',
				'rest_route_overlap',
				(string) $rest_base,
				sprintf(
					/* translators: %s REST base. */
					__( 'Both plugins expose the same REST base or API content base: %s.', 'conflict-debugger' ),
					$rest_base
				)
			);
		}

		foreach ( $query_var_registry as $query_var => $registrations ) {
			$this->add_exact_registry_pairs(
				$results,
				$registrations,
				'rewrite_routing',
				'routing_overlap',
				(string) $query_var,
				sprintf(
					/* translators: %s query var. */
					__( 'Both plugins rely on the same query var: %s.', 'conflict-debugger' ),
					$query_var
				)
			);
		}

		$menu_slug_registry = array();
		foreach ( $admin_menu_snapshot as $menu_item ) {
			if ( empty( $menu_item['slug'] ) || empty( $menu_item['owner_slug'] ) ) {
				continue;
			}

			$menu_slug_registry[ (string) $menu_item['slug'] ][] = $menu_item;
		}

		foreach ( $menu_slug_registry as $menu_slug => $menu_items ) {
			$this->add_exact_registry_pairs(
				$results,
				$menu_items,
				'admin_screen',
				'admin_screen_overlap',
				(string) $menu_slug,
				sprintf(
					/* translators: %s menu slug. */
					__( 'Both plugins register the same admin menu or page slug: %s.', 'conflict-debugger' ),
					$menu_slug
				)
			);
		}

		return $results;
	}

	/**
	 * Detects concrete runtime mutations captured during the request.
	 *
	 * @param array<int, array<string, mixed>> $entries Runtime entries.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function analyze_runtime_mutation_entries( array $entries ): array {
		$results = array();

		foreach ( $entries as $entry ) {
			$type         = sanitize_key( (string) ( $entry['type'] ?? '' ) );
			$mutation_kind = sanitize_key( (string) ( $entry['mutation_kind'] ?? '' ) );
			$actor_slug   = sanitize_key( (string) ( $entry['actor_slug'] ?? '' ) );
			$target_owner = sanitize_key( (string) ( $entry['target_owner_slug'] ?? '' ) );
			$owner_slugs  = array_values(
				array_unique(
					array_filter(
						array_merge(
							array( $actor_slug, $target_owner ),
							array_map(
								static fn( $slug ): string => sanitize_key( (string) $slug ),
								is_array( $entry['owner_slugs'] ?? null ) ? $entry['owner_slugs'] : array()
							)
						)
					)
				)
			);

			if ( count( $owner_slugs ) < 2 ) {
				continue;
			}

			$is_asset_trace = in_array( $type, array( 'asset_queue_mutation', 'asset_registry_mutation', 'asset_lifecycle' ), true );
			$surface_key    = $is_asset_trace ? 'asset_loading' : $this->surface_for_context( (string) ( $entry['request_context'] ?? '' ) );
			$signal_key     = 'callback_mutation' === $type ? 'callback_chain_churn' : 'asset_state_mutation';
			$resource       = 'callback_mutation' === $type ? sanitize_text_field( (string) ( $entry['callback_identifier'] ?? '' ) ) : $this->summarize_observed_resource( $entry );
			$message      = sanitize_textarea_field( (string) ( $entry['message'] ?? '' ) );
			$request_ctx  = sanitize_text_field( (string) ( $entry['request_context'] ?? $this->request_context_for_surface( $surface_key ) ) );
			$execution_surface = $this->execution_surface_for_entry( $entry );
			$attribution_status = sanitize_key( (string) ( $entry['attribution_status'] ?? '' ) );
			$mutation_status    = sanitize_key( (string) ( $entry['mutation_status'] ?? '' ) );
			$tier               = 'callback_mutation' === $type ? 'contextual' : 'concrete';
			$strength           = 'callback_mutation' === $type ? 'context' : 'concrete';

			if ( in_array( $mutation_kind, array( 'callback_removed', 'callback_replaced' ), true ) && '' !== $actor_slug && '' !== $target_owner && in_array( $attribution_status, array( TraceEvent::ATTRIBUTION_DIRECT, TraceEvent::ATTRIBUTION_PARTIAL ), true ) ) {
				$signal_key = 'direct_callback_mutation';
				$tier       = 'concrete';
				$strength   = 'concrete';
			} elseif ( 'callback_priority_changed' === $mutation_kind ) {
				$signal_key = 'callback_order_sensitivity';
				$tier       = 'supporting';
				$strength   = 'context';
			} elseif ( 'asset_lifecycle' === $type && ! in_array( $attribution_status, array( 'attribution_direct', 'attribution_partial' ), true ) ) {
				$tier     = 'supporting';
				$strength = 'context';
			} elseif ( 'asset_lifecycle' === $type && ! in_array( $mutation_status, array( 'mutation_observed', 'mutation_confirmed' ), true ) ) {
				$tier     = 'supporting';
				$strength = 'context';
			}

			for ( $i = 0, $count = count( $owner_slugs ); $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$this->add_analysis_item(
						$results,
						$this->build_pair_key( $owner_slugs[ $i ], $owner_slugs[ $j ] ),
						$surface_key,
						$signal_key,
						'' !== $message ? $message : __( 'Observed runtime callback or asset mutation involving both plugins.', 'conflict-debugger' ),
						$strength,
						array(
							'tier'            => $tier,
							'request_context' => $request_ctx,
							'shared_resource' => $resource,
							'execution_surface' => $execution_surface,
							'pair_specific'   => '' !== $actor_slug && '' !== $target_owner,
							'attribution_status' => $attribution_status,
							'mutation_status' => $mutation_status,
						)
					);
				}
			}
		}

		return $results;
	}

	/**
	 * Adds pair findings from exact registry entries with distinct owners.
	 *
	 * Exact registry collisions should score as strong evidence because the
	 * plugins are competing for the same key or route, not merely sharing a
	 * general category.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $results Analysis results.
	 * @param array<int, array<string, mixed>>                   $registrations Registry entries.
	 * @param string                                             $surface_key Surface key.
	 * @param string                                             $signal_key Signal key.
	 * @param string                                             $shared_resource Shared resource.
	 * @param string                                             $message Evidence message.
	 * @return void
	 */
	private function add_exact_registry_pairs( array &$results, array $registrations, string $surface_key, string $signal_key, string $shared_resource, string $message ): void {
		$owners = array();

		foreach ( $registrations as $registration ) {
			$owner_slug = sanitize_key( (string) ( $registration['owner_slug'] ?? '' ) );
			if ( '' === $owner_slug ) {
				continue;
			}

			if ( ! isset( $owners[ $owner_slug ] ) ) {
				$owners[ $owner_slug ] = $registration;
			}
		}

		if ( count( $owners ) < 2 ) {
			return;
		}

		$owner_slugs = array_keys( $owners );

		for ( $i = 0, $count = count( $owner_slugs ); $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$pair_key = $this->build_pair_key( $owner_slugs[ $i ], $owner_slugs[ $j ] );
				$this->add_analysis_item(
					$results,
					$pair_key,
					$surface_key,
					$signal_key,
					$message,
					'concrete',
					array(
						'tier'            => 'concrete',
						'request_context' => $this->request_context_for_surface( $surface_key ),
						'shared_resource' => $shared_resource,
					)
				);
			}
		}
	}

	/**
	 * Adds category-based evidence to surfaces.
	 *
	 * @param array<string, array<string, mixed>> $surface_map Surface map.
	 * @param array<string, mixed>                $plugin_a Plugin A.
	 * @param array<string, mixed>                $plugin_b Plugin B.
	 * @return void
	 */
	private function add_category_surface_overlap( array &$surface_map, array $plugin_a, array $plugin_b ): void {
		foreach ( $this->surfaces as $surface_key => $surface ) {
			$categories = (array) ( $surface['categories'] ?? array() );
			if ( empty( $categories ) ) {
				continue;
			}

			$matches_a = array_intersect( (array) ( $plugin_a['categories'] ?? array() ), $categories );
			$matches_b = array_intersect( (array) ( $plugin_b['categories'] ?? array() ), $categories );

			if ( empty( $matches_a ) || empty( $matches_b ) ) {
				continue;
			}

			$this->add_surface_evidence(
				$surface_map,
				(string) $surface_key,
				'surface_category_match',
				sprintf(
					/* translators: 1: plugin name, 2: plugin name, 3: surface label. */
					__( '%1$s and %2$s both operate in the %3$s surface.', 'conflict-debugger' ),
					(string) $plugin_a['name'],
					(string) $plugin_b['name'],
					(string) $surface['label']
				),
				'weak',
				array(
					'tier'            => 'weak',
					'request_context' => $this->request_context_for_surface( (string) $surface_key ),
				)
			);
		}
	}

	/**
	 * Adds context-based evidence from errors and environment clues.
	 *
	 * @param array<string, array<string, mixed>> $surface_map Surface map.
	 * @param array<string, mixed>                $plugin_a Plugin A.
	 * @param array<string, mixed>                $plugin_b Plugin B.
	 * @param array<int, array<string, mixed>>    $error_entries Error entries.
	 * @param array<string, mixed>                $environment Environment snapshot.
	 * @return void
	 */
	private function add_context_surface_overlap( array &$surface_map, array $plugin_a, array $plugin_b, array $error_entries, array $environment ): void {
		foreach ( $this->surfaces as $surface_key => $surface ) {
			$matched_terms   = array();
			$request_context = strtolower( $this->request_context_for_surface( (string) $surface_key ) );

			foreach ( $error_entries as $entry ) {
				$entry_context = strtolower( sanitize_text_field( (string) ( $entry['request_context'] ?? '' ) ) );
				if ( '' !== $request_context && '' !== $entry_context && false === strpos( $entry_context, $request_context ) ) {
					continue;
				}

				$haystack = strtolower(
					implode(
						' ',
						array(
							(string) ( $entry['message'] ?? '' ),
							(string) ( $entry['request_uri'] ?? '' ),
							(string) ( $entry['resource'] ?? '' ),
							(string) ( $entry['execution_surface'] ?? '' ),
						)
					)
				);

				foreach ( (array) ( $surface['context_terms'] ?? array() ) as $term ) {
					if ( false !== strpos( $haystack, strtolower( (string) $term ) ) ) {
						$matched_terms[] = (string) $term;
					}
				}
			}

			if ( count( array_unique( $matched_terms ) ) < 2 ) {
				continue;
			}

			$this->add_surface_evidence(
				$surface_map,
				(string) $surface_key,
				'surface_context_match',
				sprintf(
					/* translators: 1: surface label, 2: matched terms. */
					__( 'Runtime clues align with the %1$s surface (%2$s).', 'conflict-debugger' ),
					(string) $surface['label'],
					implode( ', ', array_slice( array_unique( $matched_terms ), 0, 4 ) )
				),
				'context',
				array(
					'tier'            => 'supporting',
					'request_context' => $this->request_context_for_surface( (string) $surface_key ),
				)
			);
		}
	}

	/**
	 * Detects likely parent/addon plugin relationships to reduce false positives.
	 *
	 * @param array<string, mixed> $plugin_a Plugin A.
	 * @param array<string, mixed> $plugin_b Plugin B.
	 * @return array<string, mixed>
	 */
	private function detect_extension_relationship( array $plugin_a, array $plugin_b ): array {
		$slug_a = strtolower( (string) ( $plugin_a['slug'] ?? '' ) );
		$slug_b = strtolower( (string) ( $plugin_b['slug'] ?? '' ) );
		$name_a = strtolower( (string) ( $plugin_a['name'] ?? '' ) );
		$name_b = strtolower( (string) ( $plugin_b['name'] ?? '' ) );

		$has_dependency_keywords = $this->contains_addon_keyword( $name_a ) || $this->contains_addon_keyword( $name_b ) || $this->contains_addon_keyword( $slug_a ) || $this->contains_addon_keyword( $slug_b );
		$mentions_other         = ( $slug_a && false !== strpos( $name_b, $slug_a ) ) || ( $slug_b && false !== strpos( $name_a, $slug_b ) );
		$shared_prefix          = $this->shares_slug_prefix( $slug_a, $slug_b );

		if ( ( $has_dependency_keywords && $mentions_other ) || ( $has_dependency_keywords && $shared_prefix ) ) {
			return array(
				'is_extension' => true,
				'message'      => __( 'These plugins look like a parent/addon pair. Shared hooks and categories may be expected unless stronger runtime signals also correlate.', 'conflict-debugger' ),
			);
		}

		return array(
			'is_extension' => false,
			'message'      => '',
		);
	}

	/**
	 * Matches error text mentioning both plugins.
	 *
	 * @param array<int, array<string, mixed>> $entries Error entries.
	 * @param array<string, mixed>             $plugin_a Plugin A.
	 * @param array<string, mixed>             $plugin_b Plugin B.
	 * @return array<int, array<string, mixed>>
	 */
	private function match_errors_to_plugins( array $entries, array $plugin_a, array $plugin_b ): array {
		$matches = array();
		$slug_a  = sanitize_key( (string) ( $plugin_a['slug'] ?? '' ) );
		$slug_b  = sanitize_key( (string) ( $plugin_b['slug'] ?? '' ) );
		$observer_involved = $this->is_observer_plugin( $plugin_a ) || $this->is_observer_plugin( $plugin_b );
		$needles = array(
			$slug_a,
			$slug_b,
			sanitize_title( (string) $plugin_a['name'] ),
			sanitize_title( (string) $plugin_b['name'] ),
		);

		foreach ( $entries as $entry ) {
			$haystack = strtolower(
				implode(
					' ',
					array(
						(string) ( $entry['message'] ?? '' ),
						(string) ( $entry['file'] ?? '' ),
						(string) ( $entry['resource'] ?? '' ),
						(string) ( $entry['request_uri'] ?? '' ),
					)
				)
			);

			if ( ! $haystack ) {
				continue;
			}

			$matched = 0;
			foreach ( $needles as $needle ) {
				if ( $needle && false !== strpos( $haystack, strtolower( $needle ) ) ) {
					$matched++;
				}
			}

			$entry_owners = array_values(
				array_unique(
					array_filter(
						array_map(
							static fn( $slug ): string => sanitize_key( (string) $slug ),
							is_array( $entry['owner_slugs'] ?? null ) ? $entry['owner_slugs'] : array()
						)
					)
				)
			);

			$resource_match       = $this->entry_resource_hints_match_plugins( $entry, $slug_a, $slug_b );
			$owner_match          = in_array( $slug_a, $entry_owners, true ) && in_array( $slug_b, $entry_owners, true );
			$failure_mode         = $this->entry_failure_mode( $entry );
			$same_trace           = '' !== sanitize_text_field( (string) ( $entry['request_context'] ?? '' ) ) && ( $resource_match || $owner_match || $matched >= 2 );
			$pair_specific        = $resource_match || $this->entry_has_pair_specific_resource( $entry );
			$direct_pair_mutation = $this->entry_has_direct_pair_mutation( $entry, $slug_a, $slug_b );
			$contaminated         = $this->entry_is_third_party_contaminated( $entry, $slug_a, $slug_b, $owner_match, $resource_match );

			if ( ! $pair_specific && $owner_match && ! $this->entry_is_callback_timing_noise( $entry ) ) {
				$pair_specific = ! empty( $entry['callback_identifier'] ) || ! empty( $entry['execution_surface'] );
			}

			if ( $observer_involved && $this->entry_is_callback_timing_noise( $entry ) && ! $pair_specific ) {
				continue;
			}

			if ( '' === $failure_mode || ! $same_trace || ! $pair_specific ) {
				if ( '' === $failure_mode || ! $same_trace || $matched < 2 ) {
					continue;
				}
			}

			$is_pair_specific_runtime = ! $contaminated && $same_trace && ( $direct_pair_mutation || ( $pair_specific && $resource_match && $owner_match ) );

			if ( ! $is_pair_specific_runtime && $matched < 2 && ! $owner_match && ! $resource_match ) {
				continue;
			}

			$entry['pair_specific']      = $is_pair_specific_runtime;
			$entry['same_trace']         = $same_trace;
			$entry['failure_mode']       = $failure_mode;
			$entry['runtime_signal_type'] = $is_pair_specific_runtime ? 'pair_specific_runtime_breakage' : 'generic_runtime_noise';
			$entry['contaminated']       = $contaminated;
			$matches[]              = $entry;
		}

		return $matches;
	}

	/**
	 * Formats an observed breakage message from runtime telemetry.
	 *
	 * @param array<string, mixed> $entry Error or telemetry entry.
	 * @return string
	 */
	private function format_observed_breakage_message( array $entry ): string {
		$request_context = sanitize_text_field( (string) ( $entry['request_context'] ?? __( 'runtime', 'conflict-debugger' ) ) );
		$resource        = $this->summarize_observed_resource( $entry );
		$execution_surface = $this->execution_surface_for_entry( $entry );
		$message         = sanitize_textarea_field( (string) ( $entry['message'] ?? '' ) );
		$status_code     = (int) ( $entry['status_code'] ?? 0 );
		$signal_type     = sanitize_key( (string) ( $entry['runtime_signal_type'] ?? 'generic_runtime_noise' ) );

		if ( 'generic_runtime_noise' === $signal_type ) {
			return sprintf(
				/* translators: 1: request context, 2: execution surface. */
				__( 'Supporting runtime noise was observed in the %1$s context around %2$s, but the failing path was not proven to be pair-specific.', 'conflict-debugger' ),
				$request_context,
				'' !== $execution_surface ? $execution_surface : __( 'the active execution path', 'conflict-debugger' )
			);
		}

		if ( $status_code >= 400 ) {
			return sprintf(
				/* translators: 1: request context, 2: status code. */
				__( 'Observed %2$d response in the %1$s context during runtime telemetry.', 'conflict-debugger' ),
				$request_context,
				$status_code
			);
		}

		if ( '' !== $resource ) {
			return sprintf(
				/* translators: 1: request context, 2: shared resource, 3: execution surface. */
				__( 'Observed breakage in the %1$s context involving %2$s on %3$s.', 'conflict-debugger' ),
				$request_context,
				$resource,
				'' !== $execution_surface ? $execution_surface : __( 'the active execution path', 'conflict-debugger' )
			);
		}

		return sprintf(
			/* translators: 1: request context, 2: message excerpt. */
			__( 'Observed breakage in the %1$s context: %2$s', 'conflict-debugger' ),
			$request_context,
			wp_trim_words( $message, 18, '...' )
		);
	}

	/**
	 * Checks whether resource hints implicate both plugins.
	 *
	 * @param array<string, mixed> $entry Runtime entry.
	 * @param string               $slug_a Plugin A slug.
	 * @param string               $slug_b Plugin B slug.
	 * @return bool
	 */
	private function entry_resource_hints_match_plugins( array $entry, string $slug_a, string $slug_b ): bool {
		$resource_hints = is_array( $entry['resource_hints'] ?? null ) ? $entry['resource_hints'] : array();
		if ( empty( $resource_hints ) ) {
			return false;
		}

		$owners = $this->owners_for_resource_hints( $resource_hints );

		return in_array( $slug_a, $owners, true ) && in_array( $slug_b, $owners, true );
	}

	/**
	 * Resolves plugin owners from resource hints.
	 *
	 * @param array<int, string> $resource_hints Resource hints.
	 * @return string[]
	 */
	private function owners_for_resource_hints( array $resource_hints ): array {
		$owners          = array();
		$shortcodes      = $this->registry->get_shortcode_snapshot();
		$blocks          = $this->registry->get_block_snapshot();
		$ajax_actions    = $this->registry->get_ajax_action_snapshot();
		$asset_handles   = $this->registry->get_asset_snapshot();

		foreach ( $resource_hints as $resource_hint ) {
			$resource_hint = sanitize_text_field( (string) $resource_hint );
			if ( false === strpos( $resource_hint, ':' ) ) {
				continue;
			}

			list( $type, $value ) = array_map( 'trim', explode( ':', $resource_hint, 2 ) );
			$type  = sanitize_key( $type );
			$value = sanitize_text_field( $value );

			if ( 'shortcode' === $type && ! empty( $shortcodes[ $value ]['owner_slug'] ) ) {
				$owners[] = sanitize_key( (string) $shortcodes[ $value ]['owner_slug'] );
			}

			if ( 'block' === $type && ! empty( $blocks[ $value ]['owner_slug'] ) ) {
				$owners[] = sanitize_key( (string) $blocks[ $value ]['owner_slug'] );
			}

			if ( 'ajax' === $type && ! empty( $ajax_actions[ sanitize_key( $value ) ]['owner_slugs'] ) ) {
				foreach ( (array) $ajax_actions[ sanitize_key( $value ) ]['owner_slugs'] as $owner_slug ) {
					$owners[] = sanitize_key( (string) $owner_slug );
				}
			}

			if ( 'asset' === $type && ! empty( $asset_handles[ sanitize_key( $value ) ]['owner_slug'] ) ) {
				$owners[] = sanitize_key( (string) $asset_handles[ sanitize_key( $value ) ]['owner_slug'] );
			}
		}

		return array_values( array_unique( array_filter( $owners ) ) );
	}

	/**
	 * Summarizes the most specific observed resource from an entry.
	 *
	 * @param array<string, mixed> $entry Runtime entry.
	 * @return string
	 */
	private function summarize_observed_resource( array $entry ): string {
		$resource_hints = is_array( $entry['resource_hints'] ?? null ) ? $entry['resource_hints'] : array();
		$resource_hints = array_values(
			array_filter(
				$resource_hints,
				fn( string $hint ): bool => 0 !== strpos( $hint, 'hook:' )
			)
		);

		if ( ! empty( $entry['callback_identifier'] ) ) {
			return sanitize_text_field( (string) $entry['callback_identifier'] );
		}

		if ( ! empty( $entry['resource_key'] ) ) {
			return sanitize_text_field( (string) $entry['resource_key'] );
		}

		if ( ! empty( $resource_hints ) ) {
			$labels = array_map(
				static function ( string $hint ): string {
					return str_replace( ':', ' ', sanitize_text_field( $hint ) );
				},
				array_slice( $resource_hints, 0, 2 )
			);

			return implode( ', ', $labels );
		}

		$execution_surface = $this->execution_surface_for_entry( $entry );
		$resource          = sanitize_text_field( (string) ( $entry['resource'] ?? $entry['file'] ?? '' ) );

		if ( '' !== $resource && $resource !== $execution_surface ) {
			return $resource;
		}

		return '';
	}

	/**
	 * Returns the execution surface for a runtime entry.
	 *
	 * @param array<string, mixed> $entry Runtime entry.
	 * @return string
	 */
	private function execution_surface_for_entry( array $entry ): string {
		$execution_surface = sanitize_text_field( (string) ( $entry['execution_surface'] ?? '' ) );
		if ( '' !== $execution_surface ) {
			return $execution_surface;
		}

		$hook = sanitize_text_field( (string) ( $entry['hook'] ?? '' ) );
		if ( '' !== $hook ) {
			return $hook;
		}

		$resource_hints = is_array( $entry['resource_hints'] ?? null ) ? $entry['resource_hints'] : array();
		foreach ( $resource_hints as $resource_hint ) {
			$resource_hint = sanitize_text_field( (string) $resource_hint );
			if ( 0 === strpos( $resource_hint, 'hook:' ) ) {
				return sanitize_text_field( substr( $resource_hint, 5 ) );
			}
		}

		return '';
	}

	/**
	 * Returns a normalized failure mode for a telemetry entry.
	 *
	 * @param array<string, mixed> $entry Runtime entry.
	 * @return string
	 */
	private function entry_failure_mode( array $entry ): string {
		$status_code = (int) ( $entry['status_code'] ?? 0 );
		$type        = sanitize_key( (string) ( $entry['type'] ?? '' ) );
		$level       = sanitize_key( (string) ( $entry['level'] ?? '' ) );
		$message     = strtolower( sanitize_textarea_field( (string) ( $entry['message'] ?? '' ) ) );

		if ( $status_code >= 500 || 'fatal' === $level || 'php_runtime' === $type ) {
			return 'runtime_breakage';
		}

		if ( $status_code >= 400 || false !== strpos( $message, 'uncaught' ) || false !== strpos( $message, 'failed to fetch' ) || false !== strpos( $message, 'missing' ) ) {
			return 'request_failure';
		}

		return '';
	}

	/**
	 * Checks whether an entry points to an exact shared resource instead of a hook-only surface.
	 *
	 * @param array<string, mixed> $entry Runtime entry.
	 * @return bool
	 */
	private function entry_has_pair_specific_resource( array $entry ): bool {
		if ( '' !== sanitize_text_field( (string) ( $entry['callback_identifier'] ?? '' ) ) && ! $this->entry_is_callback_timing_noise( $entry ) ) {
			return true;
		}

		if ( '' !== sanitize_text_field( (string) ( $entry['resource_key'] ?? '' ) ) ) {
			return true;
		}

		$resource_hints = is_array( $entry['resource_hints'] ?? null ) ? $entry['resource_hints'] : array();
		foreach ( $resource_hints as $resource_hint ) {
			$resource_hint = sanitize_text_field( (string) $resource_hint );
			if (
				0 === strpos( $resource_hint, 'ajax:' ) ||
				0 === strpos( $resource_hint, 'rest:' ) ||
				0 === strpos( $resource_hint, 'asset:' ) ||
				0 === strpos( $resource_hint, 'shortcode:' ) ||
				0 === strpos( $resource_hint, 'block:' ) ||
				0 === strpos( $resource_hint, 'screen:' )
			) {
				return true;
			}
		}

		$resource = sanitize_text_field( (string) ( $entry['resource'] ?? '' ) );
		return '' !== $resource && $resource !== $this->execution_surface_for_entry( $entry );
	}

	/**
	 * Checks whether a runtime entry contains direct pair mutation evidence.
	 *
	 * @param array<string, mixed> $entry Runtime entry.
	 * @param string               $slug_a Plugin A slug.
	 * @param string               $slug_b Plugin B slug.
	 * @return bool
	 */
	private function entry_has_direct_pair_mutation( array $entry, string $slug_a, string $slug_b ): bool {
		$signal_type    = sanitize_key( (string) ( $entry['type'] ?? '' ) );
		$mutation_kind  = sanitize_key( (string) ( $entry['mutation_kind'] ?? '' ) );
		$actor_slug     = sanitize_key( (string) ( $entry['actor_slug'] ?? '' ) );
		$target_owner   = sanitize_key( (string) ( $entry['target_owner_slug'] ?? '' ) );
		$attribution    = sanitize_key( (string) ( $entry['attribution_status'] ?? '' ) );
		$owner_slugs    = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $slug ): string => sanitize_key( (string) $slug ),
						is_array( $entry['owner_slugs'] ?? null ) ? $entry['owner_slugs'] : array()
					)
				)
			)
		);
		$has_pair_owners = in_array( $slug_a, $owner_slugs, true ) && in_array( $slug_b, $owner_slugs, true );

		if ( ! $has_pair_owners ) {
			return false;
		}

		$direct_pair_actor = '' !== $actor_slug
			&& '' !== $target_owner
			&& in_array( $actor_slug, array( $slug_a, $slug_b ), true )
			&& in_array( $target_owner, array( $slug_a, $slug_b ), true )
			&& $actor_slug !== $target_owner
			&& in_array( $attribution, array( 'attribution_direct', 'attribution_partial' ), true );

		if ( $direct_pair_actor && in_array( $mutation_kind, array( 'asset_dequeued', 'asset_deregistered', 'asset_src_changed', 'asset_dependency_changed', 'asset_version_changed', 'asset_group_changed', 'asset_media_changed', 'callback_removed', 'callback_replaced' ), true ) ) {
			return true;
		}

		return in_array( $signal_type, array( 'asset_queue_mutation', 'asset_registry_mutation' ), true )
			|| 'asset_state_mutation' === $mutation_kind;
	}

	/**
	 * Checks whether runtime evidence is centered on third-party resources.
	 *
	 * @param array<string, mixed> $entry Runtime entry.
	 * @param string               $slug_a Plugin A slug.
	 * @param string               $slug_b Plugin B slug.
	 * @param bool                 $owner_match Whether both plugins own the entry.
	 * @param bool                 $resource_match Whether exact resource hints match both plugins.
	 * @return bool
	 */
	private function entry_is_third_party_contaminated( array $entry, string $slug_a, string $slug_b, bool $owner_match, bool $resource_match ): bool {
		$contamination_status = sanitize_key( (string) ( $entry['contamination_status'] ?? '' ) );
		if ( 'contamination_high' === $contamination_status ) {
			return true;
		}

		if ( 'contamination_possible' === $contamination_status && ! $owner_match && ! $resource_match ) {
			return true;
		}

		$resource_hint_owners = $this->owners_for_resource_hints( is_array( $entry['resource_hints'] ?? null ) ? $entry['resource_hints'] : array() );
		$third_party_owners   = array_values(
			array_filter(
				$resource_hint_owners,
				static fn( string $owner ): bool => ! in_array( $owner, array( $slug_a, $slug_b ), true )
			)
		);

		if ( ! empty( $third_party_owners ) && ! $resource_match ) {
			return true;
		}

		$owner_slugs = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $slug ): string => sanitize_key( (string) $slug ),
						is_array( $entry['owner_slugs'] ?? null ) ? $entry['owner_slugs'] : array()
					)
				)
			)
		);

		$third_party_runtime_owners = array_values(
			array_filter(
				$owner_slugs,
				static fn( string $owner ): bool => ! in_array( $owner, array( $slug_a, $slug_b ), true )
			)
		);

		if ( ! empty( $third_party_runtime_owners ) && ! $owner_match && ! $resource_match ) {
			return true;
		}

		$resource_hints = is_array( $entry['resource_hints'] ?? null ) ? $entry['resource_hints'] : array();
		foreach ( $resource_hints as $resource_hint ) {
			$resource_hint = sanitize_text_field( (string) $resource_hint );

			if ( 0 === strpos( $resource_hint, 'screen:' ) ) {
				$screen_id = strtolower( substr( $resource_hint, 7 ) );
				if ( '' !== $screen_id && false === strpos( $screen_id, strtolower( $slug_a ) ) && false === strpos( $screen_id, strtolower( $slug_b ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Checks whether an entry looks like callback snapshot churn rather than direct removal evidence.
	 *
	 * @param array<string, mixed> $entry Runtime entry.
	 * @return bool
	 */
	private function entry_is_callback_timing_noise( array $entry ): bool {
		$type          = sanitize_key( (string) ( $entry['type'] ?? '' ) );
		$mutation_kind = sanitize_key( (string) ( $entry['mutation_kind'] ?? '' ) );

		return 'callback_mutation' === $type || in_array( $mutation_kind, array( 'callback_chain_churn', 'callback_priority_changed' ), true );
	}

	/**
	 * Normalizes pairwise classification with stricter causality gates.
	 *
	 * @param string                           $finding_type Finding type.
	 * @param string                           $severity Severity.
	 * @param int                              $score Confidence score.
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @param bool                             $observer_involved Whether an observer plugin is involved.
	 * @return array{0:string,1:string,2:int}
	 */
	private function normalize_pairwise_classification( string $finding_type, string $severity, int $score, array $evidence_items, bool $observer_involved ): array {
		$has_pair_specific = $this->has_pair_specific_causality( $evidence_items );
		$can_confirm       = $this->can_confirm_pairwise_conflict( $evidence_items );
		$has_contextual    = $this->has_evidence_tier( $evidence_items, 'supporting' );
		$has_strong_proof  = $this->has_evidence_tier( $evidence_items, 'strong_proof' );
		$has_pair_runtime  = $this->has_signal_key( $evidence_items, 'pair_specific_runtime_breakage' );
		$has_generic_runtime = $this->has_signal_key( $evidence_items, 'generic_runtime_noise' );
		$strong_proof_count  = (int) ( $this->heuristics->evidence_breakdown( $evidence_items )['strong_proof'] ?? 0 );
		$is_admin_noise      = $this->is_mostly_common_admin_overlap( $evidence_items );
		$has_admin_resource_proof = $this->has_admin_resource_proof( $evidence_items );
		$has_contamination   = $this->has_third_party_contamination( $evidence_items );

		if ( ! $has_pair_specific && ! $has_strong_proof ) {
			$finding_type = $has_contextual ? 'shared_surface' : 'overlap';
			$severity     = $has_contextual ? 'medium' : 'low';
			$score        = min( $score, $has_contextual ? 55 : 30 );
		}

		if ( 'confirmed_conflict' === $finding_type && ! $can_confirm ) {
			$finding_type = $has_pair_specific ? 'probable_conflict' : 'potential_interference';
			$severity     = $has_pair_specific ? 'high' : 'medium';
			$score        = min( $score, $has_pair_specific ? 90 : 75 );
		}

		if ( $observer_involved && ! $can_confirm ) {
			$severity = $this->heuristics->severity_rank( $severity ) > $this->heuristics->severity_rank( 'medium' ) ? 'medium' : $severity;
			$score    = min( $score, $has_pair_specific ? 70 : 45 );
			if ( in_array( $finding_type, array( 'probable_conflict', 'confirmed_conflict' ), true ) ) {
				$finding_type = $has_pair_specific ? 'potential_interference' : 'shared_surface';
			}
		}

		if ( 0 === $strong_proof_count && ! $has_pair_runtime ) {
			if ( $this->heuristics->severity_rank( $severity ) > $this->heuristics->severity_rank( 'medium' ) ) {
				$severity = 'medium';
			}

			if ( in_array( $finding_type, array( 'probable_conflict', 'confirmed_conflict' ), true ) ) {
				$finding_type = $has_generic_runtime ? 'potential_interference' : ( $has_contextual ? 'shared_surface' : 'overlap' );
			}
		}

		if ( $is_admin_noise && ! $has_admin_resource_proof ) {
			$finding_type = $has_generic_runtime ? 'potential_interference' : 'shared_surface';
			$severity     = $this->heuristics->severity_rank( $severity ) > $this->heuristics->severity_rank( 'medium' ) ? 'medium' : $severity;
			$score        = min( $score, 50 );
		}

		if ( $has_contamination && ! $has_pair_runtime ) {
			$finding_type = $has_contextual || $has_generic_runtime ? 'potential_interference' : 'shared_surface';
			$severity     = $this->heuristics->severity_rank( $severity ) > $this->heuristics->severity_rank( 'medium' ) ? 'medium' : $severity;
			$score        = min( max( 0, $score - 18 ), 50 );
		}

		$score = min( $score, $this->confidence_ceiling_for_category( $finding_type, $strong_proof_count > 0, $has_pair_runtime, $is_admin_noise, $has_contamination ) );

		return array( $finding_type, $severity, $score );
	}

	/**
	 * Checks whether evidence contains pair-specific causality.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return bool
	 */
	private function has_pair_specific_causality( array $evidence_items ): bool {
		$pair_specific_signals = array(
			'rest_route_overlap',
			'ajax_action_overlap',
			'routing_overlap',
			'content_model_overlap',
			'asset_state_mutation',
			'direct_callback_mutation',
			'pair_specific_runtime_breakage',
		);

		foreach ( $evidence_items as $evidence_item ) {
			$signal_key      = sanitize_key( (string) ( $evidence_item['signal_key'] ?? '' ) );
			$shared_resource = sanitize_text_field( (string) ( $evidence_item['shared_resource'] ?? '' ) );
			if ( in_array( $signal_key, $pair_specific_signals, true ) && '' !== $shared_resource ) {
				return true;
			}

			if ( ! empty( $evidence_item['pair_specific'] ) && '' !== $shared_resource ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether evidence can support a pairwise confirmed finding.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return bool
	 */
	private function can_confirm_pairwise_conflict( array $evidence_items ): bool {
		$has_observed_pair_breakage = false;
		$has_exact_interference     = false;

		foreach ( $evidence_items as $evidence_item ) {
			if ( 'pair_specific_runtime_breakage' === sanitize_key( (string) ( $evidence_item['signal_key'] ?? '' ) ) && ! empty( $evidence_item['same_trace'] ) && '' !== (string) ( $evidence_item['shared_resource'] ?? '' ) ) {
				return true;
			}

			if ( ! empty( $evidence_item['pair_specific'] ) && ! empty( $evidence_item['same_trace'] ) && '' !== (string) ( $evidence_item['failure_mode'] ?? '' ) ) {
				$has_observed_pair_breakage = true;
			}

			if ( in_array( sanitize_key( (string) ( $evidence_item['signal_key'] ?? '' ) ), array( 'rest_route_overlap', 'ajax_action_overlap', 'routing_overlap', 'content_model_overlap', 'asset_state_mutation', 'direct_callback_mutation' ), true ) && '' !== (string) ( $evidence_item['shared_resource'] ?? '' ) ) {
				$has_exact_interference = true;
			}
		}

		return $has_observed_pair_breakage && $has_exact_interference;
	}

	/**
	 * Returns whether evidence contains a given tier.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @param string                           $tier Tier.
	 * @return bool
	 */
	private function has_evidence_tier( array $evidence_items, string $tier ): bool {
		$tier = $this->heuristics->normalize_tier( $tier );

		foreach ( $evidence_items as $evidence_item ) {
			if ( $tier === $this->heuristics->normalize_tier( (string) ( $evidence_item['tier'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether evidence contains a signal key.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @param string                           $signal_key Signal key.
	 * @return bool
	 */
	private function has_signal_key( array $evidence_items, string $signal_key ): bool {
		foreach ( $evidence_items as $evidence_item ) {
			if ( $signal_key === sanitize_key( (string) ( $evidence_item['signal_key'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether evidence is dominated by common admin lifecycle hooks.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return bool
	 */
	private function is_mostly_common_admin_overlap( array $evidence_items ): bool {
		$admin_hooks = array(
			'admin_menu',
			'admin_init',
			'current_screen',
			'admin_enqueue_scripts',
			'load-post.php',
			'load-edit.php',
			'load-post-new.php',
		);

		$admin_count  = 0;
		$normal_count = 0;

		foreach ( $evidence_items as $evidence_item ) {
			$request_context   = strtolower( (string) ( $evidence_item['request_context'] ?? '' ) );
			$execution_surface = strtolower( (string) ( $evidence_item['execution_surface'] ?? '' ) );
			if ( false === strpos( $request_context, 'admin' ) ) {
				continue;
			}

			$admin_count++;
			if ( in_array( $execution_surface, $admin_hooks, true ) ) {
				$normal_count++;
			}
		}

		return $admin_count >= 2 && $normal_count >= max( 2, $admin_count - 1 );
	}

	/**
	 * Returns whether admin evidence contains a concrete shared resource.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return bool
	 */
	private function has_admin_resource_proof( array $evidence_items ): bool {
		$non_resource_hooks = array(
			'admin_menu',
			'admin_init',
			'current_screen',
			'admin_enqueue_scripts',
			'load-post.php',
			'load-edit.php',
			'load-post-new.php',
		);
		$allowed_signals = array(
			'admin_screen_overlap',
			'asset_state_mutation',
			'direct_callback_mutation',
			'pair_specific_runtime_breakage',
		);

		foreach ( $evidence_items as $evidence_item ) {
			$request_context = strtolower( (string) ( $evidence_item['request_context'] ?? '' ) );
			$resource        = strtolower( (string) ( $evidence_item['shared_resource'] ?? '' ) );
			$tier            = $this->heuristics->normalize_tier( (string) ( $evidence_item['tier'] ?? '' ) );
			$signal_key      = sanitize_key( (string) ( $evidence_item['signal_key'] ?? '' ) );

			if ( false === strpos( $request_context, 'admin' ) || '' === $resource || 'strong_proof' !== $tier ) {
				continue;
			}

			if ( in_array( $resource, $non_resource_hooks, true ) ) {
				continue;
			}

			if ( in_array( $signal_key, $allowed_signals, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether evidence includes contamination signals.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return bool
	 */
	private function has_third_party_contamination( array $evidence_items ): bool {
		foreach ( $evidence_items as $evidence_item ) {
			if ( ! empty( $evidence_item['contaminated'] ) || 'third_party_contamination' === sanitize_key( (string) ( $evidence_item['signal_key'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns a category-based confidence ceiling.
	 *
	 * @param string $category Finding category.
	 * @param bool   $has_strong_proof Whether strong proof exists.
	 * @param bool   $has_pair_runtime Whether pair-specific runtime breakage exists.
	 * @param bool   $is_admin_noise Whether common admin overlap dominates.
	 * @param bool   $has_contamination Whether contamination exists.
	 * @return int
	 */
	private function confidence_ceiling_for_category( string $category, bool $has_strong_proof, bool $has_pair_runtime, bool $is_admin_noise, bool $has_contamination ): int {
		$ceilings = array(
			'overlap'                => 35,
			'shared_surface'         => 50,
			'potential_interference' => 65,
			'probable_conflict'      => $has_strong_proof ? 85 : 65,
			'confirmed_conflict'     => $has_pair_runtime ? 100 : 85,
			'observer_artifact'      => 55,
			'global_anomaly'         => 60,
		);

		$ceiling = $ceilings[ $category ] ?? 65;

		if ( ! $has_strong_proof ) {
			$ceiling = min( $ceiling, 65 );
		}

		if ( $is_admin_noise ) {
			$ceiling = min( $ceiling, 50 );
		}

		if ( $has_contamination ) {
			$ceiling = min( $ceiling, 50 );
		}

		return $ceiling;
	}

	/**
	 * Indexes plugin data by slug.
	 *
	 * @param array<int, array<string, mixed>> $plugins Plugins.
	 * @return array<string, array<string, mixed>>
	 */
	private function index_plugins_by_slug( array $plugins ): array {
		$index = array();

		foreach ( $plugins as $plugin ) {
			$slug = sanitize_key( (string) ( $plugin['slug'] ?? '' ) );
			if ( '' !== $slug ) {
				$index[ $slug ] = $plugin;
			}
		}

		return $index;
	}

	/**
	 * Collects repeated non-specific runtime patterns that should be grouped.
	 *
	 * @param array<int, array<string, mixed>>   $entries Runtime entries.
	 * @param array<string, array<string, mixed>> $plugin_index Plugin index.
	 * @return array<string, array<string, mixed>>
	 */
	private function collect_repeated_pattern_groups( array $entries, array $plugin_index ): array {
		$groups = array();

		foreach ( $entries as $entry ) {
			if ( ! $this->entry_is_callback_timing_noise( $entry ) ) {
				continue;
			}

			$owner_slugs = array_values(
				array_unique(
					array_filter(
						array_map(
							static fn( $slug ): string => sanitize_key( (string) $slug ),
							is_array( $entry['owner_slugs'] ?? null ) ? $entry['owner_slugs'] : array()
						)
					)
				)
			);

			if ( count( $owner_slugs ) < 2 ) {
				continue;
			}

			$anchors = array_values(
				array_filter(
					$owner_slugs,
					fn( string $slug ): bool => $this->is_observer_slug( $slug, $plugin_index )
				)
			);

			if ( empty( $anchors ) ) {
				$anchors = array( $owner_slugs[0] );
			}

			$fingerprint = $this->normalize_repeated_pattern_fingerprint( $entry );
			if ( '' === $fingerprint ) {
				continue;
			}

			foreach ( $anchors as $anchor_slug ) {
				$group_key = $anchor_slug . '|' . $fingerprint;

				if ( ! isset( $groups[ $group_key ] ) ) {
					$groups[ $group_key ] = array(
						'anchor_slug'      => $anchor_slug,
						'is_observer'      => $this->is_observer_slug( $anchor_slug, $plugin_index ),
						'fingerprint'      => $fingerprint,
						'entry'            => $entry,
						'counterpart_slugs' => array(),
					);
				}

				foreach ( $owner_slugs as $owner_slug ) {
					if ( $owner_slug === $anchor_slug ) {
						continue;
					}

					$groups[ $group_key ]['counterpart_slugs'][ $owner_slug ] = true;
				}
			}
		}

		foreach ( $groups as $group_key => $group ) {
			$groups[ $group_key ]['counterpart_slugs'] = array_keys( (array) $group['counterpart_slugs'] );
			if ( count( (array) $groups[ $group_key ]['counterpart_slugs'] ) < 2 ) {
				unset( $groups[ $group_key ] );
			}
		}

		return $groups;
	}

	/**
	 * Finds a repeated pattern group for a specific pair.
	 *
	 * @param array<string, array<string, mixed>> $pattern_groups Pattern groups.
	 * @param string                              $slug_a Plugin A slug.
	 * @param string                              $slug_b Plugin B slug.
	 * @return array<string, mixed>
	 */
	private function find_repeated_pattern_group_for_pair( array $pattern_groups, string $slug_a, string $slug_b ): array {
		foreach ( $pattern_groups as $pattern_group ) {
			$anchor_slug       = sanitize_key( (string) ( $pattern_group['anchor_slug'] ?? '' ) );
			$counterpart_slugs = array_map( 'sanitize_key', (array) ( $pattern_group['counterpart_slugs'] ?? array() ) );

			if (
				( $anchor_slug === $slug_a && in_array( $slug_b, $counterpart_slugs, true ) ) ||
				( $anchor_slug === $slug_b && in_array( $slug_a, $counterpart_slugs, true ) )
			) {
				return $pattern_group;
			}
		}

		return array();
	}

	/**
	 * Builds grouped anomaly findings from repeated patterns.
	 *
	 * @param array<string, array<string, mixed>> $pattern_groups Pattern groups.
	 * @param array<string, array<string, mixed>> $plugin_index Plugin index.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_grouped_anomaly_findings( array $pattern_groups, array $plugin_index ): array {
		$findings = array();

		foreach ( $pattern_groups as $pattern_group ) {
			$anchor_slug       = sanitize_key( (string) ( $pattern_group['anchor_slug'] ?? '' ) );
			$counterpart_slugs = array_values( array_map( 'sanitize_key', (array) ( $pattern_group['counterpart_slugs'] ?? array() ) ) );
			$sample_entry      = is_array( $pattern_group['entry'] ?? null ) ? $pattern_group['entry'] : array();
			$anchor_plugin     = $plugin_index[ $anchor_slug ] ?? array( 'slug' => $anchor_slug, 'name' => $anchor_slug );

			$counterpart_names = array();
			foreach ( $counterpart_slugs as $counterpart_slug ) {
				$counterpart_names[] = (string) ( $plugin_index[ $counterpart_slug ]['name'] ?? $counterpart_slug );
			}

			$request_context   = sanitize_text_field( (string) ( $sample_entry['request_context'] ?? __( 'runtime', 'conflict-debugger' ) ) );
			$execution_surface = $this->execution_surface_for_entry( $sample_entry );
			$surface_key       = $this->surface_for_context( $request_context );
			$finding_type      = ! empty( $pattern_group['is_observer'] ) ? 'observer_artifact' : 'global_anomaly';
			$shared_resource   = $this->summarize_observed_resource( $sample_entry );
			$confidence        = ! empty( $pattern_group['is_observer'] ) ? min( 55, 35 + ( count( $counterpart_slugs ) * 5 ) ) : min( 60, 40 + ( count( $counterpart_slugs ) * 5 ) );
			$severity          = 'medium';

			$evidence_items = array(
				$this->create_evidence_item(
					$surface_key,
					! empty( $pattern_group['is_observer'] ) ? 'repeated_observer_pattern' : 'global_anomaly_pattern',
					sprintf(
						/* translators: 1: plugin name, 2: count. */
						__( '%1$s shows the same callback-chain churn pattern against %2$d different plugins.', 'conflict-debugger' ),
						(string) ( $anchor_plugin['name'] ?? $anchor_slug ),
						count( $counterpart_slugs )
					),
					'context',
					array(
						'tier'              => 'contextual',
						'request_context'   => $request_context,
						'execution_surface' => $execution_surface,
						'shared_resource'   => $shared_resource,
						'finding_type_hint' => $finding_type,
					)
				),
				$this->create_evidence_item(
					$surface_key,
					'callback_chain_churn',
					__( 'The repeated fingerprint is based on callback presence differences across snapshots, which can happen with conditional or observer-driven lifecycle behavior.', 'conflict-debugger' ),
					'context',
					array(
						'tier'              => 'contextual',
						'request_context'   => $request_context,
						'execution_surface' => $execution_surface,
						'finding_type_hint' => $finding_type,
					)
				),
			);

			$findings[] = array(
				'primary_plugin'                   => $anchor_slug,
				'primary_plugin_name'              => (string) ( $anchor_plugin['name'] ?? $anchor_slug ),
				'secondary_plugin'                 => '',
				'secondary_plugin_name'            => sprintf(
					/* translators: %d count. */
					__( 'Multiple plugins (%d)', 'conflict-debugger' ),
					count( $counterpart_slugs )
				),
				'issue_category'                   => $surface_key,
				'surface_key'                      => $surface_key,
				'surface_label'                    => (string) ( $this->surfaces[ $surface_key ]['label'] ?? $surface_key ),
				'affected_area'                    => (string) ( $this->surfaces[ $surface_key ]['affected_area'] ?? __( 'runtime', 'conflict-debugger' ) ),
				'title'                            => ! empty( $pattern_group['is_observer'] )
					? sprintf(
						/* translators: %s plugin name. */
						__( 'Recurring runtime anomaly involving %s', 'conflict-debugger' ),
						(string) ( $anchor_plugin['name'] ?? $anchor_slug )
					)
					: __( 'Repeated callback-chain interference pattern detected', 'conflict-debugger' ),
				'severity'                         => $severity,
				'status'                           => $this->heuristics->ui_status_for( $severity ),
				'confidence'                       => $confidence,
				'category'                         => $finding_type,
				'finding_type'                     => $finding_type,
				'shared_resource'                  => $shared_resource,
				'execution_surface'                => $execution_surface,
				'request_context'                  => $request_context,
				'evidence'                         => array_values( array_map( static fn( array $item ): string => (string) $item['message'], $evidence_items ) ),
				'evidence_items'                   => $evidence_items,
				'evidence_strength_breakdown'      => $this->heuristics->evidence_breakdown( $evidence_items ),
				'explanation'                      => ! empty( $pattern_group['is_observer'] )
					? sprintf(
						/* translators: 1: plugin name, 2: request context, 3: execution surface. */
						__( '%1$s appears in the same callback-chain churn pattern across multiple unrelated plugins in the %2$s context on %3$s. That is more consistent with observer-plugin callback churn or snapshot timing noise than a uniquely confirmed pairwise conflict.', 'conflict-debugger' ),
						(string) ( $anchor_plugin['name'] ?? $anchor_slug ),
						$request_context,
						'' !== $execution_surface ? $execution_surface : __( 'the active execution path', 'conflict-debugger' )
					)
					: sprintf(
						/* translators: 1: request context, 2: execution surface. */
						__( 'The same non-specific callback-chain churn pattern repeats across multiple plugin pairs in the %1$s context on %2$s. This should be treated as a global anomaly until a single shared resource or remover is identified.', 'conflict-debugger' ),
						$request_context,
						'' !== $execution_surface ? $execution_surface : __( 'the active execution path', 'conflict-debugger' )
					),
				'why_scored_this_way'              => ! empty( $pattern_group['is_observer'] )
					? __( 'Scored as an observer artifact because the same callback-chain churn fingerprint repeats against multiple unrelated plugins instead of isolating one pair-specific resource collision.', 'conflict-debugger' )
					: __( 'Scored as a global anomaly because the repeated runtime pattern is broad and non-specific across several plugin pairs.', 'conflict-debugger' ),
				'why_this_is_not_or_is_actionable' => ! empty( $pattern_group['is_observer'] )
					? __( 'This is not a confirmed pairwise conflict. Reproduce the affected request with the observer/debug plugin disabled or compare traces from the same request path before escalating to a plugin-pair diagnosis.', 'conflict-debugger' )
					: __( 'This is actionable as a runtime anomaly review, not as a confirmed plugin-pair conflict. Look for a single shared resource or exact remover before treating any pair as confirmed.', 'conflict-debugger' ),
				'recommended_next_step'            => ! empty( $pattern_group['is_observer'] )
					? __( 'Reproduce the affected request in staging with the observer/debug plugin disabled, then compare the callback chain and runtime failures on the same request path.', 'conflict-debugger' )
					: __( 'Capture the same request path again and isolate a single shared resource, callback identifier, or remover before acting on any pairwise diagnosis.', 'conflict-debugger' ),
				'related_plugins'                  => $counterpart_names,
			);
		}

		return $findings;
	}

	/**
	 * Normalizes repeated-pattern fingerprints.
	 *
	 * @param array<string, mixed> $entry Runtime entry.
	 * @return string
	 */
	private function normalize_repeated_pattern_fingerprint( array $entry ): string {
		$request_context   = sanitize_text_field( (string) ( $entry['request_context'] ?? '' ) );
		$execution_surface = $this->execution_surface_for_entry( $entry );
		$mutation_kind     = sanitize_key( (string) ( $entry['mutation_kind'] ?? $entry['type'] ?? '' ) );

		if ( '' === $request_context && '' === $execution_surface ) {
			return '';
		}

		return implode(
			'|',
			array(
				$mutation_kind,
				$request_context,
				$execution_surface,
			)
		);
	}

	/**
	 * Determines whether a plugin is an observer/debug style plugin.
	 *
	 * @param array<string, mixed> $plugin Plugin data.
	 * @return bool
	 */
	private function is_observer_plugin( array $plugin ): bool {
		$categories = is_array( $plugin['categories'] ?? null ) ? $plugin['categories'] : array();
		if ( in_array( 'observer', $categories, true ) ) {
			return true;
		}

		$text = strtolower( (string) ( $plugin['slug'] ?? '' ) . ' ' . (string) ( $plugin['name'] ?? '' ) );
		foreach ( array( 'query monitor', 'debug bar', 'profiler', 'debugger', 'health check', 'troubleshoot' ) as $term ) {
			if ( false !== strpos( $text, $term ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determines whether a slug belongs to an observer plugin.
	 *
	 * @param string                              $slug Plugin slug.
	 * @param array<string, array<string, mixed>> $plugin_index Plugin index.
	 * @return bool
	 */
	private function is_observer_slug( string $slug, array $plugin_index ): bool {
		return ! empty( $plugin_index[ $slug ] ) && $this->is_observer_plugin( $plugin_index[ $slug ] );
	}

	/**
	 * Matches coarse known-risk patterns.
	 *
	 * @param array<string, mixed> $plugin_a Plugin A.
	 * @param array<string, mixed> $plugin_b Plugin B.
	 * @return string|null
	 */
	private function match_known_risk_patterns( array $plugin_a, array $plugin_b ): ?string {
		$combined = strtolower(
			implode(
				' ',
				array(
					(string) $plugin_a['slug'],
					(string) $plugin_a['name'],
					(string) $plugin_b['slug'],
					(string) $plugin_b['name'],
				)
			)
		);

		foreach ( $this->known_risk_rules as $anchor => $keywords ) {
			if ( false === strpos( $combined, $anchor ) ) {
				continue;
			}

			foreach ( $keywords as $keyword ) {
				if ( false !== strpos( $combined, $keyword ) ) {
					return __( 'This combination matches an internal risk pattern that often deserves manual compatibility testing.', 'conflict-debugger' );
				}
			}
		}

		return null;
	}

	/**
	 * Builds a human explanation.
	 *
	 * @param array<string, mixed> $plugin_a Plugin A.
	 * @param array<string, mixed> $plugin_b Plugin B.
	 * @param string               $finding_type Finding type.
	 * @param string               $surface_key Surface key.
	 * @param string               $shared_resource Shared resource.
	 * @param string               $request_context Request context.
	 * @return string
	 */
	private function build_explanation( array $plugin_a, array $plugin_b, string $finding_type, string $surface_key, string $shared_resource, string $request_context, string $execution_surface = '' ): string {
		$surface = $this->surfaces[ $surface_key ] ?? array();
		$area    = (string) ( $surface['affected_area'] ?? __( 'generic site behavior', 'conflict-debugger' ) );
		$context = $request_context ? $request_context : $area;
		$surface_note = '' !== $execution_surface ? ' ' . sprintf(
			/* translators: %s execution surface. */
			__( 'Execution surface: %s.', 'conflict-debugger' ),
			$execution_surface
		) : '';

		if ( 'confirmed_conflict' === $finding_type ) {
			return sprintf(
				/* translators: 1: plugin A, 2: plugin B, 3: request context, 4: shared resource. */
				__( 'Confirmed runtime breakage was observed for %1$s and %2$s in the %3$s context. Direct evidence points to %4$s on the live execution path.', 'conflict-debugger' ),
				(string) $plugin_a['name'],
				(string) $plugin_b['name'],
				$context,
				$shared_resource ? $shared_resource : __( 'this runtime path', 'conflict-debugger' )
			) . $surface_note;
		}

		if ( 'probable_conflict' === $finding_type ) {
			return sprintf(
				/* translators: 1: plugin A, 2: plugin B, 3: shared resource, 4: request context. */
				__( 'Direct evidence suggests %1$s and %2$s both affect %3$s in the %4$s context. This goes beyond broad overlap, but it still needs validation on the affected request path.', 'conflict-debugger' ),
				(string) $plugin_a['name'],
				(string) $plugin_b['name'],
				$shared_resource ? $shared_resource : __( 'the same resource', 'conflict-debugger' ),
				$context
			) . $surface_note;
		}

		if ( 'potential_interference' === $finding_type ) {
			return sprintf(
				/* translators: 1: plugin A, 2: plugin B, 3: request context. */
				__( '%1$s and %2$s show a potential interference pattern in the %3$s context. Supporting indicators line up on the same execution path, but the proof is still incomplete.', 'conflict-debugger' ),
				(string) $plugin_a['name'],
				(string) $plugin_b['name'],
				$context
			) . $surface_note;
		}

		if ( 'observer_artifact' === $finding_type ) {
			return sprintf(
				/* translators: 1: plugin A, 2: request context. */
				__( 'A recurring runtime anomaly involving %1$s was observed in the %2$s context. The repeated fingerprint looks more like observer-plugin callback churn or snapshot timing noise than a unique pair-specific conflict.', 'conflict-debugger' ),
				(string) $plugin_a['name'],
				$context
			) . $surface_note;
		}

		if ( 'global_anomaly' === $finding_type ) {
			return sprintf(
				/* translators: %s request context. */
				__( 'The same non-specific runtime pattern repeats across multiple plugin pairs in the %s context. Treat it as a global anomaly until a single shared resource or remover is identified.', 'conflict-debugger' ),
				$context
			) . $surface_note;
		}

		if ( 'shared_surface' === $finding_type ) {
			return sprintf(
				/* translators: 1: plugin A, 2: plugin B, 3: request context. */
				__( '%1$s and %2$s touch the same execution surface in the %3$s context. That matters more than broad overlap, but no exact shared resource or direct interference is proven yet.', 'conflict-debugger' ),
				(string) $plugin_a['name'],
				(string) $plugin_b['name'],
				$context
			) . $surface_note;
		}

		return sprintf(
			/* translators: 1: plugin A, 2: plugin B, 3: request context. */
			__( '%1$s and %2$s were observed on a shared runtime surface in the %3$s context. That is common in WordPress and is not direct conflict evidence on its own.', 'conflict-debugger' ),
			(string) $plugin_a['name'],
			(string) $plugin_b['name'],
			$context
		) . $surface_note;
	}

	/**
	 * Builds a short human title for a finding.
	 *
	 * @param array<string, mixed> $plugin_a Plugin A.
	 * @param array<string, mixed> $plugin_b Plugin B.
	 * @param string               $finding_type Finding type.
	 * @param string               $surface_key Surface key.
	 * @param string               $shared_resource Shared resource.
	 * @param string               $request_context Request context.
	 * @return string
	 */
	private function build_title( array $plugin_a, array $plugin_b, string $finding_type, string $surface_key, string $shared_resource, string $request_context ): string {
		$surface_label = (string) ( $this->surfaces[ $surface_key ]['label'] ?? $surface_key );

		if ( 'observer_artifact' === $finding_type ) {
			return sprintf(
				/* translators: %s plugin name. */
				__( 'Recurring runtime anomaly involving %s', 'conflict-debugger' ),
				(string) $plugin_a['name']
			);
		}

		if ( 'global_anomaly' === $finding_type ) {
			return __( 'Repeated callback-chain interference pattern detected', 'conflict-debugger' );
		}

		if ( 'confirmed_conflict' === $finding_type ) {
			return sprintf(
				/* translators: 1: plugin A, 2: plugin B. */
				__( 'Confirmed runtime breakage involving %1$s and %2$s', 'conflict-debugger' ),
				(string) $plugin_a['name'],
				(string) $plugin_b['name']
			);
		}

		if ( 'probable_conflict' === $finding_type ) {
			return sprintf(
				/* translators: 1: shared resource, 2: request context. */
				__( 'Probable conflict on %1$s in %2$s', 'conflict-debugger' ),
				$shared_resource ? $shared_resource : $surface_label,
				$request_context
			);
		}

		if ( 'potential_interference' === $finding_type ) {
			return sprintf(
				/* translators: %s request context. */
				__( 'Potential interference in %s', 'conflict-debugger' ),
				$request_context
			);
		}

		if ( 'shared_surface' === $finding_type ) {
			return sprintf(
				/* translators: %s surface label. */
				__( 'Shared execution surface in %s', 'conflict-debugger' ),
				$surface_label
			);
		}

		return sprintf(
			/* translators: %s surface label. */
			__( 'Normal overlap on %s', 'conflict-debugger' ),
			$surface_label
		);
	}

	/**
	 * Explains whether the finding is actionable.
	 *
	 * @param string $finding_type Finding type.
	 * @param string $shared_resource Shared resource.
	 * @param string $request_context Request context.
	 * @return string
	 */
	private function build_actionability_note( string $finding_type, string $shared_resource, string $request_context, string $execution_surface = '' ): string {
		if ( 'confirmed_conflict' === $finding_type ) {
			return __( 'This is actionable because the scan observed runtime breakage rather than just shared plugin presence.', 'conflict-debugger' );
		}

		if ( 'probable_conflict' === $finding_type ) {
			return sprintf(
				/* translators: 1: shared resource, 2: request context. */
				__( 'This is actionable because direct shared-resource evidence points to %1$s in the same %2$s context.', 'conflict-debugger' ),
				$shared_resource ? $shared_resource : __( 'the same resource', 'conflict-debugger' ),
				$request_context
			);
		}

		if ( 'potential_interference' === $finding_type ) {
			return __( 'This is worth validating on the affected request path, but it is not yet proof that one plugin is breaking the other.', 'conflict-debugger' );
		}

		if ( 'observer_artifact' === $finding_type ) {
			return __( 'This is not actionable as a confirmed plugin-pair conflict. Verify the same request without the observer/debug plugin before treating it as pair-specific interference.', 'conflict-debugger' );
		}

		if ( 'global_anomaly' === $finding_type ) {
			return __( 'This is actionable as a global runtime anomaly review, not as proof that any one plugin pair is confirmed.', 'conflict-debugger' );
		}

		if ( 'shared_surface' === $finding_type ) {
			return __( 'This is supporting context only. It becomes useful if a later trace shows a concrete shared resource or runtime failure on the same path.', 'conflict-debugger' );
		}

		return __( 'This is broad overlap only. Treat it as background context, not as proof of a conflict.', 'conflict-debugger' );
	}

	/**
	 * Builds a more tailored next-step recommendation from the strongest evidence.
	 *
	 * @param string                           $surface_key Conflict surface key.
	 * @param string                           $request_context Request context.
	 * @param string                           $execution_surface Execution surface.
	 * @param string                           $shared_resource Shared resource.
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return string
	 */
	private function build_recommended_next_step( string $surface_key, string $request_context, string $execution_surface, string $shared_resource, array $evidence_items ): string {
		if ( $this->evidence_has_signal( $evidence_items, array( 'direct_callback_mutation' ) ) ) {
			return sprintf(
				/* translators: 1: hook or execution surface, 2: callback label or shared resource, 3: request context. */
				__( 'Inspect remove_action and remove_filter activity on %1$s, then replay the affected %3$s request while tracing the callback chain for %2$s.', 'conflict-debugger' ),
				'' !== $execution_surface ? $execution_surface : __( 'the affected hook', 'conflict-debugger' ),
				'' !== $shared_resource ? $shared_resource : __( 'the removed callback', 'conflict-debugger' ),
				'' !== $request_context ? $request_context : __( 'runtime', 'conflict-debugger' )
			);
		}

		if ( $this->evidence_has_signal( $evidence_items, array( 'callback_order_sensitivity', 'callback_chain_churn' ) ) ) {
			return sprintf(
				/* translators: 1: hook or execution surface, 2: request context. */
				__( 'Trace callback order on %1$s and compare priorities across the affected %2$s request before treating this as a confirmed conflict.', 'conflict-debugger' ),
				'' !== $execution_surface ? $execution_surface : __( 'the affected hook', 'conflict-debugger' ),
				'' !== $request_context ? $request_context : __( 'runtime', 'conflict-debugger' )
			);
		}

		if ( $this->evidence_has_signal( $evidence_items, array( 'asset_state_mutation' ) ) ) {
			return sprintf(
				/* translators: 1: resource, 2: request context. */
				__( 'Trace the asset lifecycle for %1$s and compare registration, queue, and final state on the affected %2$s request.', 'conflict-debugger' ),
				'' !== $shared_resource ? $shared_resource : __( 'the affected handle', 'conflict-debugger' ),
				'' !== $request_context ? $request_context : __( 'runtime', 'conflict-debugger' )
			);
		}

		return $this->heuristics->suggestion_for( $surface_key );
	}

	/**
	 * Returns whether the evidence list contains one of the given signal keys.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @param string[]                         $signal_keys Signal keys.
	 * @return bool
	 */
	private function evidence_has_signal( array $evidence_items, array $signal_keys ): bool {
		$signal_keys = array_values( array_filter( array_map( 'sanitize_key', $signal_keys ) ) );
		if ( empty( $signal_keys ) ) {
			return false;
		}

		foreach ( $evidence_items as $evidence_item ) {
			if ( in_array( sanitize_key( (string) ( $evidence_item['signal_key'] ?? '' ) ), $signal_keys, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Adds detector metadata to evidence items.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @param string                           $surface_key Surface key.
	 * @return array<int, array<string, mixed>>
	 */
	private function enrich_evidence_items( array $evidence_items, string $surface_key ): array {
		$request_context = $this->request_context_for_surface( $surface_key );

		foreach ( $evidence_items as $index => $evidence_item ) {
			$signal_key = (string) ( $evidence_item['signal_key'] ?? '' );
			$tier       = (string) ( $evidence_item['tier'] ?? '' );
			$context    = (string) ( $evidence_item['request_context'] ?? '' );
			$resource   = (string) ( $evidence_item['shared_resource'] ?? '' );
			$execution_surface = (string) ( $evidence_item['execution_surface'] ?? '' );

			$evidence_item['tier']            = $this->heuristics->normalize_tier( '' !== $tier ? $tier : $this->heuristics->tier_for( $signal_key ) );
			$evidence_item['request_context'] = '' !== $context ? $context : $request_context;
			$evidence_item['shared_resource'] = '' !== $resource ? $resource : $this->extract_shared_resource( $signal_key, (string) ( $evidence_item['message'] ?? '' ) );
			$evidence_item['execution_surface'] = '' !== $execution_surface ? $execution_surface : $this->extract_execution_surface( $signal_key, (string) ( $evidence_item['message'] ?? '' ) );
			$evidence_items[ $index ]         = $evidence_item;
		}

		return $evidence_items;
	}

	/**
	 * Keeps scoring focused on the strongest single request context.
	 *
	 * This prevents admin-only or cron-only signals from inflating a frontend
	 * finding, and vice versa.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return array<int, array<string, mixed>>
	 */
	private function select_primary_context_evidence( array $evidence_items ): array {
		$generic_contexts = array( '', 'runtime', 'generic site behavior' );
		$generic_items    = array();
		$context_groups   = array();

		foreach ( $evidence_items as $evidence_item ) {
			$request_context = strtolower( trim( (string) ( $evidence_item['request_context'] ?? '' ) ) );
			if ( in_array( $request_context, $generic_contexts, true ) ) {
				$generic_items[] = $evidence_item;
				continue;
			}

			$context_groups[ $request_context ][] = $evidence_item;
		}

		if ( empty( $context_groups ) ) {
			return $evidence_items;
		}

		$best_context = '';
		$best_score   = -1;
		$best_items   = $evidence_items;

		foreach ( $context_groups as $context => $items ) {
			$candidate_items = array_merge( $items, $generic_items );
			$candidate_score = $this->heuristics->score_evidence_items( $candidate_items );

			if ( $candidate_score > $best_score ) {
				$best_score   = $candidate_score;
				$best_context = (string) $context;
				$best_items   = $candidate_items;
			}
		}

		if ( '' === $best_context ) {
			return $evidence_items;
		}

		return $best_items;
	}

	/**
	 * Extracts the first shared resource from evidence items.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return string
	 */
	private function find_shared_resource( array $evidence_items ): string {
		foreach ( $evidence_items as $evidence_item ) {
			$shared_resource = (string) ( $evidence_item['shared_resource'] ?? '' );
			if ( '' !== $shared_resource ) {
				return $shared_resource;
			}
		}

		return '';
	}

	/**
	 * Extracts the first execution surface from evidence items.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return string
	 */
	private function find_execution_surface( array $evidence_items ): string {
		foreach ( $evidence_items as $evidence_item ) {
			$execution_surface = (string) ( $evidence_item['execution_surface'] ?? '' );
			if ( '' !== $execution_surface ) {
				return $execution_surface;
			}
		}

		return '';
	}

	/**
	 * Returns request context from evidence or surface defaults.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @param string                           $surface_key Surface key.
	 * @return string
	 */
	private function find_request_context( array $evidence_items, string $surface_key ): string {
		foreach ( $evidence_items as $evidence_item ) {
			$request_context = (string) ( $evidence_item['request_context'] ?? '' );
			if ( '' !== $request_context ) {
				return $request_context;
			}
		}

		return $this->request_context_for_surface( $surface_key );
	}

	/**
	 * Maps a surface to a request context label.
	 *
	 * @param string $surface_key Surface key.
	 * @return string
	 */
	private function request_context_for_surface( string $surface_key ): string {
		$contexts = array(
			'frontend_rendering'     => __( 'frontend', 'conflict-debugger' ),
			'asset_loading'          => __( 'frontend', 'conflict-debugger' ),
			'admin_screen'           => __( 'admin', 'conflict-debugger' ),
			'editor'                 => __( 'editor', 'conflict-debugger' ),
			'authentication_account' => __( 'login', 'conflict-debugger' ),
			'rest_api_ajax'          => __( 'REST/AJAX', 'conflict-debugger' ),
			'forms_submission'       => __( 'forms', 'conflict-debugger' ),
			'caching_optimization'   => __( 'frontend', 'conflict-debugger' ),
			'seo_metadata'           => __( 'frontend', 'conflict-debugger' ),
			'rewrite_routing'        => __( 'routing', 'conflict-debugger' ),
			'content_model'          => __( 'content model', 'conflict-debugger' ),
			'email_notifications'    => __( 'notifications', 'conflict-debugger' ),
			'security_access'        => __( 'login/admin', 'conflict-debugger' ),
			'background_processing'  => __( 'cron', 'conflict-debugger' ),
			'commerce_checkout'      => __( 'checkout/cart', 'conflict-debugger' ),
		);

		return $contexts[ $surface_key ] ?? __( 'runtime', 'conflict-debugger' );
	}

	/**
	 * Maps a request context label back to a surface.
	 *
	 * @param string $request_context Request context.
	 * @return string
	 */
	private function surface_for_context( string $request_context ): string {
		$request_context = strtolower( $request_context );

		if ( false !== strpos( $request_context, 'rest' ) || false !== strpos( $request_context, 'ajax' ) ) {
			return 'rest_api_ajax';
		}

		if ( false !== strpos( $request_context, 'login' ) ) {
			return 'authentication_account';
		}

		if ( false !== strpos( $request_context, 'editor' ) ) {
			return 'editor';
		}

		if ( false !== strpos( $request_context, 'checkout' ) || false !== strpos( $request_context, 'cart' ) || false !== strpos( $request_context, 'product' ) ) {
			return 'commerce_checkout';
		}

		if ( false !== strpos( $request_context, 'cron' ) ) {
			return 'background_processing';
		}

		if ( false !== strpos( $request_context, 'admin' ) ) {
			return 'admin_screen';
		}

		return 'frontend_rendering';
	}

	/**
	 * Extracts a shared resource label from detector messages.
	 *
	 * @param string $signal_key Signal key.
	 * @param string $message Evidence message.
	 * @return string
	 */
	private function extract_shared_resource( string $signal_key, string $message ): string {
		if ( preg_match( '/:\s(.+?)\.?$/', $message, $matches ) ) {
			return trim( (string) $matches[1] );
		}

		if ( preg_match( '/attach to the (.+?) hook/i', $message, $matches ) ) {
			return trim( (string) $matches[1] );
		}

		if ( 'recent_change' === $signal_key ) {
			return __( 'recent plugin changes', 'conflict-debugger' );
		}

		return '';
	}

	/**
	 * Extracts an execution surface label from detector messages.
	 *
	 * @param string $signal_key Signal key.
	 * @param string $message Evidence message.
	 * @return string
	 */
	private function extract_execution_surface( string $signal_key, string $message ): string {
		if ( preg_match( '/attach to the (.+?) hook/i', $message, $matches ) ) {
			return trim( (string) $matches[1] );
		}

		if ( 'callback_chain_churn' === $signal_key && preg_match( '/on\s(.+?)\sbut not/i', $message, $matches ) ) {
			return trim( (string) $matches[1] );
		}

		return '';
	}

	/**
	 * Sorts findings by severity rank, then confidence.
	 *
	 * @param array<string, mixed> $left Left finding.
	 * @param array<string, mixed> $right Right finding.
	 * @return int
	 */
	private function compare_findings( array $left, array $right ): int {
		$severity_compare = $this->heuristics->severity_rank( (string) ( $right['severity'] ?? 'info' ) ) <=> $this->heuristics->severity_rank( (string) ( $left['severity'] ?? 'info' ) );

		if ( 0 !== $severity_compare ) {
			return $severity_compare;
		}

		return (int) ( $right['confidence'] ?? 0 ) <=> (int) ( $left['confidence'] ?? 0 );
	}

	/**
	 * Adds a single surface evidence item.
	 *
	 * @param array<string, array<string, mixed>> $surface_map Surface map.
	 * @param string                              $surface_key Surface key.
	 * @param string                              $signal_key Signal key.
	 * @param string                              $message Evidence message.
	 * @param string                              $strength Evidence strength.
	 * @param array<string, mixed>               $meta Evidence metadata.
	 * @return void
	 */
	private function add_surface_evidence( array &$surface_map, string $surface_key, string $signal_key, string $message, string $strength, array $meta = array() ): void {
		if ( ! isset( $surface_map[ $surface_key ] ) ) {
			$surface_map[ $surface_key ] = array(
				'signal_keys'    => array(),
				'evidence_items' => array(),
			);
		}

		$surface_map[ $surface_key ]['signal_keys'][] = $signal_key;
		if ( '' !== $message ) {
			$surface_map[ $surface_key ]['evidence_items'][] = $this->create_evidence_item( $surface_key, $signal_key, $message, $strength, $meta );
		}
	}

	/**
	 * Merges precomputed pair analysis into the current surface map.
	 *
	 * @param array<string, array<string, mixed>> $surface_map Surface map.
	 * @param array<string, array<string, mixed>> $analysis Pair analysis.
	 * @return void
	 */
	private function merge_pair_analysis( array &$surface_map, array $analysis ): void {
		foreach ( $analysis as $surface_key => $surface_data ) {
			foreach ( (array) ( $surface_data['signal_keys'] ?? array() ) as $signal_key ) {
				$this->add_surface_evidence( $surface_map, (string) $surface_key, (string) $signal_key, '', 'context' );
			}

			foreach ( (array) ( $surface_data['evidence_items'] ?? array() ) as $evidence_item ) {
				$this->add_surface_evidence(
					$surface_map,
					(string) $surface_key,
					(string) ( $evidence_item['signal_key'] ?? 'surface_context_match' ),
					(string) ( $evidence_item['message'] ?? '' ),
					(string) ( $evidence_item['strength'] ?? 'medium' ),
					array(
						'tier'            => (string) ( $evidence_item['tier'] ?? '' ),
						'request_context' => (string) ( $evidence_item['request_context'] ?? '' ),
						'shared_resource' => (string) ( $evidence_item['shared_resource'] ?? '' ),
						'execution_surface' => (string) ( $evidence_item['execution_surface'] ?? '' ),
					)
				);
			}
		}
	}

	/**
	 * Adds an evidence item into a precomputed analysis array.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $results Analysis results.
	 * @param string                                             $pair_key Pair key.
	 * @param string                                             $surface_key Surface key.
	 * @param string                                             $signal_key Signal key.
	 * @param string                                             $message Evidence message.
	 * @param string                                             $strength Evidence strength.
	 * @param array<string, mixed>                               $meta Evidence metadata.
	 * @return void
	 */
	private function add_analysis_item( array &$results, string $pair_key, string $surface_key, string $signal_key, string $message, string $strength, array $meta = array() ): void {
		if ( ! isset( $results[ $pair_key ][ $surface_key ] ) ) {
			$results[ $pair_key ][ $surface_key ] = array(
				'signal_keys'    => array(),
				'evidence_items' => array(),
			);
		}

		$results[ $pair_key ][ $surface_key ]['signal_keys'][]    = $signal_key;
		$results[ $pair_key ][ $surface_key ]['evidence_items'][] = $this->create_evidence_item( $surface_key, $signal_key, $message, $strength, $meta );
	}

	/**
	 * Creates a structured evidence item.
	 *
	 * @param string $surface_key Surface key.
	 * @param string $signal_key Signal key.
	 * @param string $message Evidence message.
	 * @param string               $strength Strength label.
	 * @param array<string, mixed> $meta Evidence metadata.
	 * @return array<string, mixed>
	 */
	private function create_evidence_item( string $surface_key, string $signal_key, string $message, string $strength, array $meta = array() ): array {
		$tier = $meta['tier'] ?? '';
		if ( '' === $tier ) {
			$tier = $this->heuristics->tier_for( $signal_key );
		}

		return array_merge(
			array(
				'surface'           => $surface_key,
				'signal_key'        => $signal_key,
				'message'           => $message,
				'strength'          => $strength,
				'tier'              => $this->heuristics->normalize_tier( (string) $tier ),
				'request_context'   => $meta['request_context'] ?? '',
				'shared_resource'   => $meta['shared_resource'] ?? '',
				'execution_surface' => $meta['execution_surface'] ?? '',
			),
			$meta
		);
	}

	/**
	 * Removes duplicate evidence messages.
	 *
	 * @param array<int, array<string, string>> $evidence_items Evidence items.
	 * @return array<int, array<string, string>>
	 */
	private function deduplicate_evidence_items( array $evidence_items ): array {
		$unique = array();
		$seen   = array();

		foreach ( $evidence_items as $evidence_item ) {
			$key = (string) ( $evidence_item['surface'] ?? '' ) . '|' . (string) ( $evidence_item['signal_key'] ?? '' ) . '|' . (string) ( $evidence_item['message'] ?? '' );
			if ( isset( $seen[ $key ] ) || '' === (string) ( $evidence_item['message'] ?? '' ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$unique[]     = $evidence_item;
		}

		return $unique;
	}

	/**
	 * Checks whether a text contains addon dependency indicators.
	 *
	 * @param string $text Input text.
	 * @return bool
	 */
	private function contains_addon_keyword( string $text ): bool {
		$keywords = array( 'addon', 'add-on', 'extension', 'for ', 'integration', 'module' );

		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $text, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether plugin slugs share a meaningful prefix.
	 *
	 * @param string $slug_a Plugin A slug.
	 * @param string $slug_b Plugin B slug.
	 * @return bool
	 */
	private function shares_slug_prefix( string $slug_a, string $slug_b ): bool {
		if ( ! $slug_a || ! $slug_b ) {
			return false;
		}

		$parts_a = preg_split( '/[-_]/', $slug_a );
		$parts_b = preg_split( '/[-_]/', $slug_b );

		if ( empty( $parts_a ) || empty( $parts_b ) ) {
			return false;
		}

		$root_a = (string) ( $parts_a[0] ?? '' );
		$root_b = (string) ( $parts_b[0] ?? '' );

		return strlen( $root_a ) >= 4 && $root_a === $root_b;
	}

	/**
	 * Resolves plugin slug from a callback reflection.
	 *
	 * @param mixed $callback Callback.
	 * @return string|null
	 */
	private function resolve_plugin_slug_from_callback( mixed $callback ): ?string {
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
			return null;
		}

		return null;
	}

	/**
	 * Resolves a plugin slug from a file path or plugin asset URL.
	 *
	 * @param string $path File path or URL.
	 * @return string|null
	 */
	private function resolve_plugin_slug_from_path( string $path ): ?string {
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
			return null;
		}

		$relative = ltrim( substr( $normalized, strlen( $plugin_dir ) ), '/' );
		$parts    = explode( '/', $relative );

		return isset( $parts[0] ) ? sanitize_key( $parts[0] ) : null;
	}

	/**
	 * Groups asset sources into library families.
	 *
	 * @param string $source Source URL or path.
	 * @param string $handle Dependency handle.
	 * @return string|null
	 */
	private function classify_library( string $source, string $handle ): ?string {
		$text      = strtolower( $source . ' ' . $handle );
		$libraries = array( 'select2', 'swiper', 'slick', 'bootstrap', 'fontawesome', 'chartjs', 'jquery-ui' );

		foreach ( $libraries as $library ) {
			if ( false !== strpos( $text, $library ) ) {
				return $library;
			}
		}

		return null;
	}

	/**
	 * Classifies optimization-related assets.
	 *
	 * @param string $source Source URL or path.
	 * @param string $handle Dependency handle.
	 * @return string|null
	 */
	private function classify_optimization_asset( string $source, string $handle ): ?string {
		$text = strtolower( $source . ' ' . $handle );

		if ( false !== strpos( $text, 'lazy' ) ) {
			return __( 'lazy loading', 'conflict-debugger' );
		}

		if ( false !== strpos( $text, 'defer' ) || false !== strpos( $text, 'delay' ) ) {
			return __( 'script deferral/delay', 'conflict-debugger' );
		}

		if ( false !== strpos( $text, 'min' ) && ( false !== strpos( $text, 'css' ) || false !== strpos( $text, 'js' ) ) ) {
			return __( 'minification/deferred assets', 'conflict-debugger' );
		}

		return null;
	}

	/**
	 * Builds a stable pair key.
	 *
	 * @param string $left Left slug.
	 * @param string $right Right slug.
	 * @return string
	 */
	private function build_pair_key( string $left, string $right ): string {
		$pair = array( $left, $right );
		sort( $pair, SORT_STRING );
		return implode( ':', $pair );
	}
}
