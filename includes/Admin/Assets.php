<?php
/**
 * Admin asset registration.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Assets {
	/**
	 * Hooks asset registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues assets only on the plugin screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'tools_page_conflict-debugger' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'pcd-admin',
			PCD_URL . 'assets/css/admin.css',
			array(),
			PCD_VERSION
		);

		wp_enqueue_script(
			'pcd-admin',
			PCD_URL . 'assets/js/admin.js',
			array(),
			PCD_VERSION,
			true
		);

		wp_localize_script(
			'pcd-admin',
			'pcdAdmin',
			array(
				'scanningLabel' => __( 'Running scan...', 'conflict-debugger' ),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'pcd_scan_ajax' ),
				'i18n'          => array(
					'starting'     => __( 'Starting background scan...', 'conflict-debugger' ),
					'error'        => __( 'Could not start the scan. Please try again.', 'conflict-debugger' ),
					'failed'       => __( 'Scan failed. Please review server logs and try again.', 'conflict-debugger' ),
					'completed'    => __( 'Scan complete. Refreshing results...', 'conflict-debugger' ),
					'running'      => __( 'Scan is running in the background.', 'conflict-debugger' ),
					'queued'       => __( 'Scan queued. Waiting for worker...', 'conflict-debugger' ),
					'runScan'      => __( 'Run Scan', 'conflict-debugger' ),
					'validationPairRequired'   => __( 'Choose at least one plugin before starting pair validation.', 'conflict-debugger' ),
					'validationTargetRequired' => __( 'Enter the hook, asset handle, route, or action you want to validate.', 'conflict-debugger' ),
				),
			)
		);
	}
}
