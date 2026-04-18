# Conflict Debugger Task List

This task list keeps the next diagnostics milestones concrete and visible in the repository.

## Current Focus

- [x] Causal trace event foundation and request-scope-aware runtime telemetry
- [x] Asset lifecycle tracing with owner and mutator attribution states
- [x] Callback mutation tracing foundation with request scope and partial actor attribution
- [x] Callback mutation validation mode and deeper actor attribution
- [x] Finding detail view that links one finding to its exact trace, evidence, and score caps
- [x] LocalWP regression lab reset/request helpers for repeatable fixture runs

## Next Up

- [x] Focused validation controls for one plugin pair, one hook, one asset handle, one REST route, or one AJAX action
- [x] Detector fixtures for known-good and known-bad conflict patterns
- [ ] Scan diff UX that highlights new findings, resolved findings, and confidence changes between scans
- [ ] Improved direct log diagnostics with clearer fallback reasons and alternate path support

## Product Polish

- [ ] Add real dashboard screenshots to `docs/screenshots/`
- [ ] Tag the first GitHub release with packaged install ZIP assets
- [ ] Add GitHub labels and milestones for detector, telemetry, UI, packaging, and docs work

## Premium-Ready Follow-Up

- [ ] Staging-only safe isolation workflow
- [ ] Pair-scoped validation replay and comparison
- [ ] Scheduled scans and alerts once trace quality is stable
