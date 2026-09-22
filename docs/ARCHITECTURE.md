# Architecture

## High-level topology

```text
Strategies
  DTS / ABC / DAS / STC26
        |
        v
Trade Engine
        |
        v
Broker intent / order pipeline
        |
        v
Trade Broker
        |
        v
SINGLE_FILE_PAPER runtime

Low-Load Runner
  - scheduling authority
  - broker event cadence
  - strategy cadence
  - stale strategy watchdog

Validation
  - independent statistical/result validation

Dashboard
  - observability
  - operator controls
  - warning state
  - no implicit execution authority

Preflight
  - deployment/contract gate
```

## Authority boundaries

### Runner

Runner owns scheduling. No second cron/scheduler may concurrently control the same execution path.

### Engine

Engine creates/updates trading decisions according to strategy and market evidence.

### Broker

Broker is responsible for order-state progression. Its execution truth must not be overwritten by stale canonical state.

### Dashboard

Dashboard is an observer/controller surface. A dashboard UI change must not silently alter trading semantics.

### Validation

Validation is logically separate from trading execution. Validation limitations should be visible but should not be converted into fabricated PASS results.

## Runtime principles

- One authoritative runtime tree.
- Legacy runtime copies may exist but must be ignored unless explicitly selected.
- Runtime state needed by open positions must be preserved.
- Transport failures fail closed.
- Normal market waiting is distinct from execution failure.
