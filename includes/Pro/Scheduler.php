<?php
/**
 * Scheduled scan placeholder.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scheduler {
	/**
	 * Event hook reserved for future scheduled scans.
	 */
	public const EVENT_HOOK = 'pcd_schedule_scan_event';

	/**
	 * Returns placeholder metadata for future cron support.
	 *
	 * @return array<string, mixed>
	 */
	public function describe(): array {
		return array(
			'available' => false,
			'title'     => __( 'Scheduled Scans and Alerts', 'conflict-debugger' ),
			'message'   => __( 'Planned for premium. The architecture already reserves a cron hook for future scheduled scans and notifications.', 'conflict-debugger' ),
		);
	}
}
