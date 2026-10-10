<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Illuminate\Support\Facades\DB;
use Nanayawkumi\NykDbSync\NykDbSyncServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            NykDbSyncServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => '1',
            'database' => 'nyk_db_sync_absent',
            'username' => 'postgres',
            'password' => '',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
        $app['config']->set('database.connections.sqlite_secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('nyk-db-sync.secondary', 'sqlite_secondary');
        $app['config']->set('nyk-db-sync.enabled', false);
        $app['config']->set('nyk-db-sync.ui.enabled', true);
        $app['config']->set('nyk-db-sync.ui.middleware', ['web']);
        $app['config']->set('nyk-db-sync.ui.gate', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::purge('sqlite');
        DB::purge('sqlite_secondary');
    }
}
