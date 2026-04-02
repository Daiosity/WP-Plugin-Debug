<?php
/**
 * Main plugin composition root.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger;

use PluginConflictDebugger\Admin\Assets;
use PluginConflictDebugger\Admin\DashboardPage;
use PluginConflictDebugger\Admin\Notices;
use PluginConflictDebugger\Core\ConflictDetector;
use PluginConflictDebugger\Core\DiagnosticSessionRepository;
use PluginConflictDebugger\Core\Environment;
use PluginConflictDebugger\Core\ErrorCollector;
use PluginConflictDebugger\Core\Heuristics;
use PluginConflictDebugger\Core\RegistrySnapshot;
use PluginConflictDebugger\Core\ResultsRepository;
use PluginConflictDebugger\Core\RuntimeTelemetry;
use PluginConflictDebugger\Core\RuntimeTelemetryRepository;
use PluginConflictDebugger\Core\RuntimeMutationTracker;
use PluginConflictDebugger\Core\ScanStateRepository;
use PluginConflictDebugger\Core\Scanner;
use PluginConflictDebugger\Pro\ProPlaceholder;
use PluginConflictDebugger\Support\Capabilities;
use PluginConflictDebugger\Support\Logger;
use PluginConflictDebugger\Support\PluginChangeTracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	/**
	 * Boots the plugin.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->load_textdomain();

		$capabilities = new Capabilities();
		$repository   = new ResultsRepository();
		$telemetry    = new RuntimeTelemetryRepository();
		$scan_state   = new ScanStateRepository();
		$sessions     = new DiagnosticSessionRepository();
		$registry     = new RegistrySnapshot();
		$logger       = new Logger();
		$tracker      = new PluginChangeTracker();
		$environment  = new Environment();
		$collector    = new ErrorCollector( $logger, $telemetry, $sessions );
		$heuristics   = new Heuristics();
		$detector     = new ConflictDetector( $heuristics, $registry );
		$scanner      = new Scanner( $environment, $collector, $detector, $repository, $tracker );
		$runtime      = new RuntimeTelemetry( $telemetry, $registry, $sessions );
		$mutations    = new RuntimeMutationTracker( $telemetry, $registry );

		$assets       = new Assets();
		$dashboard    = new DashboardPage( $scanner, $repository, $scan_state, $sessions, $capabilities );
		$notices      = new Notices( $repository, $capabilities );
		$pro_features = new ProPlaceholder();

		$assets->register();
		$dashboard->register();
		$notices->register();
		$pro_features->register();
		$tracker->register();
		$registry->register();
		$runtime->register();
		$mutations->register();
	}

	/**
	 * Loads plugin translations.
	 *
	 * @return void
	 */
	private function load_textdomain(): void {
		load_plugin_textdomain(
			'plugin-conflict-debugger',
			false,
			dirname( PCD_BASENAME ) . '/languages'
		);
	}
}
