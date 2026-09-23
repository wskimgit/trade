# Frozen Baseline and Current Hotfix Line

## Historical frozen baseline

Baseline label: **3+1 Trade v1.4 FROZEN — 2026-09-22**

The last confirmed frozen execution line before the 2026-09-23 diagnostic hotfix was:

- Engine v4.4.5 — `trade-engine-v445-single-file-runtime-authority-20260922-r2`
- Broker v5.9.7 — `trade-broker-v597-single-file-runtime-authority-20260922-r2`
- Validation v1.3.1 — `trade-validation-v131-explicit-legacy-recovery-wal-20260921-r1`
- Runner v1.4.5 r4 — `trade-low-load-runner-v145-execution-truth-strategy-freshness-watchdog-20260922-r4`
- Runner Control v1.4.5 — `trade-low-load-runner-web-control-v145-startup-health-detail-20260922-r3`

Strategies remain unchanged:

- DTS — `dts-v254-market-timezone-hard-stale-recovery-contract-20260912-r1`
- ABC — `abc-v542-market-timezone-bar-date-20260912-r1`
- DAS — `das-v303-market-timezone-data-timestamp-20260912-r1`
- STC26 — `stc26-v301-daily-opportunity-shadow-20260910-r1`

## Current development hotfix line — 2026-09-23

A live UNIFIED export showed one long-lived JP SELL order that remained PENDING with a missing exchange code while Broker execution was stale. Source review also showed that Runner could classify an unapproved PENDING order as SESSION_CLOSED_HINT solely because the market was closed.

The minimal recovery line is:

- Engine v4.4.6 — `trade-engine-v446-sell-exchange-provenance-20260923-r1`
- Broker v5.9.10 — `trade-broker-v5910-jpx-builtin-calendar-parity-20260923-r1`
- Runner v1.4.6 — `trade-low-load-runner-v146-approval-actionable-gate-20260923-r1`
- Runner Control v1.4.6 — `trade-low-load-runner-web-control-v146-approval-actionable-gate-20260923-r1`
- Runner Status v1.4.6 — `trade-low-load-runner-status-v146-execution-truth-20260923-r1`
- Dashboard v3.4.11 — `trade-dashboard-v3411-header-cleanup-20260923-r2`
- Preflight v1.3.18 — `trade-3plus1-preflight-v1318-dashboard-header-cleanup-20260923-r2`
- Export v1.0.1 — `trade-export-v101-full-engine-config-mobile-safe-download-20260923-r1`

Validation and all four strategy files are unchanged.

## Operating authority

- Authority: `SINGLE_FILE_PAPER`
- REAL: false
- Runner remains scheduling authority.
- SWING remains retired.
- Legacy runtime copies remain non-authoritative.
- Active-position runtime state must be preserved.

## Semantics that must not regress

- Broker execution truth overrides stale canonical mirrors.
- Unapproved or approval-blocked PENDING orders are ACTIONABLE, not MARKET_WAIT.
- SESSION_CLOSED_HINT is only a wake hint for Broker-approved/submitted execution state.
- Missing US/JP exchange metadata may be resolved only from normalized configured/operational trade-list mappings; never invented.
- Broker exchange-map merges must preserve numeric JP symbol keys (`5803`, etc.); `array_merge()` is prohibited for these symbol-keyed maps.
- Broker and Runner JPX calendars must agree on built-in closure dates so an exchange holiday cannot be treated as an actionable execution session.
- New SELL intents recover venue metadata from their existing active position before intent signing when context lost it.
- No duplicate scheduler authority.
- No active runtime deletion while positions exist.
- Warning acknowledgement is separate from execution state.
- Runner Status must display Broker execution truth, not stale Canonical-only priority; MARKET_WAIT-only state permits strategy execution.

## Evidence rule

The 2026-09-23 hotfix is locally/CI verified until deployed. Do not call it production PASS until live Runner/Broker/strategy evidence is observed after deployment.
