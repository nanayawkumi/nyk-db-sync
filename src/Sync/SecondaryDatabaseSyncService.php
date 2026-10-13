<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Nanayawkumi\NykDbSync\DualWrite;
use Nanayawkumi\NykDbSync\Schema\SchemaSynchronizer;
use Nanayawkumi\NykDbSync\Schema\TableCatalog;
use Nanayawkumi\NykDbSync\Support\SummaryStore;
use Nanayawkumi\NykDbSync\TableName;
use RuntimeException;
use Throwable;

final class SecondaryDatabaseSyncService
{
    public function __construct(
        protected DualWrite $dualWrite,
        protected SchemaSynchronizer $schema,
        protected TableCatalog $catalog,
        protected RowNormalizer $rows,
        protected ColumnMetadata $columns,
        protected SummaryStore $summaries,
        protected ForeignKeyGuard $foreignKeys,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function sync(
        ?string $onlyTable = null,
        int $chunk = 500,
        bool $withSchema = true,
        bool $schemaOnly = false,
    ): array {
        $primary = $this->dualWrite->primaryConnection();
        $secondary = $this->dualWrite->secondaryConnection();

        if ($secondary === null || $secondary === '') {
            throw new RuntimeException('No secondary database connection is configured.');
        }

        if ($secondary === $primary) {
            throw new RuntimeException('The secondary connection is the same as the primary.');
        }

        $chunk = max(1, $chunk);
        $tables = $this->tablesFor($primary, $onlyTable);
        $started = hrtime(true);
        $schemaSummary = [
            'created' => [],
            'columns_added' => [],
            'foreign_keys' => [],
        ];

        if ($withSchema) {
            $schemaSummary = $this->schema->ensure($primary, $secondary, $tables);
            $this->dualWrite->flushCaches();
        }

        $summary = [
            'primary' => $primary,
            'secondary' => $secondary,
            'tables' => [],
            'schema' => $schemaSummary,
            'migrations_copied' => 0,
            'ran_at' => now()->toDateTimeString(),
        ];

        if (! $schemaOnly) {
            $this->foreignKeys->withoutChecks($secondary, function () use ($primary, $secondary, $tables, $chunk, $onlyTable, &$summary): void {
                foreach ($tables as $table) {
                    $summary['tables'][] = $this->copyTable($primary, $secondary, $table, $chunk, $onlyTable !== null);
                }
            });
        }

        if ($onlyTable === null && config('nyk-db-sync.schema.copy_migration_repository', true)) {
            $summary['migrations_copied'] = $this->copyMigrationRepository($primary, $secondary, $withSchema, $chunk);
        }

        $copiedTables = array_column($summary['tables'], 'table');

        if ($summary['migrations_copied'] > 0) {
            $copiedTables[] = $this->catalog->migrationTable();
        }

        $this->repairPostgresSequences($secondary, $copiedTables);
        $this->repairPostgresSequences($primary, $copiedTables);
        $summary['rows_copied'] = (int) array_sum(array_column($summary['tables'], 'copied')) + (int) $summary['migrations_copied'];
        $summary['duration_seconds'] = round((hrtime(true) - $started) / 1e9, 3);
        $this->summaries->put($summary);

        return $summary;
    }

    /**
     * @param  list<array{table: string, source: int, destination?: int|null, delta?: int}>|null  $counts
     * @return array{rows: int, tables: int, label: string, note: string, basis: string}
     */
    public function estimate(?string $onlyTable = null, ?array $counts = null): array
    {
        $primary = $this->dualWrite->primaryConnection();
        $counts ??= $this->sourceCounts($primary, $onlyTable);
        $previous = $this->summaries->get();
        $rate = null;

        if (is_array($previous)) {
            $copied = (int) ($previous['rows_copied'] ?? 0);
            $seconds = (float) ($previous['duration_seconds'] ?? 0);

            if ($copied >= 50 && $seconds >= 1) {
                $rate = $copied / $seconds;
            }
        }

        $sample = null;

        if ($rate === null) {
            $sample = $this->sampleReadRate($primary, $counts);
        }

        return (new SyncEstimate())->build($counts, $rate, $sample);
    }

    /**
     * @return list<array{table: string, source: int, destination: int|null, delta: int}>
     */
    public function compareCounts(): array
    {
        $primary = $this->dualWrite->primaryConnection();
        $secondary = $this->dualWrite->secondaryConnection();

        if ($secondary === null) {
            return [];
        }

        $rows = [];

        foreach ($this->catalog->ordered($primary) as $table) {
            if (! Schema::connection($primary)->hasTable($table)) {
                continue;
            }

            try {
                $source = (int) DB::connection($primary)->table($table)->count();
            } catch (Throwable) {
                continue;
            }

            if (! Schema::connection($secondary)->hasTable($table)) {
                $rows[] = [
                    'table' => $table,
                    'source' => $source,
                    'destination' => null,
                    'delta' => $source,
                ];

                continue;
            }

            try {
                $destination = (int) DB::connection($secondary)->table($table)->count();
            } catch (Throwable) {
                continue;
            }

            $rows[] = [
                'table' => $table,
                'source' => $source,
                'destination' => $destination,
                'delta' => abs($source - $destination),
            ];
        }

        return $rows;
    }

    public function secondaryIsReachable(): bool
    {
        return $this->probe($this->dualWrite->secondaryConnection())['connected'];
    }

    /**
     * @return array{name: string, connected: bool, driver: string|null, database: string|null, error: string|null}
     */
    public function probe(?string $name): array
    {
        if ($name === null || $name === '') {
            return [
                'name' => '',
                'connected' => false,
                'driver' => null,
                'database' => null,
                'error' => 'Not configured',
            ];
        }

        $configuredDatabase = config("database.connections.{$name}.database");
        $configuredDriver = config("database.connections.{$name}.driver");

        try {
            $connection = DB::connection($name);
            $connection->getPdo();
            $database = $connection->getDatabaseName();

            return [
                'name' => $name,
                'connected' => true,
                'driver' => $connection->getDriverName(),
                'database' => $database !== '' ? $database : null,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'name' => $name,
                'connected' => false,
                'driver' => is_string($configuredDriver) ? $configuredDriver : null,
                'database' => is_string($configuredDatabase) && $configuredDatabase !== '' ? $configuredDatabase : null,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  list<array{table: string, source: int, destination: int|null, delta: int}>|null  $rows
     */
    public function misalignedReason(int $maxDelta = 0, ?array $rows = null): ?string
    {
        $minimum = (int) config('nyk-db-sync.alignment.minimum', 10);
        $ratio = (float) config('nyk-db-sync.alignment.ratio', 0.01);

        foreach ($rows ?? $this->compareCounts() as $row) {
            if ($row['destination'] === null) {
                return "{$row['table']} is missing on the secondary.";
            }

            $tolerance = max($maxDelta, $minimum, (int) ceil($row['source'] * $ratio));

            if ($row['delta'] > $tolerance) {
                return "{$row['table']} counts differ (source {$row['source']}, destination {$row['destination']}).";
            }
        }

        return null;
    }

    /**
     * @return list<array{table: string, source: int}>
     */
    private function sourceCounts(string $primary, ?string $onlyTable): array
    {
        $rows = [];

        foreach ($this->tablesFor($primary, $onlyTable) as $table) {
            if (! Schema::connection($primary)->hasTable($table)) {
                continue;
            }

            try {
                $rows[] = [
                    'table' => $table,
                    'source' => (int) DB::connection($primary)->table($table)->count(),
                ];
            } catch (Throwable) {
                continue;
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{table?: string, source: int}>  $counts
     */
    private function sampleReadRate(string $primary, array $counts): ?float
    {
        $table = null;
        $largest = 0;

        foreach ($counts as $row) {
            $source = (int) ($row['source'] ?? 0);

            if ($source > $largest && isset($row['table']) && is_string($row['table'])) {
                $largest = $source;
                $table = $row['table'];
            }
        }

        if ($table === null || ! TableName::isSafe($table)) {
            return null;
        }

        $started = hrtime(true);

        try {
            $read = DB::connection($primary)->table($table)->limit(100)->get()->count();
        } catch (Throwable) {
            return null;
        }

        $seconds = (hrtime(true) - $started) / 1e9;

        if ($read === 0 || $seconds < 0.01) {
            return null;
        }

        return $read / $seconds;
    }

    /**
     * @return list<string>
     */
    private function tablesFor(string $primary, ?string $onlyTable): array
    {
        if ($onlyTable === null || $onlyTable === '') {
            return $this->catalog->ordered($primary);
        }

        if (! $this->catalog->isSyncable($primary, $onlyTable)) {
            throw new \InvalidArgumentException("Table [{$onlyTable}] is not available to sync.");
        }

        return [$onlyTable];
    }

    /**
     * @return array{table: string, copied: int, source: int, destination: int}
     */
    private function copyTable(string $primary, string $secondary, string $table, int $chunk, bool $required): array
    {
        if (! Schema::connection($primary)->hasTable($table)) {
            if ($required) {
                throw new \InvalidArgumentException("Table [{$table}] does not exist on the primary connection.");
            }

            return $this->emptyCopy($table);
        }

        if (! Schema::connection($secondary)->hasTable($table)) {
            if ($required) {
                throw new \InvalidArgumentException("Table [{$table}] does not exist on the secondary connection.");
            }

            Log::warning('[nyk-db-sync] Secondary sync skipped a missing table', ['table' => $table]);

            return [
                'table' => $table,
                'copied' => 0,
                'source' => (int) DB::connection($primary)->table($table)->count(),
                'destination' => 0,
            ];
        }

        $uniqueBy = $this->uniqueColumns($primary, $table);
        $destinationColumns = array_flip(Schema::connection($secondary)->getColumnListing($table));
        $uniqueBy = array_values(array_filter(
            $uniqueBy,
            fn (string $column): bool => isset($destinationColumns[$column]),
        ));

        if ($uniqueBy === []) {
            throw new RuntimeException("Table [{$table}] has no key to upsert on.");
        }

        $copied = 0;
        $metadata = $this->columns->forTable($secondary, $table);
        $driver = DB::connection($secondary)->getDriverName();

        $this->dualWrite->withoutReplication(function () use ($primary, $secondary, $table, $chunk, $uniqueBy, $destinationColumns, $metadata, $driver, &$copied): void {
            $query = DB::connection($primary)->table($table);
            $callback = function ($rows) use ($secondary, $table, $uniqueBy, $destinationColumns, $metadata, $driver, &$copied): void {
                $payload = [];

                foreach ($rows as $row) {
                    $normalized = $this->rows->normalize((array) $row, $metadata, $driver);
                    $filtered = array_intersect_key($normalized, $destinationColumns);

                    if ($filtered !== []) {
                        $payload[] = $filtered;
                    }
                }

                if ($payload === []) {
                    return;
                }

                DB::connection($secondary)->table($table)->upsert($payload, $uniqueBy);
                $copied += count($payload);
            };

            if (count($uniqueBy) === 1) {
                $query->chunkById($chunk, $callback, $uniqueBy[0]);

                return;
            }

            foreach ($uniqueBy as $column) {
                $query->orderBy($column);
            }

            $query->chunk($chunk, $callback);
        });

        return [
            'table' => $table,
            'copied' => $copied,
            'source' => (int) DB::connection($primary)->table($table)->count(),
            'destination' => (int) DB::connection($secondary)->table($table)->count(),
        ];
    }

    /**
     * @return array{table: string, copied: int, source: int, destination: int}
     */
    private function emptyCopy(string $table): array
    {
        return [
            'table' => $table,
            'copied' => 0,
            'source' => 0,
            'destination' => 0,
        ];
    }

    private function copyMigrationRepository(string $primary, string $secondary, bool $withSchema, int $chunk): int
    {
        $table = $this->catalog->migrationTable();

        if (! TableName::isSafe($table) || ! Schema::connection($primary)->hasTable($table)) {
            return 0;
        }

        if ($withSchema && ! Schema::connection($secondary)->hasTable($table)) {
            $this->schema->ensure($primary, $secondary, [$table]);
            $this->dualWrite->flushCaches();
        }

        if (! Schema::connection($secondary)->hasTable($table)) {
            Log::warning('[nyk-db-sync] Migration repository was not copied because the secondary table is missing', [
                'table' => $table,
            ]);

            return 0;
        }

        return $this->copyTable($primary, $secondary, $table, $chunk, true)['copied'];
    }

    /**
     * @return list<string>
     */
    private function uniqueColumns(string $connection, string $table): array
    {
        $indexes = Schema::connection($connection)->getIndexes($table);

        foreach ($indexes as $index) {
            if (($index['primary'] ?? false) === true && ! empty($index['columns'])) {
                return array_values($index['columns']);
            }
        }

        $columns = Schema::connection($connection)->getColumnListing($table);

        if (in_array('id', $columns, true)) {
            return ['id'];
        }

        if (in_array('key', $columns, true)) {
            return ['key'];
        }

        if (in_array('email', $columns, true)) {
            return ['email'];
        }

        return array_values($columns);
    }

    /**
     * Align serial sequences on a Postgres connection so the next insert does not collide with copied ids.
     *
     * @param  list<string>|null  $tables
     */
    public function repairSequences(?string $connection = null, ?array $tables = null): void
    {
        $connection ??= $this->postgresConnection();

        try {
            if (DB::connection($connection)->getDriverName() !== 'pgsql') {
                return;
            }
        } catch (Throwable $exception) {
            Log::warning('[nyk-db-sync] Could not repair Postgres sequences', [
                'connection' => $connection,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        try {
            if ($tables === null) {
                $tables = $this->catalog->discovered($connection);
            }

            $this->repairPostgresSequences($connection, $tables);
        } catch (Throwable $exception) {
            Log::warning('[nyk-db-sync] Could not repair Postgres sequences', [
                'connection' => $connection,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function postgresConnection(): string
    {
        $primary = $this->dualWrite->primaryConnection();

        if ($primary === 'pgsql') {
            return $primary;
        }

        return $this->dualWrite->secondaryConnection() ?: 'pgsql';
    }

    /**
     * @param  list<string>  $tables
     */
    private function repairPostgresSequences(string $connection, array $tables): void
    {
        try {
            if (DB::connection($connection)->getDriverName() !== 'pgsql') {
                return;
            }
        } catch (Throwable $exception) {
            Log::warning('[nyk-db-sync] Could not repair Postgres sequences', [
                'connection' => $connection,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        foreach ($tables as $table) {
            if (! TableName::isSafe($table) || ! Schema::connection($connection)->hasColumn($table, 'id')) {
                continue;
            }

            $quoted = '"'.str_replace('"', '""', $table).'"';

            try {
                DB::connection($connection)->select(
                    "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$quoted}), 1), true)",
                );
            } catch (Throwable $exception) {
                Log::warning('[nyk-db-sync] Could not repair a Postgres sequence', [
                    'table' => $table,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
