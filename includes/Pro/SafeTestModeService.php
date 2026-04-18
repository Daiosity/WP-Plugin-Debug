<?php
/**
 * Safe test mode service placeholder.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SafeTestModeService {
	/**
	 * Returns placeholder capability metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function describe(): array {
		return array(
			'available' => false,
			'title'     => __( 'Safe Test Mode', 'conflict-debugger' ),
			'message'   => __( 'Planned for premium. This mode should only simulate plugin isolation in staging or an explicitly approved diagnostic sandbox.', 'conflict-debugger' ),
		);
	}
}
