<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Postgres;

use InvalidArgumentException;
use Nanayawkumi\NykDbSync\TableName;

final class PostgresDatabaseCreator
{
    public function create(PostgresSession $session, string $database, string $username, string $password): void
    {
        $this->assertName($database, 'database');
        $this->assertName($username, 'username');

        if ($password === '') {
            throw new InvalidArgumentException('The login password cannot be empty.');
        }

        if (in_array(strtolower($username), ['postgres'], true)) {
            throw new InvalidArgumentException('Choose an app login other than postgres.');
        }

        if (in_array(strtolower($database), ['postgres', 'template0', 'template1'], true)) {
            throw new InvalidArgumentException('Choose an app database other than postgres.');
        }

        $role = $this->quote($username);
        $passwordLiteral = $this->literal($password);

        if ($this->exists($session, 'select 1 from pg_roles where rolname = ?', $username)) {
            $session->statement("alter role {$role} with login password {$passwordLiteral}");
        } else {
            $session->statement("create role {$role} login password {$passwordLiteral}");
        }

        $databaseSql = $this->quote($database);

        if (! $this->exists($session, 'select 1 from pg_database where datname = ?', $database)) {
            $session->statement("create database {$databaseSql} owner {$role} encoding {$this->literal('UTF8')} template template0");
        }

        $session->statement("grant connect on database {$databaseSql} to {$role}");
        $session->useDatabase($database);
        $session->statement("grant usage, create on schema public to {$role}");
    }

    private function exists(PostgresSession $session, string $sql, string $name): bool
    {
        return $session->select($sql, [$name]) !== [];
    }

    private function assertName(string $name, string $label): void
    {
        if (! TableName::isSafe($name)) {
            throw new InvalidArgumentException("The {$label} [{$name}] may contain only letters, numbers, and underscores.");
        }
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
