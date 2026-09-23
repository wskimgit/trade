# Source Inventory

Current ChatGPT → Codex handoff inventory.

## Imported exact current baseline sources

| File | Baseline identity | Status |
|---|---|---|
| src/trade_engine.php | v4.4.5 / trade-engine-v445-single-file-runtime-authority-20260922-r2 | IMPORTED |
| src/trade_broker.php | v5.9.7 / trade-broker-v597-single-file-runtime-authority-20260922-r2 | IMPORTED |
| src/trade_runner.php | v1.4.5 / strategy-freshness-watchdog r4 | IMPORTED |
| src/trade_runner_control.php | v1.4.5 / startup-health-detail r3 | IMPORTED |
| src/trade_dashboard.php | v3.4.9 / trade-dashboard-v349-dedicated-export-route-20260923-r1 | IMPORTED |
| src/trade_3plus1_preflight.php | v1.3.15 / trade-3plus1-preflight-v1315-export-full-config-contract-20260923-r1 | IMPORTED |\n| src/trade_export.php | v1.0.1 / trade-export-v101-full-engine-config-mobile-safe-download-20260923-r1 | IMPORTED |
| src/trade_validation.php | v1.3.1 / trade-validation-v131-explicit-legacy-recovery-wal-20260921-r1 | IMPORTED |
| src/trade_list.php | v1.9.0 / trade_universe_contract_v2 | IMPORTED |
| src/dts.php | dts-v254-market-timezone-hard-stale-recovery-contract-20260912-r1 | IMPORTED |
| src/abc.php | abc-v542-market-timezone-bar-date-20260912-r1 | IMPORTED |
| src/das.php | das-v303-market-timezone-data-timestamp-20260912-r1 | IMPORTED |
| src/stc26.php | stc26-v301-daily-opportunity-shadow-20260910-r1 | IMPORTED |

The frozen source set needed for the ChatGPT → Codex handoff is now present in `src/`.

## Codex source rule

Use the exact files in `src/` as the starting baseline.

- Do not substitute an older Validation v1.2.6 file.
- Do not mix older Engine/Broker/Runner/Dashboard revisions into a change.
- Preserve PHP 7.4 compatibility.
- Keep `SINGLE_FILE_PAPER` and `REAL=false` as the frozen execution authority unless a separate migration is explicitly approved.
- Do not reintroduce SWING.

## Intentionally excluded

The following are not source-of-truth code and must not be copied from live NAS into this public repository:

- real runtime state,
- broker/order/position logs,
- credentials and local broker config,
- tokens and certificates,
- account-specific snapshots,
- live trade_single_compat runtime contents.

Use synthetic fixtures for tests.
