<?php
/**
 * Capability handling.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Capabilities {
	/**
	 * Returns required capability.
	 *
	 * @return string
	 */
	public function required_capability(): string {
		return 'manage_options';
	}

	/**
	 * Checks whether current user can manage plugin data.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( $this->required_capability() );
	}
}
