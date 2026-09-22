# trade

Public handoff repository for the **3+1 automated trading system** development environment shared between ChatGPT and Codex.

## Purpose

This repository is the development handoff surface, not a live trading runtime.

- Core strategies: DTS / ABC / DAS
- Challenger: STC26
- Runtime authority: SINGLE_FILE_PAPER
- REAL trading: false in the frozen baseline
- Scheduler authority: Low-Load Runner
- SWING: retired
- Target runtime: Synology NAS / PHP 7.4 compatible
- Design principle: low-load, fail-closed, regression-verified

## Start here

1. Read `AGENTS.md`.
2. Read `CODEX_HANDOFF.md`.
3. Read `docs/FROZEN_BASELINE.md`.
4. Read `docs/ARCHITECTURE.md`.
5. Follow `docs/TEST_PROTOCOL.md` before proposing changes.

## Public-repository rule

Do not commit credentials, tokens, broker keys, passwords, private runtime state, real order/position logs, host secrets, or local configuration files. See `docs/SECURITY_PUBLIC_REPO.md`.
