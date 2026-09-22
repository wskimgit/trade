# Public Repository Security

This repository is public.

## Never commit

- broker API keys
- access/refresh tokens
- passwords
- private certificates or private keys
- live account identifiers
- private NAS credentials
- `.env`
- `broker_config.local.php`
- real runtime order/position JSON
- real engine/broker logs
- cookies/session files
- user-identifying data

## Allowed test data

Only synthetic or redacted fixtures should be stored under `tests/fixtures/`.

## Before adding a runtime snapshot

Remove:

- symbols/quantities if they reveal private live holdings,
- account identifiers,
- URLs containing tokens,
- host secrets,
- authentication headers,
- filesystem secrets.

## Secret response

If a secret is ever committed, deleting the file is not sufficient. Rotate/revoke the secret and remove it from Git history if needed.
