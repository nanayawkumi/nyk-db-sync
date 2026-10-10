<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ForeignKeyGuard
{
    public function withoutChecks(string $connection, callable $callback): mixed
    {
        $db = DB::connection($connection);
        $driver = $db->getDriverName();

        [$disable, $restore] = match (true) {
            in_array($driver, ['mysql', 'mariadb'], true) => ['SET FOREIGN_KEY_CHECKS=0', 'SET FOREIGN_KEY_CHECKS=1'],
            $driver === 'pgsql' => ['SET session_replication_role = replica', 'SET session_replication_role = DEFAULT'],
            $driver === 'sqlite' => ['PRAGMA foreign_keys = OFF', 'PRAGMA foreign_keys = ON'],
            default => [null, null],
        };

        if ($disable === null || $restore === null) {
            return $callback();
        }

        try {
            $db->statement($disable);
        } catch (Throwable $exception) {
            Log::warning('[nyk-db-sync] Could not disable foreign key checks', [
                'connection' => $connection,
                'error' => $exception->getMessage(),
            ]);

            return $callback();
        }

        try {
            return $callback();
        } finally {
            try {
                $db->statement($restore);
            } catch (Throwable $exception) {
                Log::warning('[nyk-db-sync] Could not restore foreign key checks', [
                    'connection' => $connection,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
