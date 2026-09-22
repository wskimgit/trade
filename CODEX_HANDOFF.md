# ChatGPT → Codex Handoff

## Goal

Continue Trade development in Codex without losing the architecture, operating contracts, and regression lessons established in ChatGPT.

This document is intentionally concise. Detailed current rules live under `docs/`.

## Current system shape

The frozen 3+1 execution baseline is:

- DTS — core
- ABC — core
- DAS — core
- STC26 — challenger / PAPER ONLY role in current baseline
- SWING — retired

Primary components:

- trade_engine.php
- trade_broker.php
- trade_runner.php
- trade_runner_control.php
- trade_dashboard.php
- trade_validation.php
- trade_3plus1_preflight.php
- trade_list.php
- dts.php
- abc.php
- das.php
- stc26.php

## Important historical lessons

1. Runtime authority must be explicit. Reading legacy runtime paths after computing the active runtime caused false/incorrect state.
2. Runner execution truth must take precedence over stale canonical scheduling state.
3. Future `next_due_at` must not indefinitely starve actionable Broker work.
4. Strategy freshness watchdogs are required so a strategy tick cannot be starved forever.
5. Preflight should gate concrete capabilities, not fragile exact-string formatting.
6. MARKET_WAIT is a normal scheduled state when there is no actionable order.
7. Dashboard warnings must reflect causes, not presentation artifacts.
8. Cross-market symbol concentration must be ranked by comparable percentage exposure, not raw currency amounts.
9. Warning acknowledgement is operator state only; it must never change trading state.

## Current handoff state

The repository is initially documentation-first. The exact source set should be imported only after a public-repository security scrub.

When source files are added, Codex must compare their embedded version/revision markers against `docs/FROZEN_BASELINE.md` before changing them.

## Working model

- `main`: shared frozen/reference baseline
- `codex/*`: Codex development branches
- PR: required for reviewable integration

## Definition of done for a code change

A change is not complete until:

- modified PHP files lint successfully,
- relevant regression checks pass,
- preflight/static contracts pass,
- no new secret/private runtime data is committed,
- execution-semantic changes are explicitly identified,
- the change can be rolled back from Git history.
