<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests\Fixtures;

use Nanayawkumi\NykDbSync\Postgres\PostgresSession;

final class FakePostgresSession implements PostgresSession
{
    /** @var list<string> */
    public array $statements = [];

    /** @var list<string> */
    public array $roles = [];

    /** @var list<string> */
    public array $existingDatabases = [];

    /** @var list<string> */
    public array $databases = [];

    public function select(string $sql, array $bindings = []): array
    {
        $name = (string) ($bindings[0] ?? '');

        if (str_contains($sql, 'pg_roles') && in_array($name, $this->roles, true)) {
            return [['ok' => 1]];
        }

        if (str_contains($sql, 'pg_database') && in_array($name, $this->existingDatabases, true)) {
            return [['ok' => 1]];
        }

        return [];
    }

    public function statement(string $sql): void
    {
        $this->statements[] = $sql;
    }

    public function useDatabase(string $database): void
    {
        $this->databases[] = $database;
    }
}
