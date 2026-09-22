# Test Protocol

## Minimum checks for every PHP change

1. PHP syntax lint on every modified PHP file.
2. Static contract/revision marker checks.
3. Regression checks for the affected component.
4. Preflight verification.
5. Review for PHP 7.4 compatibility.
6. Review for low-load behavior.
7. Verify no credentials/private runtime state were added.

## Execution-sensitive changes

For Engine/Broker/Runner/strategy changes, additionally verify:

- duplicate/terminal fill protection,
- lifecycle ordering,
- no position/runtime deletion,
- no concurrent scheduler path,
- bounded actionable retrigger,
- MARKET_WAIT semantics,
- stale strategy recovery,
- fail-closed transport behavior.

## Dashboard-only changes

Dashboard-only changes must demonstrate:

- no changes to Engine/Broker/Runner/strategy execution,
- no extra heavy market scans unless explicitly required,
- no misleading status semantics,
- warnings are removed only when the cause resolves,
- acknowledgement records operator state only.

## Validation language

Use the following evidence levels:

- STATIC PASS — source/lint/contracts only.
- LOCAL REGRESSION PASS — local executable regression passed.
- PREFLIGHT PASS — deployment contract gate passed in the target environment.
- LIVE VERIFIED — live runtime evidence observed after deployment.

Never collapse these into a single unsupported "production PASS".
