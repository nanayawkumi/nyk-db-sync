<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Nanayawkumi\NykDbSync\Postgres\PostgresDatabaseCreator;
use Nanayawkumi\NykDbSync\Postgres\PostgresSessionFactory;
use Nanayawkumi\NykDbSync\TableName;
use Throwable;

class GrantPostgresPrivilegesCommand extends Command
{
    protected $signature = 'nyk-db-sync:grant
        {--host= : Postgres host. Defaults to the pgsql connection.}
        {--port= : Postgres port. Defaults to the pgsql connection.}
        {--database= : Database name. Defaults to the pgsql connection.}
        {--username= : App login to grant. Defaults to the pgsql connection.}
        {--admin-username=postgres : Superuser login used to connect.}
        {--admin-password= : Password for that superuser. Empty uses local trust or peer auth.}';

    protected $description = 'Grant the Postgres login CONNECT plus USAGE and CREATE on schema public';

    public function handle(PostgresDatabaseCreator $creator, PostgresSessionFactory $sessions): int
    {
        try {
            $input = $this->details();
            $session = $sessions->open($input['host'], $input['port'], $input['admin_password'], $input['admin_username']);
            $creator->grant($session, $input['database'], $input['username']);
        } catch (InvalidArgumentException|Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Granted CONNECT, USAGE, and CREATE on [{$input['database']}] to [{$input['username']}].");

        return self::SUCCESS;
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, admin_username: string, admin_password: string}
     */
    private function details(): array
    {
        $configured = config('database.connections.pgsql');
        $configured = is_array($configured) ? $configured : [];
        $host = $this->stringOption('host');
        $port = $this->stringOption('port');
        $database = $this->stringOption('database');
        $username = $this->stringOption('username');
        $adminUsername = $this->stringOption('admin-username');
        $adminPassword = $this->option('admin-password');

        if ($host === '') {
            $host = is_string($configured['host'] ?? null) && $configured['host'] !== '' ? $configured['host'] : '127.0.0.1';
        }

        if ($port === '') {
            $port = isset($configured['port']) && (string) $configured['port'] !== '' ? (string) $configured['port'] : '5432';
        }

        if ($database === '') {
            $database = is_string($configured['database'] ?? null) ? $configured['database'] : '';
        }

        if ($username === '') {
            $username = is_string($configured['username'] ?? null) ? $configured['username'] : '';
        }

        if ($database === '') {
            $database = trim((string) $this->ask('Database name'));
        }

        if ($username === '') {
            $username = trim((string) $this->ask('App login'));
        }

        if ($adminUsername === '') {
            $adminUsername = 'postgres';
        }

        if (! is_string($adminPassword)) {
            $adminPassword = (string) $this->secret('Postgres superuser password (empty for local trust auth)');
        }

        if (preg_match('/^[A-Za-z0-9._-]+$/', $host) !== 1) {
            throw new InvalidArgumentException("The host [{$host}] is not a hostname or address.");
        }

        if (preg_match('/^[0-9]+$/', $port) !== 1) {
            throw new InvalidArgumentException("The port [{$port}] must be a number.");
        }

        if (! TableName::isSafe($adminUsername)) {
            throw new InvalidArgumentException("The superuser [{$adminUsername}] may contain only letters, numbers, and underscores.");
        }

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'admin_username' => $adminUsername,
            'admin_password' => $adminPassword,
        ];
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }
}
