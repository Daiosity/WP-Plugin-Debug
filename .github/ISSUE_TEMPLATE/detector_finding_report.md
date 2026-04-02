---
name: Detector finding review
about: Report a false positive, false negative, or misleading conflict finding.
title: "[Detector] "
labels: detector, triage
assignees: ""
---

## Finding summary

What did the detector report?

## Why it seems wrong or incomplete

Choose one or describe your own:

- false positive
- false negative
- overconfident severity
- wrong plugin attribution
- missing request context
- observer-artifact style noise

## Affected finding details

- Title:
- Severity:
- Confidence:
- Finding type:
- Request context:
- Shared resource, if shown:
- Execution surface, if shown:

## Plugins involved

- Plugin A:
- Plugin B:
- Any observer/debug plugin active?

## Reproduction context

- Site area affected:
- Can the issue be reproduced consistently?
- Did disabling one plugin change the outcome?

## Runtime evidence

Paste any relevant details from:

- recent runtime events
- request contexts
- JS errors
- failed AJAX or REST requests
- PHP errors

## What you expected the detector to say

Describe the more accurate output you expected.
