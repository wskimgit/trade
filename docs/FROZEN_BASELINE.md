# Frozen Baseline

Baseline label: **3+1 Trade v1.4 FROZEN**

This file records the last known stable development contract transferred from ChatGPT.

## Execution baseline

- trade_engine.php: v4.4.5
  - revision: `trade-engine-v445-single-file-runtime-authority-20260922-r2`
- trade_broker.php: v5.9.7
  - revision: `trade-broker-v597-single-file-runtime-authority-20260922-r2`
- trade_validation.php: v1.3.1
  - revision: `trade-validation-v131-explicit-legacy-recovery-wal-20260921-r1`
- trade_runner.php: v1.4.5 r4
- trade_runner_control.php: v1.4.5

Strategies:

- DTS: `dts-v254-market-timezone-hard-stale-recovery-contract-20260912-r1`
- ABC: `abc-v542-market-timezone-bar-date-20260912-r1`
- DAS: `das-v303-market-timezone-data-timestamp-20260912-r1`
- STC26: `stc26-v301-daily-opportunity-shadow-20260910-r1`

Current dashboard handoff line:

- Dashboard: v3.4.8 ALERT-ACK
  - revision: `trade-dashboard-v348-alert-ack-state-machine-20260922-r1`
- Preflight: v1.3.13
  - revision: `trade-3plus1-preflight-v1313-dashboard-alert-ack-contract-20260922-r1`

## Operating authority

- Authority: `SINGLE_FILE_PAPER`
- REAL: false
- Runner is scheduling authority.
- Broker policy: EVENT / low-load runner controlled.
- SWING: retired.
- Legacy runtime copies are non-authoritative.
- Active positions require preservation of relevant strategy runtime state.

## Cadence

- DTS: 60 seconds
- ABC: 1200 seconds
- DAS: 1200 seconds
- STC26: 1200 seconds

Runner/Broker cadence is low-load and event-aware; exact current timing must be verified from source/runtime before modification.

## Semantics that must not regress

- No duplicate scheduler authority.
- No canonical-state resurrection after terminal execution truth.
- Actionable Broker work must retrigger within a bounded interval.
- MARKET_WAIT + actionable=0 is informational/normal.
- Strategy stale watchdog must prevent indefinite starvation.
- Engine runtime identity should come from runtime evidence with explicit source priority.
- Warning acknowledgement is separate from execution state.
- Public repository must not contain credentials or real runtime state.

## Production evidence rule

This document is a development handoff baseline, not live production evidence.

Codex must not claim a production PASS unless live runtime output is independently observed after deployment.
