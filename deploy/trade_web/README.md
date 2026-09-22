# Web-only independent web/trade deployment

This directory contains a browser installer for a second, isolated Trade instance.

It stores the pinned source tree in the NAS folder entered in the web form. The default is:

~~~text
/volume1/web/trade
~~~

The target must be under the allowed parent, which defaults to /volume1/web. The existing /volume1/web application is not read, deleted, or modified.

## Required web setup

1. Put install_trade_web.php in a temporary, access-controlled Web Station folder. Do not place it inside the target folder before the first install.
2. Configure a secret outside the web root. The recommended environment variable is:

~~~text
TRADE_WEB_INSTALL_SECRET_FILE=/volume1/@appdata/trade-web/install.secret
~~~

The secret file must be readable by the PHP/Web Station user and must not be inside /volume1/web. An inline TRADE_WEB_INSTALL_SECRET is also accepted, but it must never be committed to GitHub.
3. Open install_trade_web.php in a browser. Enter the target NAS folder, GitHub ref, installation key, and type the target path again for confirmation.
4. Keep the temporary installer protected. Remove or move it after installation when possible.

The installer accepts POST only for installation, uses a session CSRF token, and rejects target paths outside the allowed parent. It resolves the GitHub ref to a commit SHA before downloading source.

## Upgrade behavior

A first install aborts when the selected target already contains files. An upgrade requires the explicit web checkbox and is allowed only for a target previously created by this installer. It replaces only the managed PHP/entry files and preserves runtime JSON, logs, locks, and scheduler state.

The initialized authority is SINGLE_FILE_PAPER; real_order_allowed is always false. The installer never starts the scheduler.

## Web verification

After installation, open the target folder through Web Station, for example:

~~~text
https://your-host.example/web/trade/
~~~

The index page redirects to trade_dashboard.php. Review the dashboard authority/mode indicators and use the web health/status routes provided by the installed application.

The provided .htaccess blocks directory listing, JSON/log/lock/runtime artifacts, and trade_runner_control.php for Apache. If Synology Nginx is used, add equivalent Web Station/reverse-proxy deny rules and protect any runner control route with authentication or an IP allowlist.

This package records the exact source commit in trade_install_state.json. It does not perform NAS live verification by itself. Codex has prepared the repository changes but has not logged into the NAS.
