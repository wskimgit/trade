# Source Inventory

Current ChatGPT → Codex handoff inventory.

## Imported exact current baseline sources

| File | Baseline identity | Status |
|---|---|---|
| src/trade_engine.php | v4.4.5 / trade-engine-v445-single-file-runtime-authority-20260922-r2 | IMPORTED |
| src/trade_broker.php | v5.9.7 / trade-broker-v597-single-file-runtime-authority-20260922-r2 | IMPORTED |
| src/trade_runner.php | v1.4.5 / strategy-freshness-watchdog r4 | IMPORTED |
| src/trade_runner_control.php | v1.4.5 / startup-health-detail r3 | IMPORTED |
| src/trade_dashboard.php | v3.4.8 / trade-dashboard-v348-alert-ack-state-machine-20260922-r1 | IMPORTED |
| src/trade_3plus1_preflight.php | v1.3.13 / trade-3plus1-preflight-v1313-dashboard-alert-ack-contract-20260922-r1 | IMPORTED |
| src/trade_list.php | v1.9.0 / trade_universe_contract_v2 | IMPORTED |
| src/dts.php | dts-v254-market-timezone-hard-stale-recovery-contract-20260912-r1 | IMPORTED |
| src/abc.php | abc-v542-market-timezone-bar-date-20260912-r1 | IMPORTED |
| src/das.php | das-v303-market-timezone-data-timestamp-20260912-r1 | IMPORTED |
| src/stc26.php | stc26-v301-daily-opportunity-shadow-20260910-r1 | IMPORTED |

## Pending exact source

| File | Required identity | Status |
|---|---|---|
| src/trade_validation.php | v1.3.1 / trade-validation-v131-explicit-legacy-recovery-wal-20260921-r1 | PENDING IMPORT |

The exact v1.3.1 Validation source is known from the final verified deployment package, but has not yet been placed in this public repository as a standalone source file.

**Do not substitute an older v1.2.6 Validation file. Do not reconstruct or invent the missing source.**

Until the exact v1.3.1 file is imported:

- repository-local full Preflight is expected to be incomplete,
- Codex may inspect and improve unrelated files,
- Codex must not make Validation semantic changes,
- Codex must not claim the whole frozen source set is complete.

## Intentionally excluded

The following are not source-of-truth code and must not be copied from live NAS into this public repository:

- real runtime state,
- broker/order/position logs,
- credentials and local broker config,
- tokens and certificates,
- account-specific snapshots,
- live trade_single_compat runtime contents.

Use synthetic fixtures for tests.
