# AGENTS.md

These rules apply to Codex and any automated coding agent working in this repository.

## Project identity

This repository contains the development handoff for the 3+1 Trade system:

- CORE: DTS, ABC, DAS
- CHALLENGER: STC26
- SWING is retired and must not be reintroduced unless explicitly requested by the user.

## Frozen operating constraints

- Runtime authority: `SINGLE_FILE_PAPER`
- `REAL=false`
- Runner is the scheduling authority.
- Legacy scheduler files may exist for compatibility but must not run concurrently.
- Preserve open-position compatibility.
- Do not delete active strategy runtime state while positions exist.
- Keep PHP 7.4 compatibility.
- Optimize for low CPU/memory load on older Synology hardware.
- Fail closed on transport/execution ambiguity.
- Scheduled `MARKET_WAIT` with no actionable order is not an execution failure.

## Engineering rules

- Do not make patch-on-patch fixes when the defect is structural.
- Prefer redesign of the affected boundary when required.
- Never hide an operational warning merely to make the dashboard green.
- Duplicate warnings caused by one root cause should be merged.
- User acknowledgement of a warning must not mutate trading state.
- UI/observability changes must not silently alter Engine/Broker/Runner/strategy execution.
- Preserve explicit version/revision markers.
- Keep source-of-truth semantics documented.

## Required workflow

Before editing:

1. Read `CODEX_HANDOFF.md`.
2. Read `docs/FROZEN_BASELINE.md`.
3. Read `docs/ARCHITECTURE.md`.
4. Inspect the exact current source; do not infer unseen code.

After editing:

1. Run PHP lint on all modified PHP files.
2. Run available regression checks.
3. Run preflight/static contract checks.
4. Report exact files changed.
5. Report whether execution logic changed.
6. Report any compatibility risk.
7. Never claim production PASS without live runtime evidence.

## Branching

Treat `main` as the shared reference baseline.

For substantive work, prefer a branch named like:

`codex/<short-task-name>`

Then submit a PR to `main` with verification evidence.

## Public repository restrictions

Never commit:

- API keys
- access tokens
- broker credentials
- passwords
- private certificates/keys
- `.env`
- local broker config
- real runtime/order/position state
- user-identifying data
- private NAS credentials

Use synthetic fixtures under `tests/fixtures/` when runtime examples are required.
