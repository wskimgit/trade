# Codex Development Environment

## Target compatibility

Production target is Synology NAS with PHP 7.4-compatible source.

Codex may use a newer local PHP runtime for syntax/testing, but changes must remain PHP 7.4 compatible.

## Repository workflow

```text
main
  └─ codex/<task>
       ├─ inspect exact source
       ├─ edit
       ├─ lint
       ├─ regression/static checks
       └─ PR -> main
```

## First commands

```bash
php -v
for f in src/*.php; do php -l "$f" || exit 1; done
```

Do not execute strategy/Broker/Runner entrypoints against real accounts or copied live runtime state.

## Runtime simulation

Use only synthetic files under `tests/fixtures/`.

Environment variables and local configs used for tests must contain dummy values only.

## Source layout

`src/` mirrors operational filenames so code relationships remain easy to understand.

Runtime folders are intentionally absent. Tests should create temporary runtime trees.

## Evidence levels

- syntax/static check
- local regression
- preflight against synthetic/target tree
- live deployed verification

Keep these levels separate in every report.

## Missing source gate

Before any full-system release or Validation change, confirm `src/trade_validation.php` exists and matches the frozen baseline identity documented in `docs/SOURCE_INVENTORY.md`.
