# Independent web/trade deployment

This directory contains the NAS installer for a second, isolated Trade instance.

It installs the pinned source tree into:

~~~text
/volume1/web/trade
~~~

The existing /volume1/web application is not read, deleted, or modified. The installer is CLI-only and is intended for Synology PHP 7.4 or newer.

## Safety contract

- The installer resolves the requested GitHub ref to a commit SHA before downloading any source.
- The first install aborts when /volume1/web/trade already contains files.
- Re-installation requires TRADE_ALLOW_REINSTALL=1 and is allowed only for a target previously created by this installer.
- Only the frozen source allowlist is copied. Runtime JSON, logs, locks, and scheduler state stay in the separate trade directory.
- The initialized authority is SINGLE_FILE_PAPER; real_order_allowed is always false.
- No GitHub token is embedded in the repository. Public wskimgit/trade needs no token. If a private fork is used, keep TRADE_GITHUB_TOKEN_FILE outside the web root.

The installer does not start the scheduler. This prevents a source copy from unexpectedly starting background work.

## NAS usage

Copy install_trade_web.php to a non-public NAS directory and run:

~~~sh
php /volume1/@tmp/install_trade_web.php --selftest
php /volume1/@tmp/install_trade_web.php install
~~~

Optional environment variables:

~~~sh
export TRADE_GITHUB_REF=main
export TRADE_WEB_ROOT=/volume1/web/trade
# Only for a private fork:
# export TRADE_GITHUB_REPOSITORY=owner/private-trade
# export TRADE_GITHUB_TOKEN_FILE=/volume1/@appdata/trade/github-token
~~~

For a later source upgrade:

~~~sh
export TRADE_ALLOW_REINSTALL=1
php /volume1/@tmp/install_trade_web.php install
~~~

The upgrade replaces only the managed PHP/entry files and preserves runtime directories. It refuses to proceed if the target has been changed to a non-paper authority.

## Verification

Run on the NAS after installation:

~~~sh
cd /volume1/web/trade
php -l trade_engine.php
php trade_runner.php health
php trade_3plus1_preflight.php
~~~

A successful deployment is only a static/local verification. It is not live verification and it does not authorize real orders.

To start the low-load runner, use a Synology Task Scheduler/CLI job reviewed by the operator:

~~~sh
php /volume1/web/trade/trade_runner.php daemon
~~~

Do not expose trade_runner_control.php directly to the public internet. The included .htaccess denies it by default. If web control is later required, put it behind DSM/Web Station authentication or an IP allowlist first.

## Web Station note

Apache honors the included .htaccess defense-in-depth rules. Synology Nginx configurations may ignore .htaccess; in that case add equivalent deny rules for JSON/log/lock/runtime artifacts and protect the runner control endpoint in Web Station or the reverse proxy.

This package records the exact source commit in trade_install_state.json. Codex has prepared the repository changes, but it has not logged into the NAS or performed the installation.
