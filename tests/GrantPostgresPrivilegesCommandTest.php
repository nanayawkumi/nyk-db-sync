<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Nanayawkumi\NykDbSync\Postgres\PostgresSession;
use Nanayawkumi\NykDbSync\Postgres\PostgresSessionFactory;
use Nanayawkumi\NykDbSync\Tests\Fixtures\FakePostgresSession;

class GrantPostgresPrivilegesCommandTest extends TestCase
{
    public function test_it_grants_the_configured_login_without_changing_env(): void
    {
        $session = new FakePostgresSession();
        $session->roles[] = 'app_user';
        $session->existingDatabases[] = 'peedocastdb';

        $this->app->instance(PostgresSessionFactory::class, new class($session) implements PostgresSessionFactory
        {
            public function __construct(private PostgresSession $session) {}

            public function open(string $host, string $port, string $adminPassword, string $adminUsername = 'postgres'): PostgresSession
            {
                return $this->session;
            }
        });

        config([
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => '5432',
            'database.connections.pgsql.database' => 'peedocastdb',
            'database.connections.pgsql.username' => 'app_user',
        ]);

        $this->artisan('nyk-db-sync:grant', [
            '--admin-password' => '',
        ])->assertSuccessful()
            ->expectsOutputToContain('Granted CONNECT, USAGE, and CREATE on [peedocastdb] to [app_user].');

        $this->assertSame([
            'grant connect on database "peedocastdb" to "app_user"',
            'grant usage, create on schema public to "app_user"',
        ], $session->statements);
    }

    public function test_it_connects_as_the_named_superuser(): void
    {
        $session = new FakePostgresSession();
        $session->roles[] = 'app_user';
        $session->existingDatabases[] = 'peedocastdb';

        $factory = new class($session) implements PostgresSessionFactory
        {
            public string $adminUsername = '';

            public function __construct(private PostgresSession $session) {}

            public function open(string $host, string $port, string $adminPassword, string $adminUsername = 'postgres'): PostgresSession
            {
                $this->adminUsername = $adminUsername;

                return $this->session;
            }
        };
        $this->app->instance(PostgresSessionFactory::class, $factory);

        config([
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => '5432',
            'database.connections.pgsql.database' => 'peedocastdb',
            'database.connections.pgsql.username' => 'app_user',
        ]);

        $this->artisan('nyk-db-sync:grant', [
            '--admin-username' => 'dbadmin',
            '--admin-password' => 'secret',
        ])->assertSuccessful();

        $this->assertSame('dbadmin', $factory->adminUsername);
    }
}
