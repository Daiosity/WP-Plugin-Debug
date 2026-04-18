# Conflict Debugger Agent Guide

## Purpose

This repository builds a WordPress plugin whose job is to detect real plugin conflicts with conservative, developer-trustworthy reasoning.

The plugin promise is:

"Find likely plugin conflicts before you waste hours disabling plugins manually."

False positives are worse than missing weak signals.

## Core Rules

1. Do not treat shared presence as proof of conflict.
2. Shared hooks, broad surfaces, recent updates, and extreme priorities are weak signals only.
3. High-severity findings require concrete interference.
4. Critical findings require observed breakage or direct override/removal causing failure.
5. Prefer exact shared resources over keyword overlap.
6. Prefer same-request-context evidence over global assumptions.

## Evidence Hierarchy

### Weak Overlap

Examples:
- shared common hooks
- broad surface overlap
- recent updates
- extreme priorities by themselves

Weak overlap is not a conflict.

### Contextual Risk

Examples:
- same request context
- same hook family in a sensitive flow
- same surface in admin, editor, REST, AJAX, login, checkout, or cron

Contextual risk is still not proof of conflict.

### Concrete Interference

Examples:
- same script/style handle
- same REST route
- same AJAX action
- same option/meta/transient key
- same CPT/taxonomy/rewrite slug
- same shortcode or block name
- callback removal/replacement
- asset dequeue/deregister mutation

This is where real conflict findings begin.

### Observed Breakage

Examples:
- PHP fatal, warning, or runtime error
- JavaScript error or unhandled rejection
- failed REST or AJAX response
- missing asset
- redirect loop or access failure
- reproducible breakage tied to a request context

Observed breakage is required for critical severity.

## Severity Rules

- Weak only: max severity is `low`
- Weak + contextual: max severity is `medium`
- Concrete interference: can be `high`
- Observed breakage: can be `critical`

Never mark something critical from:
- shared hooks
- shared surfaces
- recent updates
- priority differences alone

## Request Context Rules

Always reason in request context:
- frontend
- admin
- login
- REST
- AJAX
- block editor
- Elementor editor
- checkout/cart/product
- cron

If plugins do not overlap in the same request context, do not escalate.

## Resource-First Detection

Prefer exact ownership and mutation tracking for:
- script/style handles
- REST routes
- AJAX actions
- shortcode tags
- block names
- CPT/taxonomy keys
- rewrite slugs
- admin menu/page slugs
- callback mutation on sensitive hooks

The target mental model is:

request -> hook -> callback -> resource -> mutation -> breakage

## Wording Rules

Avoid:
- "Conflict likely" without strong evidence
- "Critical issue" without breakage
- "Security issue" without real access logic

Prefer:
- "Potential interaction"
- "Shared runtime surface"
- "Elevated risk"
- "Concrete interference detected"
- "Confirmed conflict"

## UI Expectations

The plugin should stay:
- conservative
- practical
- clear
- WordPress-native
- useful to both site owners and developers

Findings should explain:
- what is shared
- where it happens
- why it may matter
- whether it is actionable
- what to test next

## Engineering Expectations

- Use PHP 8.1+ compatible code
- Follow WordPress coding standards
- Use namespaced OOP code
- Escape output
- Sanitize input
- Verify nonces and capabilities
- Degrade gracefully when data is unavailable
- Keep the free version useful without faking premium behavior

## Repository Workflow

When changing the detector:
- prefer conservative scoring changes
- improve exact ownership mapping before broadening heuristics
- keep request-context awareness intact
- preserve severity hard caps
- add structured evidence, not vague messaging

When changing runtime telemetry:
- keep it lightweight
- avoid breaking requests
- avoid noisy logging
- store only recent useful telemetry

When shipping changes:
- run PHP lint on changed files
- update plugin version
- update readme stable tag and changelog
- rebuild install zip

## Priority for Future Work

1. Increase precision before increasing breadth
2. Prefer exact ownership and mutation capture
3. Prefer observed breakage over inferred suspicion
4. Keep the detector trustworthy even if it becomes more conservative

