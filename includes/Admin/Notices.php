<?php
/**
 * Admin notices.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Admin;

use PluginConflictDebugger\Core\ResultsRepository;
use PluginConflictDebugger\Support\Capabilities;
use PluginConflictDebugger\Support\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Notices {
	/**
	 * Results repository.
	 *
	 * @var ResultsRepository
	 */
	private ResultsRepository $repository;

	/**
	 * Capability service.
	 *
	 * @var Capabilities
	 */
	private Capabilities $capabilities;

	/**
	 * Constructor.
	 *
	 * @param ResultsRepository $repository Results repository.
	 * @param Capabilities      $capabilities Capability service.
	 */
	public function __construct( ResultsRepository $repository, Capabilities $capabilities ) {
		$this->repository   = $repository;
		$this->capabilities = $capabilities;
	}

	/**
	 * Hooks notices.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Renders notice after scan or when critical findings exist.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->capabilities->can_manage() ) {
			return;
		}

		$scan_notice = get_transient( 'pcd_scan_notice' );
		if ( is_array( $scan_notice ) ) {
			delete_transient( 'pcd_scan_notice' );

			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $scan_notice['type'] ?? 'info' ),
				esc_html( $scan_notice['message'] ?? '' )
			);
		}

		$results = $this->repository->get_latest();
		if ( empty( $results ) ) {
			return;
		}

		$critical_count = (int) ( $results['severity_counts']['critical'] ?? 0 );
		$scanned_at     = isset( $results['scan_timestamp'] ) ? Helpers::format_datetime( (string) $results['scan_timestamp'] ) : '';

		if ( $critical_count < 1 ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: critical finding count, 2: scan date. */
			__( 'Conflict Debugger found %1$d critical issue signal(s) in the latest scan (%2$s). Review the findings before changing plugin settings on production.', 'conflict-debugger' ),
			$critical_count,
			$scanned_at
		);

		printf(
			'<div class="notice notice-error"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html( $message ),
			esc_url( admin_url( 'tools.php?page=conflict-debugger' ) ),
			esc_html__( 'Open dashboard', 'conflict-debugger' )
		);
	}
}
