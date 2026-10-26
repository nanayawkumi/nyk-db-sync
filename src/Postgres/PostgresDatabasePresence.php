<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Postgres;

final class PostgresDatabasePresence
{
    public function missing(?string $database, bool $connected, ?string $error): bool
    {
        if ($database === null || $database === '') {
            return true;
        }

        if ($connected) {
            return false;
        }

        $error = strtolower((string) $error);

        return str_contains($error, '3d000') || str_contains($error, 'does not exist');
    }
}
