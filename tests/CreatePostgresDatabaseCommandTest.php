<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Nanayawkumi\NykDbSync\Postgres\PostgresSession;
use Nanayawkumi\NykDbSync\Postgres\PostgresSessionFactory;
use Nanayawkumi\NykDbSync\Tests\Fixtures\FakePostgresSession;

class CreatePostgresDatabaseCommandTest extends TestCase
{
    public function test_it_writes_pgsql_env_keys_from_the_typed_details(): void
    {
        $path = sys_get_temp_dir().'/nyk-db-sync-'.uniqid('', true);
        file_put_contents($path, "DB_CONNECTION=mysql\nPGSQL_HOST=old\nPGSQL_DATABASE=old\n");
        config(['nyk-db-sync.dotenv_path' => $path]);

        $session = new FakePostgresSession();
        $this->app->instance(PostgresSessionFactory::class, new class($session) implements PostgresSessionFactory
        {
            public function __construct(private PostgresSession $session) {}

            public function open(string $host, string $port, string $adminPassword): PostgresSession
            {
                return $this->session;
            }
        });

        try {
            $this->artisan('nyk-db-sync:create-database', [
                '--host' => '127.0.0.1',
                '--port' => '5432',
                '--database' => 'geonet',
                '--username' => 'geonet_app',
                '--password' => 'secret',
                '--admin-password' => '',
            ])->assertSuccessful()
                ->expectsOutputToContain('Wrote PGSQL_HOST');

            $contents = (string) file_get_contents($path);

            $this->assertStringContainsString('DB_CONNECTION=mysql', $contents);
            $this->assertStringContainsString('PGSQL_HOST=127.0.0.1', $contents);
            $this->assertStringContainsString('PGSQL_PORT=5432', $contents);
            $this->assertStringContainsString('PGSQL_DATABASE=geonet', $contents);
            $this->assertStringContainsString('PGSQL_USERNAME=geonet_app', $contents);
            $this->assertStringContainsString('PGSQL_PASSWORD=secret', $contents);
            $this->assertStringNotContainsString('PGSQL_HOST=old', $contents);
            $this->assertSame([
                'create role "geonet_app" login password \'secret\'',
            ], array_values(array_filter(
                $session->statements,
                fn (string $sql): bool => str_starts_with($sql, 'create role'),
            )));
        } finally {
            foreach (glob($path.'.bak.*') ?: [] as $backup) {
                @unlink($backup);
            }

            @unlink($path);
        }
    }

    public function test_it_can_generate_the_login_password(): void
    {
        $path = sys_get_temp_dir().'/nyk-db-sync-'.uniqid('', true);
        file_put_contents($path, "DB_CONNECTION=mysql\n");
        config(['nyk-db-sync.dotenv_path' => $path]);

        $this->app->instance(PostgresSessionFactory::class, new class(new FakePostgresSession()) implements PostgresSessionFactory
        {
            public function __construct(private PostgresSession $session) {}

            public function open(string $host, string $port, string $adminPassword): PostgresSession
            {
                return $this->session;
            }
        });

        try {
            $this->artisan('nyk-db-sync:create-database', [
                '--database' => 'geonet',
                '--username' => 'geonet_app',
                '--generate-password' => true,
                '--admin-password' => '',
            ])->assertSuccessful()
                ->expectsOutputToContain('Generated password:');

            $contents = (string) file_get_contents($path);

            $this->assertMatchesRegularExpression('/^PGSQL_PASSWORD=.+$/m', $contents);
            $this->assertStringNotContainsString('PGSQL_PASSWORD=secret', $contents);
        } finally {
            foreach (glob($path.'.bak.*') ?: [] as $backup) {
                @unlink($backup);
            }

            @unlink($path);
        }
    }

    public function test_it_rejects_a_database_name_with_spaces(): void
    {
        $this->artisan('nyk-db-sync:create-database', [
            '--database' => 'my app',
            '--username' => 'app_user',
            '--password' => 'secret',
            '--admin-password' => '',
        ])->assertFailed()
            ->expectsOutputToContain('letters, numbers, and underscores');
    }
}
