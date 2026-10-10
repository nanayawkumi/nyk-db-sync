# nyk-db-sync

Bidirectional MySQL and PostgreSQL sync for Laravel 10.37–13. It translates tables onto the other engine, copies rows, keeps both sides updated while you cut over, and can switch the live connection from an admin page.

This is the package form of the dual-database work in nyk-business. The host app still owns `config/database.php`. The package does not replace that file.

## What it does

1. Reads the primary connection and creates missing tables on the secondary, mapping column types between MySQL and PostgreSQL.
2. Copies rows in foreign-key order with `upsert`, then repairs Postgres `id` sequences.
3. While dual-write is on, primary inserts, updates, deletes, and model/pivot changes are applied to the secondary. A secondary failure is logged and the primary write stands.
4. Cutover rewrites `DB_CONNECTION` and `DB_DUAL_WRITE` in `.env` after the secondary is reachable and row counts are close, then signals queue workers to restart.

Direction follows whichever connection is primary. `mysql` and `mariadb` pair with `pgsql`, and `pgsql` pairs with `mysql`, unless `DB_SECONDARY_CONNECTION` is set.

## Requirements

- PHP `^8.1`
- Laravel `^10.37`, `^11.0`, `^12.0`, or `^13.0`
- `pdo_mysql` and `pdo_pgsql` on the host that runs the sync

## Installation

```bash
composer require nanayawkumi/nyk-db-sync
```

The service provider is auto-discovered. Publish the config when you want to set table order, skip lists, or the admin gate:

```bash
php artisan vendor:publish --tag=nyk-db-sync-config
```

## Database connections

Keep MySQL and Postgres as separate connections so `DB_CONNECTION` can flip without losing either database. The names `mysql` and `pgsql` are the cutover targets.

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
# DB_SECONDARY_CONNECTION=pgsql

MYSQL_HOST=127.0.0.1
MYSQL_PORT=3306
MYSQL_DATABASE=laravel
MYSQL_USERNAME=root
MYSQL_PASSWORD=

PGSQL_HOST=127.0.0.1
PGSQL_PORT=5432
PGSQL_DATABASE=laravel
PGSQL_USERNAME=postgres
PGSQL_PASSWORD=
```

Create the empty Postgres database before the first sync. The package creates tables there. It also copies the `migrations` table so a later `php artisan migrate --database=pgsql` does not try to create those tables again.

## Sync

```bash
php artisan nyk-db-sync:sync
php artisan nyk-db-sync:sync --table=orders
php artisan nyk-db-sync:sync --schema-only
php artisan nyk-db-sync:sync --no-schema
php artisan nyk-db-sync:repair-sequences
php artisan nyk-db-sync:repair-sequences --connection=pgsql
```

Every primary table is discovered. `skip_tables` defaults to `migrations` (that table is still copied on its own so the migrator stays honest). `table_order` lists tables that should run as early as their foreign keys allow. Other tables follow dependency order, then name.

A sync sets each Postgres `id` sequence to `MAX(id)` on both connections. Inserting a copied id does not move a sequence by itself, so this is what stops the next insert from reusing one.

Tables that only exist on the secondary are left alone. Columns that already exist are not altered. New columns are added. Foreign keys are created with the table when the referenced table already exists.

## Dual-write

```dotenv
DB_DUAL_WRITE=true
```

Writes through the query builder and through Eloquent models (including pivot attach/detach and soft deletes) are repeated on the secondary. When that write includes an `id` and the secondary is Postgres, the sequence is advanced to at least that id. `DB::statement()` and `DB::unprepared()` are not replicated. Models outside `app/Models` go in `nyk-db-sync.models.extra`.

A downed secondary cannot fail the request. The failure is written to the log as `[nyk-db-sync]`.

## Cutover

From the admin page, or `DatabaseEngineSwitcher::switchTo('pgsql', keepDualWrite: true)`.

The switch is refused when the secondary is down, the engine is already active, or a table is missing or its row-count gap is larger than the alignment tolerance (`alignment.minimum` and `alignment.ratio`). A backup of `.env` is written beside it before the change. Queue workers are signaled to restart before and after the write. Switching to `pgsql` realigns `id` sequences to `MAX(id)` before new inserts run. The admin page can do the same with **Repair Postgres IDs**.

Run another sync, or leave dual-write on, until the counts sit inside that tolerance.

## Admin UI

Off by default.

```dotenv
NYK_DB_SYNC_UI=true
NYK_DB_SYNC_PATH=nyk-db-sync
```

Set `nyk-db-sync.ui.middleware` and `nyk-db-sync.ui.gate` to the host's admin check. The default middleware is `web` and `auth`. The page shows both connections, dual-write state, live counts, a sync form, and the engine switch.

## Sample implementation

This is an existing Laravel site whose live data is already in MySQL. Users, orders, and the admin area stay as they are. The package adds a Postgres database beside MySQL, then an admin page that can copy data and switch the live engine.

### 1. Install and publish

```bash
composer require nanayawkumi/nyk-db-sync
php artisan vendor:publish --tag=nyk-db-sync-config
```

### 2. Keep the current MySQL connection, and add Postgres

In `config/database.php`, point the existing `mysql` connection at `MYSQL_*` and fall back to the `DB_*` values the site already uses. Add a `pgsql` connection next to it. Do not delete the current `mysql` entry.

```php
'mysql' => [
    'driver' => 'mysql',
    'host' => env('MYSQL_HOST', env('DB_HOST', '127.0.0.1')),
    'port' => env('MYSQL_PORT', env('DB_PORT', '3306')),
    'database' => env('MYSQL_DATABASE', env('DB_DATABASE', 'laravel')),
    'username' => env('MYSQL_USERNAME', env('DB_USERNAME', 'root')),
    'password' => env('MYSQL_PASSWORD', env('DB_PASSWORD', '')),
    'unix_socket' => env('MYSQL_SOCKET', env('DB_SOCKET', '')),
    'charset' => env('MYSQL_CHARSET', env('DB_CHARSET', 'utf8mb4')),
    'collation' => env('MYSQL_COLLATION', env('DB_COLLATION', 'utf8mb4_unicode_ci')),
    'prefix' => '',
    'prefix_indexes' => true,
    'strict' => true,
    'engine' => null,
],

'pgsql' => [
    'driver' => 'pgsql',
    'host' => env('PGSQL_HOST', '127.0.0.1'),
    'port' => env('PGSQL_PORT', '5432'),
    'database' => env('PGSQL_DATABASE', 'laravel'),
    'username' => env('PGSQL_USERNAME', 'postgres'),
    'password' => env('PGSQL_PASSWORD', ''),
    'charset' => env('PGSQL_CHARSET', 'utf8'),
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => env('PGSQL_SEARCH_PATH', 'public'),
    'sslmode' => env('PGSQL_SSLMODE', 'prefer'),
],
```

Leave `DB_CONNECTION=mysql` so the site keeps serving MySQL until someone switches it.

Add the Postgres settings to `.env`. Create that database empty. Do not point `DB_DATABASE` at it.

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
PGSQL_SSLMODE=prefer

NYK_DB_SYNC_UI=true
NYK_DB_SYNC_PATH=admin/database
```

`NYK_DB_SYNC_PATH` is the URL of the admin page. With the value above it is `/admin/database`.

### 3. Tell the package which models sit outside `app/Models`

`config/nyk-db-sync.php` already discovers `App\Models`. Package models, such as a roles package, need to be listed or their pivot writes will not be observed:

```php
'models' => [
    'discover' => true,
    'namespace' => 'App\\Models',
    'path' => null,
    'extra' => [
        \Spatie\Permission\Models\Role::class,
        \Spatie\Permission\Models\Permission::class,
        \Laravel\Sanctum\PersonalAccessToken::class,
    ],
],

'table_order' => [
    'users',
    'roles',
    'permissions',
],
```

`table_order` only pulls those tables forward. Every other primary table is still copied, after the tables it depends on.

### 4. Limit the admin page to the people who already administer the site

The page uses the `web` middleware group, so the existing session and CSRF setup apply. Add a gate the site already understands. This example treats a `SuperAdmin` role as the database admin. Use whatever check the site already uses (`is_admin`, a policy, a permission).

`app/Providers/AppServiceProvider.php`:

```php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('manage-database', function ($user): bool {
        return $user->hasRole('SuperAdmin');
    });
}
```

`config/nyk-db-sync.php`:

```php
'ui' => [
    'enabled' => (bool) env('NYK_DB_SYNC_UI', false),
    'path' => env('NYK_DB_SYNC_PATH', 'admin/database'),
    'middleware' => ['web', 'auth'],
    'gate' => 'manage-database',
],
```

If the site already has an admin middleware, add it to that list (`['web', 'auth', 'admin']`). Guests and signed-in users who fail the gate get 403. The route names are `nyk-db-sync.index`, `nyk-db-sync.sync`, `nyk-db-sync.switch`, and `nyk-db-sync.repair`.

### 5. Link it from the existing admin navigation

```blade
@can('manage-database')
    <a href="{{ route('nyk-db-sync.index') }}">Database</a>
@endcan
```

Opening `/admin/database` shows the live engine, the secondary connection, whether dual-write is on, and the row counts. **Run sync** creates missing Postgres tables and copies rows. **Switch live database** writes `DB_CONNECTION` and `DB_DUAL_WRITE` in `.env`, and a switch to Postgres realigns `id` sequences. **Repair Postgres IDs** does that realignment without switching. The confirmation box on the switch form has to be checked. The switch stays disabled while Postgres cannot be reached, and the package refuses it while a table is missing or the counts are too far apart.

### 6. Cut over

```bash
php artisan nyk-db-sync:sync
```

Then set `DB_DUAL_WRITE=true` and reload config (`php artisan config:clear`) so new orders written to MySQL are also written to Postgres. Each of those copies advances the Postgres `id` sequence. When the admin page says the counts are close enough, switch the live engine to `pgsql` and leave dual-write on. That switch realigns sequences again. A copy of `.env` is saved beside it as `.env.bak.YYYYMMDDHHMMSS` before the write. Queue workers are signaled to restart.

To move back, open the same page and switch the engine to `mysql`.

## Schema limits

Translated: integers, booleans, decimals, strings, text, json/jsonb, uuid, dates, times, enums declared inline (`enum('a','b')`), and indexes.

Not translated: views, triggers, stored procedures, generated columns, Postgres arrays (stored as json), MySQL `SET` (stored as string), native Postgres enum labels, and `ON UPDATE CURRENT_TIMESTAMP`. Existing column types are not rewritten. `0000-00-00` datetime values become null on the way across. Postgres boolean columns receive real booleans.

Disabling Postgres foreign keys during the copy uses `session_replication_role`, which needs a role allowed to set it. If that statement is rejected, the copy still runs and foreign keys stay enforced.

## Package layout

- `src/Schema` reads the primary catalog and builds the secondary tables
- `src/Sync` copies rows and stores the last summary in cache and `storage/app/nyk-db-sync/last-sync.json`
- `src/DualWrite.php` plus the connection classes repeat live writes
- `src/Cutover` rewrites `.env`
- `src/Http` and `src/Views/index.blade.php` are the admin page

## Testing

```bash
composer test
```

Tests use two in-memory SQLite databases. MySQL and Postgres type mapping is covered without a database server.

## License

MIT. See [LICENSE](LICENSE).
