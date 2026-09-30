# Live NAS trade baseline — 2026-09-30

This is a read-only observation of the running Synology Web Station instance at
`https://k-bizpost.myds.me/trade_dashboard.php`,
`trade_runner_control.php`, `trade_runner_status.php`, `trade_runner_preflight.php`,
and `trade_pipeline_diag.php`.

The filesystem path `/volume1/web/*.php` is exposed at the domain root, not at
the URL prefix `/web/`. PHP source bodies are executed by Web Station and are
not downloadable from these pages. The values below are therefore runtime
identity and SHA-256 evidence, not a source checkout.

## Runtime identity

- PHP: 7.4.30
- Authority: `SINGLE_FILE_PAPER`
- Real orders: `false`
- Dashboard system-info identity: v3.4.14 /
  `trade-dashboard-v3414-terminal-diagnostics-20260923-r1`
- Engine: v4.5.1 /
  `trade-engine-v451-ack-evidence-resolution-parity-20260929-r1`
- Broker: v5.9.13
- Runner: v1.5.1 /
  `trade-low-load-runner-v151-canonical-ghost-guard-20260929-r1`
- Runner web control: v1.5.1 /
  `trade-low-load-runner-web-control-v151-canonical-ghost-guard-20260929-r1`
- Runner status: v1.5.1 /
  `trade-low-load-runner-status-v151-execution-truth-round-robin-20260929-r1`
- Runner preflight: heading v1.5.4, 71/71 PASS
- Pipeline diagnostics: v1.4.4 /
  `trade_pipeline_diag_v144`

## Exact files reported by Preflight

All entries below were PASS with matching expected/actual SHA-256:

| File | SHA-256 |
|---|---|
| `trade_engine.php` | `c2f29a6aa784b73f2fa4d36eef34b376180aa8c921480cc9a17595459fd46a6c` |
| `trade_broker.php` | `2f346e3c686dfc6cfe477912847a1c615f2a763abb3b1538c8b6c8b23ef91333` |
| `dts.php` | `fe226ac726485cec5df0fd324d6ae306e1e3fdfcb344ed604282079d0d574a99` |
| `abc.php` | `be44d87292a836c393b1c2b76b2c9a0381cca93996326220028c93bfa8d8ead5` |
| `das.php` | `7f314fdda15581516899223f5dbf1472aab6c08104ca9e8e0e2c5687fc066fda` |
| `stc26.php` | `270c3e0a02471b8279e177898c2c4f08329b69fec9428d107f8ea4221ea06414` |
| `trade_store_v103.php` | `ec3fc8168607683f022b8ffb40a5ecc8c66cd2d82b604a08e69a1cc006d729e6` |
| `trade_runner.php` | `1120d5027abc924ec224a2aca4455198c784b61953c9e77a889db830cb20632e` |
| `trade_runner_config.php` | `0f0f1ffa20dd4329085cfdc3b94b8975df695541eaeebef316e3fa7404fdaf5c` |
| `trade_runner_control.php` | `78d32200a6117c9dae34d0b719800cf5788c78a0dd9bc9ceafa17b5ad2314d28` |
| `trade_runner_status.php` | `21bb84f1f0a963ae2d9fe7e099914aba93b8051ba2fb716ed8bfc8528ff2864f` |
| `trade_validation.php` | `94fcbd660fcd722c9a28be82df821e07157b36b7af622af70a09cd1798f652d8` |
| `trade_validation_repair.php` | `194d9e526f36e941f51463d09b14d22fc66922df3958a06c0acb3acecfa044b4` |
| `trade_pipeline_diag.php` | `f84ffac3af19b1b6534d81466a60791ae55e7024fd194547dec183f690b350e4` |

## Observed integrity and drift

- Preflight reported 71/71 PASS, 0 hard failures, and 0 warnings.
- Runner status reported a fresh heartbeat, `RUNNING`, PAPER, REAL false,
  transport OK, validation OK, and no active/actionable/market-wait orders at
  observation time.
- Pipeline diagnostics reported one terminal-candidate drift: a stale DTS
  `BUY_PENDING` candidate whose ACK evidence was `EXPIRED`; no orphan
  `BUY_PENDING` rows.
- Dashboard visible header says `v3.4.11`, while its system-info/footer says
  v3.4.14 / `v3414`. This is a version-identity inconsistency to fix in the
  next source revision.
- Runner preflight heading says v1.5.4, while its displayed revision includes
  `v153-engine449`. This is another stale identity string, despite all checks
  passing.

## Deployment consequence

The current GitHub `main` source and the earlier Pull&VERIFY branch contain
older identities than this live NAS baseline (for example, GitHub main showed
Engine v4.4.6, Broker v5.9.10, Runner v1.4.6). The Pull&VERIFY package must not
be used to replace the isolated target with that older source until the live
NAS PHP files are mirrored into GitHub and the allowlist/markers are updated.
This file intentionally does not claim that source mirroring has occurred.
