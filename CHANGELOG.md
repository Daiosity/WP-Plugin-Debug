# Changelog

All notable changes to `Conflict Debugger` are tracked here.

## 1.1.3

- Renamed the plugin slug, distributable folder, ZIP outputs, admin page slug, and text domain from `plugin-conflict-debugger` to `conflict-debugger`.
- Finished the WordPress.org Plugin Check cleanup with the slug rename, leaving the project clear of the restricted `plugin` term in its public identity.
- Updated LocalWP install expectations, build outputs, and repo docs so GitHub remains the canonical source and download location for releases.

## 1.1.2

- Started a dedicated WordPress.org Plugin Check cleanup pass by fixing bootstrap metadata, GPL license headers, and the plugin readme compatibility fields.
- Replaced the hidden `languages/.gitkeep` placeholder with a WordPress-safe `languages/index.php` file and removed production debug logging from bootstrap failure handling.
- Began hardening request/input and i18n code paths so the dashboard, telemetry, and tracer layers are friendlier to Plugin Check and future review.

## 1.1.1

- Split trace-level mutation warnings out of the main error-signal summary so asset and callback tracing no longer inflate the top-line runtime error count.
- Boot the plugin early enough to capture callback-mutation baselines before later plugins alter sensitive hooks such as `template_redirect`.
- Added repeatable LocalWP regression-lab helpers for resetting telemetry/debug logs and replaying authenticated admin requests against fixture scenarios.

## 1.1.0

- Added focused validation mode controls so a developer can narrow telemetry to one plugin pair, hook, asset handle, REST route, or AJAX action before rerunning a scan.
- Added finding detail drilldowns with evidence strength, scoring rationale, concrete resource/context metadata, and direct links to matching runtime events.
- Added detector fixtures for noisy admin overlap, asset lifecycle mutation, callback removal, REST route collision, and AJAX action collision regression checks.

## 1.0.27

- Added a causal trace-event foundation with per-request IDs, request scopes, attribution status, mutation status, and before/after state payloads so runtime evidence can be tied back to one concrete execution path.
- Added a conservative asset lifecycle tracer that records plugin-owned handle registrations, queue changes, and state mutations with mutator attribution when it can be narrowed safely.
- Expanded the diagnostics runtime viewer to surface request scope, actor attribution, target owners, and state changes so developers can inspect "what changed, where, and by whom" more directly.

## 1.0.28

- Added a root `TASKS.md` file so the next diagnostics milestones are tracked directly in the repository.
- Reworked callback mutation tracing to record removed, replaced, and priority-shifted callbacks with request scope, attribution state, and before/after callback snapshots.
- Tightened callback mutation scoring so pairwise escalation only happens when callback mutation evidence has real actor attribution.

## 1.0.26

- Added strict hard gates so findings without strong proof cannot rise above Medium unless pair-specific runtime breakage is directly linked to the same context, execution surface, concrete resource, and request path.
- Normalized common admin lifecycle hooks like `admin_menu`, `admin_init`, `current_screen`, `admin_enqueue_scripts`, and `load-post.php` so broad admin overlap no longer inflates into probable conflicts.
- Split runtime telemetry into generic runtime noise versus pair-specific runtime breakage, and downranked third-party contaminated admin clues into soft support instead of direct pair evidence.

## 1.0.25

- Refactored detector scoring around WordPress-aware evidence strength so common hooks, broad surfaces, extreme priorities, and mixed-context overlap do not inflate into serious findings by themselves.
- Added stricter context-purity selection so findings are scored from the strongest single request context instead of blending unrelated admin, frontend, REST, or cron clues.
- Added clearer finding categories, evidence-strength breakdowns, and scoring explanations to make the dashboard more trustworthy for developers reviewing noisy overlap cases.

## 1.0.24

- Added a direct loopback worker fallback so scans can start even when the site does not reliably trigger the queued WP-Cron worker.
- Added queued-scan recovery during status polling so long-stuck queued jobs can self-recover instead of waiting indefinitely.

## 1.0.23

- Added request trace comparison in Diagnostics so reproduced sessions can compare the most abnormal captured trace against the closest calmer baseline.
- Added a scan-time trace snapshot model that groups request contexts and runtime events into comparable traces with resource, owner, surface, and failure deltas.
- Added compact trace snapshots to scan history storage so trace-aware diagnostics can expand cleanly later.

## 1.0.22

- Added a focused Diagnostic Session workflow so users can target one site area, reproduce the issue, and capture session-tagged telemetry for that trace.
- Tagged request contexts and runtime events with diagnostic session IDs so the runtime viewer can collapse around the active or latest session instead of unrelated recent traffic.
- Added start/end session controls in Diagnostics and session-aware runtime event filtering in the dashboard.

## 1.0.21

- Added a Recent Runtime Events viewer in Diagnostics for JavaScript errors, failed requests, and mutation signals captured during recent site activity.
- Linked findings to matching runtime events so you can jump from a flagged interaction to the concrete telemetry behind it.
- Stored structured runtime events separately in scan results so the dashboard can review observed breakage without flattening everything into generic error rows.

## 1.0.20

- Added a plugin drilldown tab so you can inspect one plugin at a time, including its current findings, related plugins, categories, and request contexts.
- Added a compare-scans panel that highlights new and resolved findings between the latest two scans.
- Polished the dashboard styles and interactions so the new drilldown workflow is easier to navigate.

## 1.0.19

- Added a Log Access Check diagnostics panel that explains whether `debug.log` is enabled, exists, and is readable by PHP.
- Added lightweight scan history so recent scan outcomes can be compared over time from within the dashboard.

## 1.0.18

- Replaced archive creation with normalized ZIP entry generation so package paths use standard forward slashes instead of Windows backslashes during extraction.

## 1.0.17

- Fixed the host-friendly ZIP build so `conflict-debugger.zip` is truly flat at archive root while `conflict-debugger-wp-admin.zip` keeps the folder-inside structure for standard WordPress uploads.

## 1.0.16

- Renamed packaging outputs so the flat host-friendly ZIP now uses the plain plugin slug filename and the folder-inside package is clearly labeled for WP Admin uploads.
- Reduced duplicate diagnostics wording by keeping the log-access warning out of the analysis notes list when it is already shown as the top notice.

## 1.0.15

- Redesigned the admin dashboard with full-width findings and WordPress-style tabs for findings, diagnostics, and pro preview.
- Moved recent request contexts into a dedicated diagnostics view and improved long URL wrapping so request data no longer overflows its panel.
- Promoted site status into the summary row so key scan signals are visible without crowding the findings layout.

## 1.0.14

- Added two packaging targets: a standard WordPress uploader ZIP and a host-extract ZIP for control panels that create their own destination folder during extraction.

## 1.0.13

- Restored strict WordPress-standard ZIP packaging with a single `conflict-debugger/` folder inside the archive so subfolders like `includes/` and `assets/` install correctly.

## 1.0.12

- Added observer/debug plugin awareness so Query Monitor-style tooling is treated more conservatively than ordinary business-logic plugins.
- Grouped repeated callback-churn fingerprints into observer-artifact/global-anomaly findings instead of over-attributing them as multiple confirmed pairwise conflicts.
- Split execution surface from shared resource and tightened confirmed scoring so callback snapshot churn cannot become a 100% confirmed pairwise conflict without direct pair-specific causality.

## 1.0.11

- Added a strict standard-release build script that packages only the WordPress plugin files into a single top-level `conflict-debugger` folder.
- Standardized the rolling install ZIP to the WordPress-native folder-inside-zip format only.

## 1.0.10

- Added a safer bootstrap fallback so the plugin does not hard-fatal if a host extracts the ZIP into an unusual structure.
- Switched release packaging guidance back to the standard WordPress format with a single top-level plugin folder inside the ZIP.

## 1.0.9

- Added runtime mutation tracking for callback removal/replacement on sensitive hooks and asset queue/deregister mutations after enqueue.
- Integrated mutation events into the detector as concrete interference signals instead of treating them as generic overlap.
- Improved pair matching for runtime events by carrying owner slugs alongside resource hints.

## 1.0.8

- Added exact ownership snapshots for shortcode tags, block types, AJAX actions, and asset handles.
- Added request resource hints so runtime telemetry can associate observed breakage with concrete resources active on the affected request.
- Improved observed-breakage matching by using resource ownership maps instead of relying only on plugin names appearing in logs.

## 1.0.7

- Added lightweight request-context capture for frontend, admin, login, REST, AJAX, editor, checkout/cart/product, and cron requests.
- Added observed-breakage collection for JavaScript errors, failed same-origin REST/AJAX responses, missing assets, fatal runtime errors, and HTTP 4xx/5xx responses.
- Integrated recent request contexts and runtime telemetry into scan analysis to make findings more request-aware and trustworthy.

## 1.0.6

- Refactored scoring around weak overlap, contextual risk, concrete interference, and observed breakage tiers.
- Added strict severity caps so shared hooks, shared surfaces, recent updates, and extreme priorities cannot escalate into false critical findings.
- Updated finding wording and dashboard output to distinguish overlap, risk, interference, and confirmed conflict signals.

## 1.0.5

- Added exact registry-based conflict detection for duplicate post type keys, taxonomy keys, rewrite slugs, REST bases, query vars, and admin menu/page slugs.
- Hardened runtime registry collection so WordPress hook argument differences do not break diagnostics.
- Improved uninstall cleanup to remove stored registry snapshots.

## 1.0.4

- Refactored conflict detection around broader WordPress conflict surfaces instead of WooCommerce-heavy logic.
- Added structured evidence items and affected-area output for frontend, admin, editor, login, API/AJAX, forms, caching, SEO, routing, content model, notifications, security, and background jobs.
- Expanded plugin category inference to support more generic WordPress site types.

## 1.0.3

- Improved findings table readability with wider explanation/action columns and expandable evidence.
- Added addon-parent relationship awareness to reduce false positives in likely extension/plugin-suite pairs.

## 1.0.2

- Improved scan UX: scans now run in background with progress polling.
- Fixed completion refresh loop by preventing repeated reloads per scan token.
- Updated plugin metadata author and version.

## 1.0.0

- Initial production-leaning release with manual scans, heuristic findings, dashboard UI, and pro-ready architecture.
