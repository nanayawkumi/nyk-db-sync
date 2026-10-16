<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Postgres;

final class PdoPostgresSessionFactory implements PostgresSessionFactory
{
    public function open(string $host, string $port, string $adminPassword): PostgresSession
    {
        return new PdoPostgresSession($host, $port, $adminPassword, 'postgres');
    }
}
