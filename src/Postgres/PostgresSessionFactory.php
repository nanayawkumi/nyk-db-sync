<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Postgres;

interface PostgresSessionFactory
{
    public function open(string $host, string $port, string $adminPassword): PostgresSession;
}
