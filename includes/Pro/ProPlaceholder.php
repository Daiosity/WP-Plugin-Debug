<?php
/**
 * Pro-ready extension points and stubs.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProPlaceholder {
	/**
	 * Safe test mode placeholder.
	 *
	 * @var SafeTestModeService
	 */
	private SafeTestModeService $safe_test_mode;

	/**
	 * Auto isolator placeholder.
	 *
	 * @var AutoIsolator
	 */
	private AutoIsolator $auto_isolator;

	/**
	 * Scheduler placeholder.
	 *
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->safe_test_mode = new SafeTestModeService();
		$this->auto_isolator  = new AutoIsolator();
		$this->scheduler      = new Scheduler();
	}

	/**
	 * Hooks placeholder extension points.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( Scheduler::EVENT_HOOK, array( $this, 'scheduled_scan' ) );
	}

	/**
	 * Stub for scheduled scan support.
	 *
	 * Future pro implementation should call the scanner service here and
	 * dispatch alert notifications after evaluating the result threshold.
	 *
	 * @return void
	 */
	public function scheduled_scan(): void {
		// Intentionally left blank for the free/core foundation.
	}

	/**
	 * Safe test mode concept placeholder.
	 *
	 * This should only execute in staging or an explicitly approved sandboxed
	 * diagnostic mode where plugin deactivation can be simulated safely.
	 *
	 * @return array<string, mixed>
	 */
	public function safe_test_mode_stub(): array {
		return $this->safe_test_mode->describe();
	}

	/**
	 * Binary-search auto-isolation placeholder.
	 *
	 * Future implementation direction:
	 * 1. Snapshot active plugins.
	 * 2. Split the candidate set into halves.
	 * 3. Test each half in an isolated environment.
	 * 4. Repeat until the smallest reproducible set remains.
	 *
	 * @return array<string, mixed>
	 */
	public function auto_isolate_stub(): array {
		return $this->auto_isolator->describe();
	}
}
