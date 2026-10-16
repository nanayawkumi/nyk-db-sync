<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Postgres;

use PDO;

final class PdoPostgresSession implements PostgresSession
{
    private PDO $pdo;

    public function __construct(
        private string $host,
        private string $port,
        private string $adminPassword,
        string $database,
    ) {
        $this->pdo = $this->connect($database);
    }

    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function statement(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function useDatabase(string $database): void
    {
        $this->pdo = $this->connect($database);
    }

    private function connect(string $database): PDO
    {
        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $this->host, $this->port, $database),
            'postgres',
            $this->adminPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }
}
