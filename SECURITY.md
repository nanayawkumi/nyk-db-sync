# Security Policy

## Supported versions

Only the latest minor release of the 1.x line receives security fixes. If you are on an older release, upgrade first and check whether the problem still exists.

| Version | Supported |
| ------- | --------- |
| Latest 1.x minor (currently 1.1) | Yes |
| Anything older | No |

## Reporting a vulnerability

Please do not open a public issue, pull request or discussion for a security problem.

Report it privately through GitHub: open the **Security** tab of [nanayawkumi/nyk-db-sync](https://github.com/nanayawkumi/nyk-db-sync/security) and choose **Report a vulnerability**.

Include what you can of the following:

- The version of the package, Laravel, PHP, and the database engines involved.
- What an attacker can do, and what access or role they need first.
- Steps or a minimal project that reproduces it.
- Any fix you have in mind.

You will get an acknowledgement and updates as the report is looked into. Once a fix is released, the advisory is published and you are credited, unless you prefer to stay anonymous.

## What counts

This package copies data between databases and can rewrite configuration, so these are in scope:

- The admin page and its routes: authorization bypass, CSRF, or reaching an action without the configured gate.
- Handling of database credentials: Postgres superuser credentials submitted to the setup form, generated passwords, and the `.env` file and its `.env.bak.*` backups.
- SQL built from table, column or user-supplied names (injection or unsafe identifier quoting).
- Files written under `storage/app/nyk-db-sync/` (decisions and analysis).
- Data loss or corruption on the secondary caused by crafted data on the primary.

Not in scope: problems that need an attacker who already has database superuser access or write access to your `.env`, and findings in your application's own gate or middleware.

## Hardening your install

- Set `nyk-db-sync.ui.gate` (`NYK_DB_SYNC_GATE`) to a Gate ability held only by administrators. Without it the admin page answers 403.
- Leave the admin page off (`NYK_DB_SYNC_UI=false`) in environments that don't need it.
- Treat `.env.bak.*` files as secrets: keep them out of version control and web roots, and delete the ones you no longer need. The package keeps the newest few (`dotenv_backups`).
- Give the sync a database login with only the rights it needs. Use the Postgres superuser only for the one-time database setup, and don't store its credentials.
