# NAS Pull & VERIFY web control

The package now has two browser-only helpers:

- `deploy/nas/mirror_live_trade_web.php` reads an explicit allowlist of current NAS `trade*.php` files and writes them to the non-main GitHub branch `nas/live-trade-current`.
- `deploy/nas/pull_verify_trade.php` installs or upgrades the isolated `/volume1/web/trade` target after source verification.

Neither helper requires SSH or a user CLI command. The mirror helper never changes the live application or runtime state.

## First: mirror the live NAS source

The running NAS is newer than the GitHub `main` source currently in this repository. Do this before using Pull & VERIFY:

1. Save `deploy/nas/mirror_live_trade_web.php` as `/volume1/web/mirror_live_trade_web.php`.
2. With DSM File Station, create `/volume1/.trade_pull_web_key` outside the web root. Put one random secret of at least 16 characters in the file.
3. Confirm the existing GitHub token used by the NAS publisher is available at `/volume1/sis_private/github_token.txt`, or configure one of the documented token environment variables outside the web root.
4. Open the saved PHP file over HTTPS. Because `/volume1/web` is the Web Station document root, the usual URL is `https://k-bizpost.myds.me/mirror_live_trade_web.php`, not `/web/mirror_live_trade_web.php`.
5. Log in with the web key, review the allowlist and hashes, then press **최신 소스 미러링**. This writes only under `nas/live-trade-source/` on the separate GitHub branch `nas/live-trade-current`; it does not touch `main`.

After the mirror completes, Codex must fetch and review that branch, update the Pull & VERIFY allowlist and frozen markers, and run CI. Do not run **최초 PULL** against the current `main` until that source-alignment update is complete, because it is older than the live NAS.

## Pull & VERIFY browser setup

After source alignment is complete:

1. Save `deploy/nas/pull_verify_trade.php` as `/volume1/web/pull_verify_trade.php`, outside the target folder `/volume1/web/trade`.
2. Open `https://k-bizpost.myds.me/pull_verify_trade.php`.
3. Log in with the same web key.
4. Press **최초 PULL** for the first install. The target must be empty.
5. Use **로컬 VERIFY** for a local hash/syntax check, or check the confirmation box and press **UPGRADE** for a later upgrade.

The pull control is HTTPS-only by default. It uses POST, CSRF protection, a web key outside the web root, a concurrency lock, exact GitHub commit resolution, an allowlist, SHA-256 verification, PHP syntax checks, and atomic activation. It preserves runtime state during upgrades and refuses non-empty unmanaged targets.

The default target is `/volume1/web/trade`. It initializes:

- `SINGLE_FILE_PAPER` authority
- `real_order_allowed=false`
- independent runtime directories
- `trade_pull_verify_state.json` with the resolved commit and per-file SHA-256 values

The pull verifier does not start the Runner.

## Token settings

For a private fork or explicit pin, configure these outside the web root:

~~~text
TRADE_MIRROR_TOKEN_FILE=/volume1/sis_private/github_token.txt
TRADE_MIRROR_REPOSITORY=wskimgit/trade
TRADE_MIRROR_BRANCH=nas/live-trade-current
TRADE_MIRROR_BASE_REF=main
TRADE_PULL_TOKEN_FILE=/volume1/sis_private/github-token
TRADE_PULL_REPOSITORY=owner/private-trade
TRADE_PULL_REF=main
TRADE_PULL_EXPECTED_SHA=40-character-commit-sha
TRADE_PULL_TARGET=/volume1/web/trade
TRADE_PULL_ALLOWED_PARENT=/volume1/web
TRADE_PULL_WEB_KEY_FILE=/volume1/.trade_pull_web_key
TRADE_PULL_LOCK_FILE=/volume1/.trade_pull_verify_web.lock
~~~

Never put a token, web key, broker credential, certificate, or runtime state in this repository or under `/volume1/web`.

Codex has not executed the deployment on the NAS.
