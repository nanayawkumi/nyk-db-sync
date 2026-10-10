<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Console;

use Illuminate\Console\Command;
use Nanayawkumi\NykDbSync\Sync\SecondaryDatabaseSyncService;

class RepairPostgresSequencesCommand extends Command
{
    protected $signature = 'nyk-db-sync:repair-sequences
        {--connection= : Connection name (defaults to the Postgres side)}';

    protected $description = 'Set Postgres id sequences to MAX(id) so new inserts do not collide with copied rows';

    public function handle(SecondaryDatabaseSyncService $sync): int
    {
        $connection = $this->option('connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : $sync->postgresConnection();

        $sync->repairSequences($connection);

        $this->info('Postgres sequences were aligned to MAX(id) on '.$connection.'.');

        return self::SUCCESS;
    }
}
