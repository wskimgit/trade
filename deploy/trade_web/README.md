# NAS Pull & VERIFY deployment

Use the single PHP file in this directory from the NAS itself. Do not use SSH, a web installer, or an inbound web endpoint for deployment.

## Procedure

1. Save pull_verify_trade.php on the NAS outside /volume1/web.
2. Execute it with the NAS PHP 7.4 CLI or DSM Task Scheduler.
3. The default target is /volume1/web/trade. Set TRADE_PULL_TARGET if another child folder under /volume1/web is required.
4. The script resolves the GitHub ref to a commit SHA, downloads only the allowlisted source files through the GitHub Contents API, verifies PHP headers, frozen revision markers, SHA-256 hashes, PHP 7.4 syntax, and the SINGLE_FILE_PAPER / REAL=false contract.
5. Only after every check passes, it atomically activates the files. Existing runtime directories and runtime state are preserved during upgrades.

The script does not read, delete, or modify /volume1/web outside the selected target folder. It never copies runtime state, order data, broker credentials, tokens, or certificates from GitHub.

## Commands

First install into an empty target:

~~~sh
php /volume1/@appdata/trade-pull/pull_verify_trade.php pull
~~~

Verify the locally installed files later:

~~~sh
php /volume1/@appdata/trade-pull/pull_verify_trade.php verify
~~~

Upgrade the managed target explicitly:

~~~sh
php /volume1/@appdata/trade-pull/pull_verify_trade.php upgrade
~~~

The command is shown only as the local NAS execution example; no SSH connection is used. A DSM Task Scheduler job can execute the same local PHP file.

## Optional settings

The public repository needs no token. For a private fork, use an environment variable outside the web root:

~~~text
TRADE_PULL_TOKEN_FILE=/volume1/@appdata/trade-pull/github-token
TRADE_PULL_REPOSITORY=owner/private-trade
TRADE_PULL_REF=main
TRADE_PULL_EXPECTED_SHA=40-character-commit-sha
TRADE_PULL_TARGET=/volume1/web/trade
TRADE_PULL_ALLOWED_PARENT=/volume1/web
~~~

Never put a token in this repository or under /volume1/web.

## Result

The target receives the frozen 3+1 source set plus index.php and .htaccess. It initializes:

- SINGLE_FILE_PAPER authority
- real_order_allowed=false
- independent runtime directories
- an auditable trade_pull_verify_state.json containing the resolved commit and per-file SHA-256 values

The pull script does not start the Runner. Start/stop remains an application/web control concern after the target has been reviewed and protected in Web Station. The generated .htaccess blocks runtime artifacts and the runner control endpoint by default.

Codex has prepared this Pull & VERIFY source package. It has not executed it on the NAS.
