<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Postgres;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nanayawkumi\NykDbSync\Support\DotenvEditor;
use Nanayawkumi\NykDbSync\TableName;

final class PostgresDatabaseSetup
{
    public function __construct(
        private PostgresDatabaseCreator $creator,
        private PostgresSessionFactory $sessions,
        private DotenvEditor $dotenv,
    ) {}

    public function generatePassword(): string
    {
        return Str::password(32);
    }

    public function install(string $host, string $port, string $database, string $username, string $password, string $adminPassword): void
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $host) !== 1) {
            throw new InvalidArgumentException("The host [{$host}] is not a hostname or address.");
        }

        if (preg_match('/^[0-9]+$/', $port) !== 1) {
            throw new InvalidArgumentException("The port [{$port}] must be a number.");
        }

        if (! TableName::isSafe($database)) {
            throw new InvalidArgumentException("The database [{$database}] may contain only letters, numbers, and underscores.");
        }

        if (! TableName::isSafe($username)) {
            throw new InvalidArgumentException("The username [{$username}] may contain only letters, numbers, and underscores.");
        }

        if ($password === '') {
            throw new InvalidArgumentException('The login password cannot be empty.');
        }

        $session = $this->sessions->open($host, $port, $adminPassword);
        $this->creator->create($session, $database, $username, $password);
        $this->dotenv->update([
            'PGSQL_HOST' => $host,
            'PGSQL_PORT' => $port,
            'PGSQL_DATABASE' => $database,
            'PGSQL_USERNAME' => $username,
            'PGSQL_PASSWORD' => $password,
        ]);
        Artisan::call('config:clear');
    }
}
