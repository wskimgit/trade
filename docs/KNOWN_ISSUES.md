# Known Issues / Historical Failure Modes

This file records failure patterns that should not be reintroduced.

## Active-runtime vs legacy-runtime confusion

A component may compute the correct active runtime path but accidentally read legacy files afterward. All reads/writes must use the selected authority consistently.

## Runner starvation

Future `next_due_at` or stale canonical state can starve Broker or strategy work. Execution truth and bounded watchdog logic are required.

## Strategy tick starvation

A strategy may appear installed/current while its tick is stale. Freshness must be measured from runtime evidence, not only source version.

## False preflight blockers

Exact formatting/version-string matching created false failures in the past. Prefer concrete capability checks when safe.

## MARKET_WAIT misclassification

A scheduled market wait with no actionable order is not a Broker failure.

## Cross-currency exposure ranking

Do not rank KRW/USD/JPY raw notionals against each other. Use normalized exposure percentages.

## Alert acknowledgement

Acknowledgement must mean "operator has seen this" only. It must not suppress monitoring, mutate execution state, or permanently hide a worsening condition.
