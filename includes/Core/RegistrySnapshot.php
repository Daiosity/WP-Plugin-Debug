<?php
/**
 * Runtime registry snapshot collector.
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

final class RegistrySnapshot {
	/**
	 * Stored admin menu snapshot option.
	 */
	private const ADMIN_MENU_OPTION = 'pcd_admin_menu_snapshot';

	/**
	 * Stored shortcode ownership snapshot option.
	 */
	private const SHORTCODE_OPTION = 'pcd_shortcode_snapshot';

	/**
	 * Stored block type ownership snapshot option.
	 */
	private const BLOCK_OPTION = 'pcd_block_snapshot';

	/**
	 * Stored AJAX action ownership snapshot option.
	 */
	private const AJAX_OPTION = 'pcd_ajax_action_snapshot';

	/**
	 * Stored asset handle ownership snapshot option.
	 */
	private const ASSET_OPTION = 'pcd_asset_handle_snapshot';

	/**
	 * Registered post types observed in this request.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $post_type_registrations = array();

	/**
	 * Registered taxonomies observed in this request.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $taxonomy_registrations = array();

	/**
	 * Shortcode ownership snapshot.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $shortcode_snapshot = array();

	/**
	 * Block ownership snapshot.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $block_snapshot = array();

	/**
	 * AJAX action ownership snapshot.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $ajax_action_snapshot = array();

	/**
	 * Asset handle ownership snapshot.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $asset_snapshot = array();

	/**
	 * Hooks runtime collectors.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'registered_post_type', array( $this, 'capture_post_type' ), 10, 2 );
		add_action( 'registered_taxonomy', array( $this, 'capture_taxonomy' ), 10, 3 );
		add_action( 'init', array( $this, 'capture_shortcodes' ), 9999 );
		add_action( 'init', array( $this, 'capture_block_types' ), 9999 );
		add_action( 'init', array( $this, 'capture_ajax_actions' ), 9999 );
		add_action( 'admin_menu', array( $this, 'capture_admin_menus' ), 999 );
		add_action( 'wp_print_scripts', array( $this, 'capture_asset_handles' ), 999 );
		add_action( 'wp_print_styles', array( $this, 'capture_asset_handles' ), 999 );
		add_action( 'admin_print_scripts', array( $this, 'capture_asset_handles' ), 999 );
		add_action( 'admin_print_styles', array( $this, 'capture_asset_handles' ), 999 );
		add_action( 'login_enqueue_scripts', array( $this, 'capture_asset_handles' ), 999 );
	}

	/**
	 * Captures a registered post type event.
	 *
	 * WordPress passes raw registration args here, so we resolve the final
	 * runtime object from the global registry when available.
	 *
	 * @param string $post_type Post type key.
	 * @param mixed  $args Raw post type args.
	 * @return void
	 */
	public function capture_post_type( string $post_type, mixed $args = null ): void {
		$owner_slug = $this->detect_owner_from_backtrace();
		$data       = $this->normalize_registry_source( $this->get_registered_post_type_object( $post_type ), $args );

		$this->post_type_registrations[ $post_type ][] = array(
			'key'          => $post_type,
			'owner_slug'   => $owner_slug,
			'label'        => (string) ( $data['label'] ?? $post_type ),
			'rewrite_slug' => $this->normalize_rewrite_slug( $data['rewrite'] ?? false ),
			'rest_base'    => (string) ( $data['rest_base'] ?? '' ),
			'query_var'    => is_string( $data['query_var'] ?? null ) ? (string) $data['query_var'] : '',
		);
	}

	/**
	 * Captures a registered taxonomy event.
	 *
	 * WordPress passes object types plus raw args here, so we resolve the final
	 * runtime object from the global registry when available.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @param mixed  $object_type Object type or object types.
	 * @param mixed  $args Raw taxonomy args.
	 * @return void
	 */
	public function capture_taxonomy( string $taxonomy, mixed $object_type = null, mixed $args = null ): void {
		$owner_slug = $this->detect_owner_from_backtrace();
		$data       = $this->normalize_registry_source( $this->get_registered_taxonomy_object( $taxonomy ), $args );

		$this->taxonomy_registrations[ $taxonomy ][] = array(
			'key'          => $taxonomy,
			'owner_slug'   => $owner_slug,
			'label'        => (string) ( $data['label'] ?? $taxonomy ),
			'rewrite_slug' => $this->normalize_rewrite_slug( $data['rewrite'] ?? false ),
			'rest_base'    => (string) ( $data['rest_base'] ?? '' ),
			'query_var'    => is_string( $data['query_var'] ?? null ) ? (string) $data['query_var'] : '',
		);
	}

	/**
	 * Stores the current admin menu snapshot for later background scans.
	 *
	 * @return void
	 */
	public function capture_admin_menus(): void {
		global $menu, $submenu;

		$items = array();

		foreach ( (array) $menu as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
				continue;
			}

			$items[] = array(
				'slug'       => (string) $entry[2],
				'title'      => wp_strip_all_tags( (string) ( $entry[0] ?? '' ) ),
				'owner_slug' => $this->infer_owner_from_menu_slug( (string) $entry[2] ),
				'type'       => 'menu',
			);
		}

		foreach ( (array) $submenu as $entries ) {
			foreach ( (array) $entries as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
					continue;
				}

				$items[] = array(
					'slug'       => (string) $entry[2],
					'title'      => wp_strip_all_tags( (string) ( $entry[0] ?? '' ) ),
					'owner_slug' => $this->infer_owner_from_menu_slug( (string) $entry[2] ),
					'type'       => 'submenu',
				);
			}
		}

		update_option( self::ADMIN_MENU_OPTION, $items, false );
	}

	/**
	 * Stores the currently registered shortcode ownership map.
	 *
	 * @return void
	 */
	public function capture_shortcodes(): void {
		global $shortcode_tags;

		$snapshot = array();

		foreach ( (array) $shortcode_tags as $tag => $callback ) {
			$owner_slug = $this->resolve_plugin_slug_from_callback( $callback );
			if ( '' === $owner_slug ) {
				continue;
			}

			$snapshot[ sanitize_key( (string) $tag ) ] = array(
				'tag'        => sanitize_key( (string) $tag ),
				'owner_slug' => $owner_slug,
			);
		}

		$this->shortcode_snapshot = $snapshot;
		update_option( self::SHORTCODE_OPTION, $snapshot, false );
	}

	/**
	 * Stores the currently registered block type ownership map.
	 *
	 * @return void
	 */
	public function capture_block_types(): void {
		if ( ! class_exists( '\WP_Block_Type_Registry' ) ) {
			return;
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		if ( ! method_exists( $registry, 'get_all_registered' ) ) {
			return;
		}

		$snapshot = array();
		foreach ( (array) $registry->get_all_registered() as $block_name => $block_type ) {
			$owner_slug = $this->resolve_plugin_slug_from_callback( $block_type->render_callback ?? null );

			if ( '' === $owner_slug ) {
				$owner_slug = $this->resolve_plugin_slug_from_asset_handles(
					array_merge(
						(array) ( $block_type->editor_script_handles ?? array() ),
						(array) ( $block_type->script_handles ?? array() ),
						(array) ( $block_type->view_script_handles ?? array() ),
						(array) ( $block_type->style_handles ?? array() ),
						(array) ( $block_type->editor_style_handles ?? array() ),
					)
				);
			}

			if ( '' === $owner_slug ) {
				continue;
			}

			$snapshot[ sanitize_text_field( (string) $block_name ) ] = array(
				'name'       => sanitize_text_field( (string) $block_name ),
				'owner_slug' => $owner_slug,
				'title'      => sanitize_text_field( (string) ( $block_type->title ?? $block_name ) ),
			);
		}

		$this->block_snapshot = $snapshot;
		update_option( self::BLOCK_OPTION, $snapshot, false );
	}

	/**
	 * Stores AJAX action ownership by inspecting registered wp_ajax hooks.
	 *
	 * @return void
	 */
	public function capture_ajax_actions(): void {
		global $wp_filter;

		if ( ! is_array( $wp_filter ) && ! $wp_filter instanceof \ArrayAccess ) {
			return;
		}

		$snapshot = array();
		foreach ( array_keys( (array) $wp_filter ) as $hook_name ) {
			$hook_name = (string) $hook_name;
			$is_private = 0 === strpos( $hook_name, 'wp_ajax_' );
			$is_public  = 0 === strpos( $hook_name, 'wp_ajax_nopriv_' );

			if ( ! $is_private && ! $is_public ) {
				continue;
			}

			$action_name = $is_public ? substr( $hook_name, 16 ) : substr( $hook_name, 8 );
			if ( ! isset( $wp_filter[ $hook_name ] ) || ! $wp_filter[ $hook_name ] instanceof \WP_Hook ) {
				continue;
			}

			$owners = array();
			foreach ( $wp_filter[ $hook_name ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$owner_slug = $this->resolve_plugin_slug_from_callback( $callback['function'] ?? null );
					if ( '' !== $owner_slug ) {
						$owners[ $owner_slug ] = true;
					}
				}
			}

			if ( empty( $owners ) ) {
				continue;
			}

			$snapshot[ sanitize_key( $action_name ) ] = array(
				'action'      => sanitize_key( $action_name ),
				'owner_slugs' => array_keys( $owners ),
				'public'      => $is_public,
			);
		}

		$this->ajax_action_snapshot = $snapshot;
		update_option( self::AJAX_OPTION, $snapshot, false );
	}

	/**
	 * Stores asset handle ownership from currently registered scripts/styles.
	 *
	 * @return void
	 */
	public function capture_asset_handles(): void {
		$snapshot = $this->get_asset_snapshot();

		foreach ( array( 'script' => wp_scripts(), 'style' => wp_styles() ) as $type => $store ) {
			if ( ! $store || empty( $store->registered ) ) {
				continue;
			}

			foreach ( $store->registered as $handle => $dependency ) {
				if ( empty( $dependency->src ) ) {
					continue;
				}

				$owner_slug = $this->resolve_plugin_slug_from_path( (string) $dependency->src );
				if ( '' === $owner_slug ) {
					continue;
				}

				$snapshot[ sanitize_key( (string) $handle ) ] = array(
					'handle'     => sanitize_key( (string) $handle ),
					'owner_slug' => $owner_slug,
					'type'       => (string) $type,
				);
			}
		}

		$this->asset_snapshot = $snapshot;
		update_option( self::ASSET_OPTION, $snapshot, false );
	}

	/**
	 * Returns post type registrations captured in this request.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function get_post_type_registrations(): array {
		return $this->post_type_registrations;
	}

	/**
	 * Returns taxonomy registrations captured in this request.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function get_taxonomy_registrations(): array {
		return $this->taxonomy_registrations;
	}

	/**
	 * Returns the latest stored admin menu snapshot.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_admin_menu_snapshot(): array {
		$snapshot = get_option( self::ADMIN_MENU_OPTION, array() );
		return is_array( $snapshot ) ? $snapshot : array();
	}

	/**
	 * Returns the stored shortcode ownership snapshot.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_shortcode_snapshot(): array {
		$snapshot = ! empty( $this->shortcode_snapshot ) ? $this->shortcode_snapshot : get_option( self::SHORTCODE_OPTION, array() );
		return is_array( $snapshot ) ? $snapshot : array();
	}

	/**
	 * Returns the stored block type ownership snapshot.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_block_snapshot(): array {
		$snapshot = ! empty( $this->block_snapshot ) ? $this->block_snapshot : get_option( self::BLOCK_OPTION, array() );
		return is_array( $snapshot ) ? $snapshot : array();
	}

	/**
	 * Returns the stored AJAX action ownership snapshot.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_ajax_action_snapshot(): array {
		$snapshot = ! empty( $this->ajax_action_snapshot ) ? $this->ajax_action_snapshot : get_option( self::AJAX_OPTION, array() );
		return is_array( $snapshot ) ? $snapshot : array();
	}

	/**
	 * Returns the stored asset handle ownership snapshot.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_asset_snapshot(): array {
		$snapshot = ! empty( $this->asset_snapshot ) ? $this->asset_snapshot : get_option( self::ASSET_OPTION, array() );
		return is_array( $snapshot ) ? $snapshot : array();
	}

	/**
	 * Attempts to detect the owning plugin from backtrace.
	 *
	 * @return string
	 */
	private function detect_owner_from_backtrace(): string {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 );

		foreach ( $frames as $frame ) {
			$file = isset( $frame['file'] ) ? wp_normalize_path( (string) $frame['file'] ) : '';
			if ( ! $file || 0 !== strpos( $file, wp_normalize_path( WP_PLUGIN_DIR ) ) ) {
				continue;
			}

			$relative = ltrim( substr( $file, strlen( wp_normalize_path( WP_PLUGIN_DIR ) ) ), '/' );
			$parts    = explode( '/', $relative );

			if ( ! empty( $parts[0] ) && 'plugin-conflict-debugger' !== $parts[0] ) {
				return sanitize_key( (string) $parts[0] );
			}
		}

		return '';
	}

	/**
	 * Infers plugin owner from a menu slug when it references a plugin file.
	 *
	 * @param string $menu_slug Menu slug.
	 * @return string
	 */
	private function infer_owner_from_menu_slug( string $menu_slug ): string {
		$query_string = wp_parse_url( $menu_slug, PHP_URL_QUERY );
		if ( is_string( $query_string ) ) {
			parse_str( $query_string, $query_args );

			if ( ! empty( $query_args['page'] ) && is_string( $query_args['page'] ) ) {
				$menu_slug = $query_args['page'];
			}
		}

		$normalized = wp_normalize_path( $menu_slug );

		if ( false !== strpos( $normalized, '/' ) ) {
			$parts = explode( '/', $normalized );
			if ( ! empty( $parts[0] ) ) {
				return sanitize_key( (string) $parts[0] );
			}
		}

		return '';
	}

	/**
	 * Normalizes rewrite configuration to a plain slug string.
	 *
	 * @param mixed $rewrite Rewrite config.
	 * @return string
	 */
	private function normalize_rewrite_slug( mixed $rewrite ): string {
		if ( is_array( $rewrite ) && ! empty( $rewrite['slug'] ) ) {
			return (string) $rewrite['slug'];
		}

		if ( is_string( $rewrite ) ) {
			return $rewrite;
		}

		return '';
	}

	/**
	 * Returns the runtime post type object when available.
	 *
	 * @param string $post_type Post type key.
	 * @return object|null
	 */
	private function get_registered_post_type_object( string $post_type ): ?object {
		global $wp_post_types;

		$post_type_object = $wp_post_types[ $post_type ] ?? null;
		return is_object( $post_type_object ) ? $post_type_object : null;
	}

	/**
	 * Returns the runtime taxonomy object when available.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @return object|null
	 */
	private function get_registered_taxonomy_object( string $taxonomy ): ?object {
		global $wp_taxonomies;

		$taxonomy_object = $wp_taxonomies[ $taxonomy ] ?? null;
		return is_object( $taxonomy_object ) ? $taxonomy_object : null;
	}

	/**
	 * Flattens object and array registry sources into a consistent array.
	 *
	 * @param mixed ...$sources Registry sources.
	 * @return array<string, mixed>
	 */
	private function normalize_registry_source( mixed ...$sources ): array {
		$normalized = array();

		foreach ( $sources as $source ) {
			if ( is_object( $source ) ) {
				$normalized = array_merge( $normalized, get_object_vars( $source ) );
			} elseif ( is_array( $source ) ) {
				$normalized = array_merge( $normalized, $source );
			}
		}

		return $normalized;
	}

	/**
	 * Resolves a plugin owner from a callback.
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
	 * Resolves a plugin owner from block asset handles.
	 *
	 * @param array<int, string> $handles Asset handles.
	 * @return string
	 */
	private function resolve_plugin_slug_from_asset_handles( array $handles ): string {
		$assets = $this->get_asset_snapshot();

		foreach ( $handles as $handle ) {
			$handle = sanitize_key( (string) $handle );
			if ( ! empty( $assets[ $handle ]['owner_slug'] ) ) {
				return sanitize_key( (string) $assets[ $handle ]['owner_slug'] );
			}

			foreach ( array( wp_scripts(), wp_styles() ) as $store ) {
				if ( $store && ! empty( $store->registered[ $handle ]->src ) ) {
					$owner_slug = $this->resolve_plugin_slug_from_path( (string) $store->registered[ $handle ]->src );
					if ( '' !== $owner_slug ) {
						return $owner_slug;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Resolves a plugin slug from a file path or plugin asset URL.
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
