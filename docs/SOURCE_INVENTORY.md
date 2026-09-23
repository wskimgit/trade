# Source Inventory

Current ChatGPT → Codex handoff inventory.

## Imported current sources

| File | Current identity | Status |
|---|---|---|
| src/trade_engine.php | v4.4.6 / trade-engine-v446-sell-exchange-provenance-20260923-r1 | IMPORTED |
| src/trade_broker.php | v5.9.10 / trade-broker-v5910-jpx-builtin-calendar-parity-20260923-r1 | IMPORTED |
| src/trade_runner.php | v1.4.6 / trade-low-load-runner-v146-approval-actionable-gate-20260923-r1 | IMPORTED |
| src/trade_runner_control.php | v1.4.6 / trade-low-load-runner-web-control-v146-approval-actionable-gate-20260923-r1 | IMPORTED |
| src/trade_runner_status.php | v1.4.6 / trade-low-load-runner-status-v146-execution-truth-20260923-r1 | IMPORTED |
| src/trade_runner_preflight.php | v1.4.6 / trade-low-load-runner-web-preflight-v146-status-execution-truth-20260923-r5 | IMPORTED |
| src/trade_dashboard.php | v3.4.10 / trade-dashboard-v3410-validation-single-surface-20260923-r1 | IMPORTED |
| src/trade_3plus1_preflight.php | v1.3.17 / trade-3plus1-preflight-v1317-dashboard-validation-single-surface-20260923-r1 | IMPORTED |
| src/trade_export.php | v1.0.1 / trade-export-v101-full-engine-config-mobile-safe-download-20260923-r1 | IMPORTED |
| src/trade_validation.php | v1.3.1 / trade-validation-v131-explicit-legacy-recovery-wal-20260921-r1 | IMPORTED |
| src/trade_list.php | v1.9.0 / trade_universe_contract_v2 | IMPORTED |
| src/dts.php | dts-v254-market-timezone-hard-stale-recovery-contract-20260912-r1 | IMPORTED |
| src/abc.php | abc-v542-market-timezone-bar-date-20260912-r1 | IMPORTED |
| src/das.php | das-v303-market-timezone-data-timestamp-20260912-r1 | IMPORTED |
| src/stc26.php | stc26-v301-daily-opportunity-shadow-20260910-r1 | IMPORTED |

## 2026-09-23 order-path recovery hotfix

The execution core was advanced from the 2026-09-22 frozen baseline only where live exported evidence showed a concrete defect:

- Runner: unapproved/approval-blocked PENDING orders remain ACTIONABLE even during a closed session.
- Broker: current operational `trade_list.php` is an exchange-map fallback even when `TRADE_LIST_FILE` points at an older list; numeric-string JP symbols such as `5803` are merged with key-preserving `array_replace()` rather than `array_merge()`.
- Broker: JPX built-in 2026/2027 calendar fallback matches Runner wake-hint closures; an optional local calendar can add holidays or explicitly reopen a date with `open_dates`.
- Engine: SELL intent context recovers a blank exchange from the already-open Broker-confirmed position before the intent is signed.
- Runner Control and Preflight were advanced coherently with those changes.

Regression: `tests/regression_order_path_recovery.php`.

## Codex source rule

Use the exact files in `src/` as the starting baseline. Preserve PHP 7.4 compatibility, `SINGLE_FILE_PAPER`, `REAL=false`, and SWING retirement unless a separately reviewed migration explicitly changes them.

## Intentionally excluded

Do not copy live runtime state, broker/order/position logs, credentials, local broker config, tokens, certificates, account-specific snapshots, or live `trade_single_compat` runtime contents into this public repository. Use synthetic fixtures for tests.

- Dashboard v3.4.10: validation summary is shown once in the operational overview; duplicated per-strategy validation rows were removed from the four strategy cards.

- Runner Status v1.4.6: displays the execution order gate (`BROKER_EXECUTION_PLUS_CANONICAL_FALLBACK`) as operational truth; a MARKET_WAIT-only order does not appear as BROKER FIRST.
