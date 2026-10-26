<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Postgres;

final class PdoPostgresSessionFactory implements PostgresSessionFactory
{
    public function open(string $host, string $port, string $adminPassword, string $adminUsername = 'postgres'): PostgresSession
    {
        return new PdoPostgresSession($host, $port, $adminUsername, $adminPassword, 'postgres');
    }
}
