# nyk-db-sync

Copy a live Laravel database from MySQL to PostgreSQL, or the other way around, then switch the app when the row counts match. The package reads the tables that are already in the database. Projects without migration files work the same way.

The host app keeps `config/database.php`.

## Requirements

- PHP `^8.1`
- Laravel `^10.37`, `^11.0`, `^12.0`, or `^13.0`
- `pdo_mysql` and `pdo_pgsql` on the machine that runs the sync

## Install

```bash
composer require nanayawkumi/nyk-db-sync
php artisan vendor:publish --tag=nyk-db-sync-config
```

The service provider is discovered automatically. Publish the config when you want a table order, a skip list, or an admin gate.

## Connections

Keep `mysql` and `pgsql` as two connections. `DB_CONNECTION` is the live one. MySQL and MariaDB pair with Postgres, and Postgres pairs with MySQL, unless `DB_SECONDARY_CONNECTION` is set.

Point `mysql` at `MYSQL_*`, falling back to the `DB_*` values the site already uses, and add `pgsql` beside it.

```php
'mysql' => [
    'driver' => 'mysql',
    'host' => env('MYSQL_HOST', env('DB_HOST', '127.0.0.1')),
    'port' => env('MYSQL_PORT', env('DB_PORT', '3306')),
    'database' => env('MYSQL_DATABASE', env('DB_DATABASE', 'laravel')),
    'username' => env('MYSQL_USERNAME', env('DB_USERNAME', 'root')),
    'password' => env('MYSQL_PASSWORD', env('DB_PASSWORD', '')),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
],

'pgsql' => [
    'driver' => 'pgsql',
    'host' => env('PGSQL_HOST', '127.0.0.1'),
    'port' => env('PGSQL_PORT', '5432'),
    'database' => env('PGSQL_DATABASE', 'laravel'),
    'username' => env('PGSQL_USERNAME', 'postgres'),
    'password' => env('PGSQL_PASSWORD', ''),
    'charset' => 'utf8',
    'prefix' => '',
    'search_path' => 'public',
    'sslmode' => env('PGSQL_SSLMODE', 'prefer'),
],
```

```dotenv
DB_CONNECTION=mysql
DB_DUAL_WRITE=false

MYSQL_HOST="${DB_HOST}"
MYSQL_PORT="${DB_PORT}"
MYSQL_DATABASE="${DB_DATABASE}"
MYSQL_USERNAME="${DB_USERNAME}"
MYSQL_PASSWORD="${DB_PASSWORD}"

PGSQL_HOST=127.0.0.1
PGSQL_PORT=5432
PGSQL_DATABASE=my_app
PGSQL_USERNAME=postgres
PGSQL_PASSWORD=
```

`PGSQL_USERNAME` is the variable name. Leave `DB_CONNECTION=mysql` so the site keeps serving MySQL until you switch it.

Create the empty Postgres database and its login with:

```bash
php artisan nyk-db-sync:create-database
```

The command asks for the database name, the app login, and that login's password. It connects as the superuser on `127.0.0.1` port `5432`, creates the database and the login, and grants `CONNECT` plus `USAGE` and `CREATE` on schema `public`. It overwrites `PGSQL_HOST`, `PGSQL_PORT`, `PGSQL_DATABASE`, `PGSQL_USERNAME`, and `PGSQL_PASSWORD`. The admin page shows that form only when the Postgres database does not exist. The grant form stays on the page either way.

`--host`, `--port`, `--database`, `--username`, `--password`, `--admin-username`, and `--admin-password` skip the questions. `--generate-password` creates a random login password and prints it. `--admin-username` is the superuser login and defaults to `postgres`. `--admin-password` is that login's password. Leave it empty when local trust or peer auth is enough. An existing database is left in place. An existing login keeps its name and gets the password you typed, or the generated one. The admin form has the same generate option and a superuser login field.

On Postgres 15 and newer, the app role needs `GRANT USAGE, CREATE ON SCHEMA public`. If creating a table fails with `permission denied for schema public`, grant that on the existing database:

```bash
php artisan nyk-db-sync:grant
```

It uses the host, port, database, and username from the `pgsql` connection unless you pass `--host`, `--port`, `--database`, or `--username`. It connects as the superuser and grants `CONNECT` plus `USAGE` and `CREATE` on schema `public`. `--admin-username` defaults to `postgres`. `--admin-password` is that login's password. The admin page has the same form, filled from the `pgsql` connection, with a superuser login field.

## Sync

```bash
php artisan nyk-db-sync:sync
php artisan nyk-db-sync:sync --table=orders
php artisan nyk-db-sync:sync --schema-only
php artisan nyk-db-sync:sync --no-schema
php artisan nyk-db-sync:repair-sequences
```

A sync analyzes first and stops on a blocking issue. See [Analyze](#analyze).

The command and the admin page count the primary rows and show an estimated time before the copy starts. The first estimate is rough. Once rows are copying, the estimate uses that speed, including when a timeout or crash stops the run before it finishes.

A sync creates missing tables and columns, then upserts every row. Existing columns are left as they are. Running it again updates the same primary keys. On Postgres, foreign keys are removed for the copy and put back when it finishes. A constraint that existing rows still violate is added or restored as `NOT VALID` and written to the log. Each Postgres `id` sequence is set to `MAX(id)` so the next insert does not reuse a copied id.

`skip_tables` defaults to `migrations`. That table is still copied on its own, so a later `php artisan migrate` does not try to create the translated tables again. `table_order` runs named tables as early as their foreign keys allow.

Models outside `app/Models` go in `models.extra`. `table_order` only changes order. Every other primary table is still copied.

```php
'models' => [
    'extra' => [
        \Spatie\Permission\Models\Role::class,
        \Spatie\Permission\Models\Permission::class,
    ],
],
```

If the process is killed, rows already committed stay on the secondary and foreign keys removed for that copy may still be off. Run the same command again, without `--no-schema`, and it continues.

## Analyze

Run this before the first sync, or whenever the data or schema has changed.

```bash
php artisan nyk-db-sync:analyze
php artisan nyk-db-sync:analyze --table=orders
php artisan nyk-db-sync:analyze --sample=10000
php artisan nyk-db-sync:analyze --fix=auto
php artisan nyk-db-sync:analyze --report
```

The analysis simulates the conversion and writes nothing. It maps every column the way the sync would, then scans the source data with one aggregate query per table, so rows are not pulled into PHP. Each table comes back `ready`, `ready with notes`, or `blocked`.

It looks for:

- a string longer than the destination column, which includes `SET` and unrecognized types stored as `string(255)`
- integers outside the destination range, such as `UNSIGNED` columns on Postgres
- zero dates (`0000-00-00`) going into a `NOT NULL` column
- NUL bytes in text copied to Postgres
- invalid JSON in a column that becomes `json`
- enum values outside the declared list
- `tinyint(1)` values other than 0 and 1 that would be flattened to a boolean
- a table with no primary or unique index to upsert on. Postgres and SQLite refuse the copy when the table has rows. MySQL accepts it but a second sync adds the rows again.
- rows that point at a missing parent (the foreign key is added as `NOT VALID`, so this is a warning)
- Postgres values that differ only by case under a unique index, when the destination is MySQL
- a table or column name longer than the destination allows
- an existing destination column whose type no longer matches the source

`--sample=N` scans only the first N rows of each table. Without it every row is scanned, which reads each table once.

For each blocking issue you choose one of three answers.

| Answer | Effect |
| --- | --- |
| Let the package fix it | The fix is saved and applied by the next sync. It only changes the destination: a wider column type, `NULL` allowed, or a value converted while copying. The source is never written to. |
| I'll fix it myself | The table stays blocked until a new analysis comes back clean. |
| Accept the risk | The table is unblocked and the issue stays listed as accepted. If a later analysis finds something different (for example more bad rows), the table is blocked again and you are asked again. |

Run interactively, the command asks about each blocker. `--fix=auto|manual|skip` answers every open blocker without asking (`auto` leaves the ones that have no automatic fix). `--report` prints JSON and asks nothing. The exit code is non-zero while any table is blocked, so it can gate a deploy.

An automatic fix is built into the table when the sync creates it or adds the column. On a table that already exists on Postgres, the sync alters the column (`ALTER COLUMN ... TYPE` or `DROP NOT NULL`). Existing columns on MySQL and SQLite are not altered. The issue then names the change to make by hand.

Decisions are stored in `storage/app/nyk-db-sync/decisions.json` and the last analysis in `analysis.json`. A decision is dropped once the issue it answers no longer appears.

### Sync gate

`nyk-db-sync:sync` and the admin page check every table they are about to copy. A table with no analysis, an analysis older than `analysis.max_age_minutes`, or an analysis made before its schema changed is analyzed first. The sync stops, before anything is written, if any table still has an unresolved blocking issue. `--table=orders` checks only `orders`, and `--schema-only` stops only for schema problems, such as a name that is too long. A schema-only run does not scan the data, and what it finds is not stored.

```bash
php artisan nyk-db-sync:sync --skip-analysis
```

```dotenv
NYK_DB_SYNC_ANALYSIS_REQUIRED=false
```

The first bypasses the check for one run. The second turns the gate off. The analysis scans the data as it is when it runs, so rows added afterward are not checked until the next analysis.

## Optimize

Run this after the copy, or on its own. It does not run as part of `nyk-db-sync:sync`.

```bash
php artisan nyk-db-sync:optimize
php artisan nyk-db-sync:optimize --apply
php artisan nyk-db-sync:optimize --partition=orders:created_at:month
```

The command reads PHP under `app`, `routes`, and `database`. It looks for `where`, `orderBy`, `groupBy`, `latest`, and `oldest` on a model, and for `table.column` references. `--path` scans other directories.

It recommends a Postgres index when a queried column exists and the MySQL origin does not already index it. An index Postgres already has is left out. The first run only prints the plan. `--apply` creates those indexes on Postgres with `CREATE INDEX CONCURRENTLY`. MySQL is not changed.

The origin is the MySQL connection: the live database while `DB_CONNECTION=mysql`, or the MySQL secondary after you have switched to Postgres.

`--partition` is `table:column:day`, `table:column:month`, or `table:column:year`. You name the table, the column, and the interval. The command prints that plan. It does not partition the table, because turning an existing table into a partitioned table rewrites it.

## Dual-write

```dotenv
DB_DUAL_WRITE=true
```

Primary inserts, updates, deletes, and pivot changes are repeated on the secondary. A secondary failure is logged as `[nyk-db-sync]` and the primary write stands. `DB::statement()` and `DB::unprepared()` are not repeated. An insert that includes an `id` advances the Postgres sequence.

## Switch

Use the admin page, or `DatabaseEngineSwitcher::switchTo('pgsql', keepDualWrite: true)`.

The switch is refused when the secondary is down, that engine is already live, or a table is missing or its row count differs at all. Raise `alignment.minimum` and `alignment.ratio` only if the primary takes writes during the switch. The sync copies and updates rows but never deletes, so a row deleted on the source stays on the secondary and blocks the switch until you remove it. A backup of `.env` is written beside it (owner-only, newest `dotenv_backups` kept), then `DB_CONNECTION` and `DB_DUAL_WRITE` are updated and queue workers are signaled to restart. Switching to Postgres realigns `id` sequences. **Repair Postgres IDs** does that without switching.

## Admin page

Off until you turn it on. The **Analyze** panel runs the same analysis as the command: it lists each table as ready or blocked, and each issue has a menu to let the package fix it, fix it yourself, or accept the risk. **Run sync** stays disabled while a stored analysis has a blocked table, and the server refuses the sync either way.

The default URL is `/nyk-db-sync`, with the `web` and `auth` middleware.

```dotenv
NYK_DB_SYNC_UI=true
NYK_DB_SYNC_PATH=admin/database
NYK_DB_SYNC_LAYOUT=layouts.app
NYK_DB_SYNC_SECTION=content
```

`layout` renders the sync UI in that view's `@yield`. `section` is the yield name, usually `content`, so an existing sidebar stays. For a component shell such as `<x-app-layout>`, set `NYK_DB_SYNC_COMPONENT=app-layout` instead. Leave both empty for a standalone page.

You can also drop the same block into a page you already have:

```blade
<x-nyk-db-sync />
```

The page can run syncs, rewrite `.env` and take database superuser credentials, so it answers 403 until `ui.gate` names a Gate ability (`NYK_DB_SYNC_GATE`). If your middleware already limits the page to admins, set `NYK_DB_SYNC_ALLOW_UNGATED=true` to skip the gate. Row counts on the page are cached for `ui.counts_cache_seconds`.

Limit it with a gate the site already uses:

```php
Gate::define('manage-database', fn ($user): bool => $user->hasRole('SuperAdmin'));
```

```php
'ui' => [
    'middleware' => ['web', 'auth'],
    'gate' => 'manage-database',
    'layout' => env('NYK_DB_SYNC_LAYOUT', 'layouts.app'),
    'section' => 'content',
],
```

```blade
@can('manage-database')
    <a href="{{ route('nyk-db-sync.index') }}">Database</a>
@endcan
```

The page shows whether each database accepted a connection, how many rows are already on the secondary, how many are left, that share as a percentage, the estimated time, the form that creates the Postgres database, and the switch form. The switch stays disabled while the secondary is down. Confirming the switch is required.

## Schema limits

Translated: integers, booleans, decimals, strings, text, json, uuid, dates, times, inline enums, and indexes.

Full-text indexes become the engine's own full-text index (a GIN index on Postgres). Spatial indexes are skipped. A plain index over a `TEXT` or `BLOB` column is skipped on Postgres and logged, because MySQL indexes those by prefix and a Postgres btree over the whole value fails on large rows. MySQL `ON UPDATE CURRENT_TIMESTAMP` is not carried over, so `updated_at` only changes when the application sets it. Binary columns reach Postgres `bytea` byte for byte. A source column that is missing on the secondary is not copied, and the sync summary lists it under `dropped_columns`. The package replaces the `mysql`, `pgsql`, `sqlite` and `mariadb` connection resolvers, so another package that does the same will conflict.

Left unchanged: views, triggers, stored procedures, generated columns, native Postgres enums, MySQL `SET`, and existing column types. A `0000-00-00` value or column default becomes null, and that column is created nullable. Postgres arrays are stored as json.

The database user has to own the tables so foreign keys can be removed and restored during the copy.

## Tests

```bash
composer test
```

## Security

To report a vulnerability, see [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
