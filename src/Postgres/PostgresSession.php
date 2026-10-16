<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Postgres;

interface PostgresSession
{
    /**
     * @param  list<mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array;

    public function statement(string $sql): void;

    public function useDatabase(string $database): void;
}
