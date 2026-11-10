<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dual-write
    |--------------------------------------------------------------------------
    |
    | When enabled, writes on the primary connection are also applied to the
    | secondary. A failing secondary write is logged and does not roll back
    | the primary write.
    |
    */

    'enabled' => (bool) env('DB_DUAL_WRITE', false),

    /*
    |--------------------------------------------------------------------------
    | Secondary connection
    |--------------------------------------------------------------------------
    |
    | Leave empty to infer the pair: mysql and mariadb use pgsql, pgsql uses
    | mysql, and sqlite uses sqlite_secondary.
    |
    */

    'secondary' => env('DB_SECONDARY_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Dotenv file rewritten by cutover
    |--------------------------------------------------------------------------
    */

    'dotenv_path' => env('DB_DOTENV_PATH'),

    // Each rewrite leaves a .env.bak.<time> copy with every secret in it. This many are kept.
    'dotenv_backups' => 5,

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    |
    | Every primary table is discovered. Names in skip_tables are left out of
    | the data copy. table_order names are copied as early as foreign keys
    | allow; remaining tables follow in dependency order, then by name.
    |
    | The migrations table is skipped here and copied separately so the
    | secondary migrator treats translated tables as already migrated.
    |
    */

    'skip_tables' => [
        'migrations',
    ],

    'table_order' => [
        // 'users',
        // 'orders',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cutover alignment
    |--------------------------------------------------------------------------
    |
    | A table blocks the engine switch when its row-count gap is larger than
    | the greatest of "minimum" and source * ratio. The default is no gap at
    | all: switching with missing rows loses data. Raise these only if the
    | primary takes writes during the switch.
    |
    | The sync copies and updates rows but never deletes. A row deleted on the
    | source stays on the secondary and shows up here as a gap.
    |
    */

    'alignment' => [
        'minimum' => 0,
        'ratio' => 0.0,
    ],

    'engines' => ['mysql', 'pgsql'],

    /*
    |--------------------------------------------------------------------------
    | Schema translation
    |--------------------------------------------------------------------------
    */

    'schema' => [
        'create_missing' => true,
        'add_missing_columns' => true,
        'copy_migration_repository' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Analysis
    |--------------------------------------------------------------------------
    |
    | nyk-db-sync:analyze simulates the conversion and reports what would fail
    | or lose data. When "required" is on, a sync analyzes any table that has no
    | fresh analysis and refuses to run while a table has an unresolved blocking
    | issue. "sample" limits the data scan to the first N rows of each table
    | (0 scans every row). A stored analysis older than "max_age_minutes", or
    | made before the table's schema changed, is run again.
    |
    */

    'analysis' => [
        'required' => (bool) env('NYK_DB_SYNC_ANALYSIS_REQUIRED', true),
        'sample' => 0,
        'max_age_minutes' => 1440,
    ],

    /*
    |--------------------------------------------------------------------------
    | Eloquent observers
    |--------------------------------------------------------------------------
    |
    | Models under path are observed so saves, deletes, and pivot changes are
    | replicated even when they bypass the query builder. Query-builder writes
    | are replicated as well.
    |
    */

    'models' => [
        'discover' => true,
        'namespace' => 'App\\Models',
        'path' => null,
        'extra' => [
            // \Spatie\Permission\Models\Role::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin UI
    |--------------------------------------------------------------------------
    |
    | Off until NYK_DB_SYNC_UI is true. Point middleware and gate at whatever
    | guards database administration in the host app.
    |
    */

    'ui' => [
        'enabled' => (bool) env('NYK_DB_SYNC_UI', false),
        'path' => env('NYK_DB_SYNC_PATH', 'nyk-db-sync'),
        'middleware' => ['web', 'auth'],

        /*
        | The Gate ability a user needs to open the page and use any action on
        | it. With no gate the page answers 403, because it can run syncs, rewrite
        | .env and take database superuser credentials. Set allow_without_gate
        | only when the middleware above already limits the page to admins.
        */

        'gate' => env('NYK_DB_SYNC_GATE'),
        'allow_without_gate' => (bool) env('NYK_DB_SYNC_ALLOW_UNGATED', false),

        /*
        | Row counts for every table are shown on the page. Counting is slow on
        | large tables, so the result is kept for this many seconds. 0 counts on
        | every page view. A sync or a switch clears it.
        */

        'counts_cache_seconds' => 30,

        /*
        | Leave layout empty for a standalone page. Set it to the host view
        | that wraps the admin area, and section to that view's @yield name,
        | so the sync UI fills the content area beside an existing sidebar.
        |
        | component is the alternative for <x-app-layout> style shells. Set
        | layout or component, not both. The same UI can also be placed on
        | any existing page with <x-nyk-db-sync />.
        |
        */

        'layout' => env('NYK_DB_SYNC_LAYOUT'),
        'section' => env('NYK_DB_SYNC_SECTION', 'content'),
        'component' => env('NYK_DB_SYNC_COMPONENT'),
    ],

];
