<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Nanayawkumi\NykDbSync\Sync\SecondaryDatabaseSyncService;
use Nanayawkumi\NykDbSync\Sync\SyncEstimate;
use Throwable;

class SyncSecondaryDatabaseCommand extends Command
{
    protected $signature = 'nyk-db-sync:sync
        {--table= : Copy a single table}
        {--chunk=500 : Rows per batch}
        {--schema-only : Translate schema and skip the row copy}
        {--no-schema : Copy rows into tables that already exist}';

    protected $description = 'Translate schema and copy rows from the primary database to the secondary connection';

    public function handle(SecondaryDatabaseSyncService $sync): int
    {
        $schemaOnly = (bool) $this->option('schema-only');
        $noSchema = (bool) $this->option('no-schema');

        if ($schemaOnly && $noSchema) {
            $this->error('Choose --schema-only or --no-schema, not both.');

            return self::FAILURE;
        }

        $table = $this->option('table');
        $table = is_string($table) && $table !== '' ? $table : null;

        try {
            if (! $schemaOnly) {
                $estimate = $sync->estimate($table);
                $this->info($estimate['label']);

                if ($estimate['note'] !== '') {
                    $this->line($estimate['note']);
                }
            }

            $summary = $sync->sync(
                $table,
                (int) $this->option('chunk'),
                ! $noSchema,
                $schemaOnly,
            );
        } catch (InvalidArgumentException|Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $created = $summary['schema']['created'] ?? [];

        if ($created !== []) {
            $this->info('Created tables: '.implode(', ', $created));
        }

        $this->info("Copied {$summary['primary']} → {$summary['secondary']} at {$summary['ran_at']}");

        if (isset($summary['duration_seconds'])) {
            $this->info('Finished in '.(new SyncEstimate())->duration((float) $summary['duration_seconds']).'.');
        }

        if (($summary['migrations_copied'] ?? 0) > 0) {
            $this->line('Migration repository rows copied: '.$summary['migrations_copied']);
        }

        if ($summary['tables'] !== []) {
            $this->table(
                ['Table', 'Copied', 'Source', 'Destination'],
                array_map(fn (array $row): array => [
                    $row['table'],
                    $row['copied'],
                    $row['source'],
                    $row['destination'],
                ], $summary['tables']),
            );
        }

        foreach ($summary['tables'] as $row) {
            if ($row['source'] !== $row['destination']) {
                $this->warn("Mismatch on {$row['table']}: source {$row['source']}, destination {$row['destination']}");
            }
        }

        return self::SUCCESS;
    }
}
