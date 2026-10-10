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
    | the greatest of "minimum" and source * ratio.
    |
    */

    'alignment' => [
        'minimum' => 10,
        'ratio' => 0.01,
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
        'gate' => null,
    ],

];
