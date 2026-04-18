# Conflict Debugger

Conflict Debugger is a production-leaning WordPress diagnostics plugin focused on one job:

**helping site owners and developers find real plugin conflicts without wasting hours disabling plugins one by one.**

This project is being built as a practical product, not a generic "site health" clone. The detector is intentionally conservative, context-aware, and designed to earn trust by preferring exact interference signals over vague overlap.

## Why This Exists

WordPress sites often break in ways that are expensive to trace:

- frontend rendering breaks after a plugin update
- admin screens stop saving properly
- AJAX or REST requests start failing
- checkout, login, editor, or routing behavior changes unexpectedly
- several plugins appear suspicious, but no one knows where to start

Most troubleshooting still depends on manual trial and error. This plugin is meant to shorten that path by surfacing:

- where overlap is happening
- what shared resource may be involved
- which request context is affected
- whether the finding is weak, contextual, concrete, or observed breakage

## Product Positioning

**Promise:** Find likely plugin conflicts before you waste hours disabling plugins manually.

**Design principle:** false positives are worse than missing weak signals.

That means the detector does **not** treat common WordPress behavior as proof of conflict. Shared hooks, broad plugin categories, recent updates, and extreme priorities are weak signals only. High-confidence findings require concrete interference or observed breakage.

## Current Feature Set

### Core scanning

- manual scan trigger from the WordPress admin
- scan status tracking and persistent scan results
- environment snapshot capture
- recent plugin change awareness
- scan history for comparing results over time

### Conflict detection

- conflict-surface based reasoning instead of broad keyword guessing
- request-context awareness across frontend, admin, REST, AJAX, login, editor, cron, and commerce flows
- exact ownership capture for resources like AJAX actions, REST routes, shortcodes, blocks, and asset handles
- runtime mutation tracking for callback churn and asset dequeue or deregister behavior
- observer-artifact and global-anomaly classification to reduce false positives from tools like Query Monitor

### Runtime evidence

- recent request context capture
- lightweight runtime telemetry
- trace warnings kept separate from actual PHP, log, and request failures in scan summaries
- JS and failed-request evidence surfaced in diagnostics
- log access checks with graceful fallback when direct `debug.log` access is unavailable
- request trace comparison between the most abnormal captured trace and the closest calmer baseline

### Admin UX

- WordPress-native admin screen
- findings tab
- finding detail drilldowns with evidence strength and linked runtime traces
- diagnostics tab
- plugin-focused drilldown tab
- runtime events viewer
- focused diagnostic session workflow for reproducing one issue path at a time
- focused validation mode for one plugin pair, hook, asset handle, REST route, or AJAX action

## Screenshots

UI screenshots live in [`docs/screenshots/`](./docs/screenshots/).

Recommended gallery for the repo:

- dashboard overview
- findings tab with evidence-rich results
- diagnostics tab with runtime events
- plugin drilldown view
- focused diagnostic session workflow

This keeps the repository ready for portfolio presentation without cluttering the source tree.

## How The Detector Works

The detector reasons through this model:

`request -> hook -> callback -> resource -> mutation -> breakage`

It classifies evidence into four tiers:

1. **Weak overlap**
   - shared hooks
   - broad surface overlap
   - recent updates
   - extreme priorities on their own
2. **Contextual risk**
   - same request context
   - same sensitive workflow area
   - same hook family in a risky flow
3. **Concrete interference**
   - same exact resource
   - callback removal or replacement
   - asset deregister or dequeue conflicts
   - same AJAX action, REST route, shortcode, block, slug, or handle
4. **Observed breakage**
   - PHP runtime errors
   - JS failures
   - failed AJAX or REST requests
   - missing assets
   - request-scoped breakage evidence

Severity is capped deliberately:

- weak only: at most `low`
- weak plus contextual: at most `medium`
- `high` requires concrete interference
- `critical` requires observed breakage

## Installation

### WordPress Admin upload

1. Download the WordPress admin package from the project build output or release.
2. In WordPress, go to `Plugins > Add New > Upload Plugin`.
3. Upload the ZIP package.
4. Activate **Conflict Debugger**.
5. Open `Tools > Conflict Debugger`.

### Local development

Copy the plugin folder into:

```text
wp-content/plugins/conflict-debugger/
```

Then activate it from the WordPress admin.

## Releases

This repository includes a GitHub Actions workflow that can build release-ready plugin ZIP packages.

Release outputs:

- `conflict-debugger-wp-admin.zip`
- `conflict-debugger.zip`

The workflow can be triggered manually or from version tags, which makes the repository easier to maintain and present professionally.

## Regression Fixtures

Detector regression fixtures live in [`tests/fixtures/`](./tests/fixtures/). They provide small WordPress plugins for:

- normal admin overlap that should stay low/shared-surface
- asset lifecycle mutation
- callback removal
- REST route collision
- AJAX action collision

These fixtures are meant to keep detector trust high as the heuristics and tracing layers evolve. In this workspace, the LocalWP test site also includes helper scripts for resetting telemetry/debug logs and replaying authenticated admin requests so fixture runs stay repeatable.

## Repository Structure

```text
conflict-debugger/
|-- assets/
|-- docs/
|-- includes/
|   |-- Admin/
|   |-- Core/
|   |-- Pro/
|   `-- Support/
|-- languages/
|-- tools/
|-- AGENTS.md
|-- CHANGELOG.md
|-- conflict-debugger.php
|-- readme.txt
`-- uninstall.php
```

## Development Notes

- PHP 8.1+ compatible
- namespaced OOP architecture
- WordPress coding standards mindset
- capability checks, nonces, sanitization, and escaping throughout admin actions
- premium-ready structure without faking premium functionality

## Roadmap

Near-term priorities:

- stronger callback actor attribution so removal events can graduate from trace warnings to conservative pairwise findings when the mutator is proven
- scan diff UX that highlights new findings, resolved findings, and confidence changes
- deeper exact ownership mapping
- improved plugin-focused diagnostics

See [TASKS.md](./TASKS.md) for the actively maintained implementation list.
- safer staging-oriented isolation workflows

Longer-term premium-oriented direction:

- safe test mode
- binary-search conflict isolation
- scheduled scans and alerts
- staging-focused diagnostics and remediation guidance

## Changelog

Project release history lives in:

- [`CHANGELOG.md`](./CHANGELOG.md)
- [`readme.txt`](./readme.txt)

## Repository Hygiene

This repository is maintained as a real product project and portfolio-quality codebase. Changes should favor:

- trustworthy diagnostics over noisy heuristics
- clean structure over quick fixes
- meaningful documentation
- specific commit history
- practical product value
