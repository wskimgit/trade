# NAS Pull & VERIFY web control

Use the single PHP file as a mobile-friendly, authenticated web control. It does not require SSH or a user CLI command. The browser request performs the same GitHub Pull & VERIFY path and never downloads runtime data or credentials.

## One-time NAS setup

1. Save `deploy/nas/pull_verify_trade.php` on the NAS as `/volume1/web/pull_verify_trade.php`. Keep it outside the target folder `/volume1/web/trade`.
2. With DSM File Station, create `/volume1/.trade_pull_web_key` outside the web root. Put one random secret of at least 16 characters in the file. Do not put the key in GitHub or under `/volume1/web`.
3. Open the file over HTTPS, for example `https://k-bizpost.myds.me/pull_verify_trade.php`, and enter the web key.
4. Press **최초 PULL** for the first install. The target must be empty.
5. After reviewing the result, use **로컬 VERIFY** for a local hash/syntax check or check the confirmation box and press **UPGRADE** for a later GitHub upgrade.

The web control is deliberately HTTPS-only by default. It supports `TRADE_PULL_ALLOW_HTTP=1` only as an explicit administrator override; do not use that on a public NAS.

## What the web control verifies

The script resolves the selected GitHub ref to an exact commit SHA, pulls only the allowlisted source files through the GitHub Contents API, checks PHP headers and frozen revision markers, verifies SHA-256 hashes, runs PHP syntax checks, and activates the result atomically only after all checks pass.

The default target is `/volume1/web/trade`. The script refuses to touch a non-empty unmanaged target and refuses paths outside `/volume1/web`. Existing runtime directories, open positions, and runtime JSON state are preserved during **UPGRADE**.

The target receives the frozen 3+1 source set plus `index.php` and `.htaccess`. It initializes:

- `SINGLE_FILE_PAPER` authority
- `real_order_allowed=false`
- independent runtime directories
- `trade_pull_verify_state.json` with the resolved commit and per-file SHA-256 values

The pull verifier does not start the Runner. Start/stop remains an application/web control concern after the target has been reviewed and protected in Web Station.

## Optional settings

For a private fork or pinned deployment, configure these outside the web root:

~~~text
TRADE_PULL_TOKEN_FILE=/volume1/@appdata/trade-pull/github-token
TRADE_PULL_REPOSITORY=owner/private-trade
TRADE_PULL_REF=main
TRADE_PULL_EXPECTED_SHA=40-character-commit-sha
TRADE_PULL_TARGET=/volume1/web/trade
TRADE_PULL_ALLOWED_PARENT=/volume1/web
TRADE_PULL_WEB_KEY_FILE=/volume1/.trade_pull_web_key
TRADE_PULL_LOCK_FILE=/volume1/.trade_pull_verify_web.lock
~~~

The public repository needs no GitHub token. Never put a token, web key, broker credential, certificate, or runtime state in this repository or under `/volume1/web`.

Codex has prepared this Pull & VERIFY source package. It has not executed it on the NAS.
