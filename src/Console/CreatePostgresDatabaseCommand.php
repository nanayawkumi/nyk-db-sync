<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Nanayawkumi\NykDbSync\Postgres\PostgresDatabaseSetup;
use Throwable;

class CreatePostgresDatabaseCommand extends Command
{
    protected $signature = 'nyk-db-sync:create-database
        {--host=127.0.0.1 : Postgres host}
        {--port=5432 : Postgres port}
        {--database= : Database name to create}
        {--username= : App login to create}
        {--password= : Password for that login}
        {--generate-password : Create a random login password instead of asking for one}
        {--admin-password= : Password for the postgres superuser. Empty uses local trust or peer auth.}';

    protected $description = 'Create an empty Postgres database and login, then write PGSQL_* into .env';

    public function handle(PostgresDatabaseSetup $setup): int
    {
        try {
            $input = $this->details($setup);
            $setup->install(
                $input['host'],
                $input['port'],
                $input['database'],
                $input['username'],
                $input['password'],
                $input['admin_password'],
            );
        } catch (InvalidArgumentException|Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Postgres database [{$input['database']}] is ready for login [{$input['username']}].");
        $this->line('Wrote PGSQL_HOST, PGSQL_PORT, PGSQL_DATABASE, PGSQL_USERNAME, and PGSQL_PASSWORD.');

        if ($input['generated']) {
            $this->line('Generated password: '.$input['password']);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string, admin_password: string, generated: bool}
     */
    private function details(PostgresDatabaseSetup $setup): array
    {
        $host = $this->stringOption('host');
        $port = $this->stringOption('port');
        $database = $this->stringOption('database');
        $username = $this->stringOption('username');
        $password = $this->stringOption('password');
        $generated = (bool) $this->option('generate-password');
        $adminPassword = $this->option('admin-password');

        if ($host === '') {
            $host = '127.0.0.1';
        }

        if ($port === '') {
            $port = '5432';
        }

        if ($database === '') {
            $database = trim((string) $this->ask('Database name'));
        }

        if ($username === '') {
            $username = trim((string) $this->ask('App login'));
        }

        if ($generated) {
            $password = $setup->generatePassword();
        } elseif ($password === '') {
            $password = (string) $this->secret('Password for that login');
        }

        if (! is_string($adminPassword)) {
            $adminPassword = (string) $this->secret('Postgres superuser password (empty for local trust auth)');
        }

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'admin_password' => $adminPassword,
            'generated' => $generated,
        ];
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }
}
