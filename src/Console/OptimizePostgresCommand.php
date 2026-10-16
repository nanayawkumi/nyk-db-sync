<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nanayawkumi\NykDbSync\DualWrite;
use Nanayawkumi\NykDbSync\Optimize\IndexAdvisor;
use Nanayawkumi\NykDbSync\Optimize\PartitionRequest;
use Nanayawkumi\NykDbSync\Optimize\QueryIndexScanner;
use Nanayawkumi\NykDbSync\Schema\TableCatalog;
use Nanayawkumi\NykDbSync\TableName;
use Throwable;

class OptimizePostgresCommand extends Command
{
    protected $signature = 'nyk-db-sync:optimize
        {--path=* : Directories to scan. Defaults to app, routes, and database.}
        {--partition=* : Partition to plan, as table:column:day, table:column:month, or table:column:year.}
        {--apply : Create the recommended indexes on Postgres.}';

    protected $description = 'Recommend Postgres indexes from project queries, and print partition plans you name';

    public function handle(QueryIndexScanner $scanner, IndexAdvisor $advisor, DualWrite $dualWrite, TableCatalog $catalog): int
    {
        try {
            $partitions = array_map(
                fn (mixed $value): array => PartitionRequest::parse(is_string($value) ? $value : ''),
                (array) $this->option('partition'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $origin = $this->origin($dualWrite);

        if ($origin === null) {
            $this->error('The origin database must be the MySQL connection. This command only changes Postgres.');

            return self::FAILURE;
        }

        $paths = array_values(array_filter(
            (array) $this->option('path'),
            fn (mixed $path): bool => is_string($path) && $path !== '',
        ));

        if ($paths === []) {
            $paths = array_values(array_filter(
                [app_path(), base_path('routes'), base_path('database')],
                'is_dir',
            ));
        }

        $uses = $scanner->scan($paths);
        $tables = array_values(array_unique(array_merge(
            $catalog->discovered($origin),
            array_column($uses, 'table'),
            array_column($partitions, 'table'),
        )));
        $originIndexes = [];
        $targetIndexes = [];
        $columns = [];

        foreach ($tables as $table) {
            if (! is_string($table) || ! TableName::isSafe($table)) {
                continue;
            }

            $originIndexes[$table] = $this->indexes($origin, $table);
            $targetIndexes[$table] = $this->indexes('pgsql', $table);
            $columns[$table] = $this->columns($origin, $table);
        }

        $recommendations = $advisor->recommend($uses, $originIndexes, $targetIndexes, $columns);

        if ($recommendations === []) {
            $this->info('No new Postgres indexes. The origin already covers the columns this project queries, or those columns were not found.');
        } else {
            $this->table(
                ['Table', 'Column', 'Found in', 'Postgres SQL'],
                array_map(fn (array $row): array => [$row['table'], $row['column'], $row['source'], $row['sql']], $recommendations),
            );
        }

        foreach ($partitions as $partition) {
            $this->line('');
            $this->warn("Partition {$partition['table']} by {$partition['interval']} on {$partition['column']}.");

            if ($this->originIsPartitioned($origin, $partition['table'])) {
                $this->line('The origin already partitions this table, so no Postgres partition is recommended.');

                continue;
            }

            $this->line('The origin does not partition this table. Partitioning an existing Postgres table rewrites it, so this command prints the plan and does not run it.');
            $this->line(sprintf(
                'Create a new table like %s, partition it by range on %s, and copy rows into %s partitions.',
                $partition['table'],
                $partition['column'],
                $partition['interval'],
            ));
        }

        if (! (bool) $this->option('apply')) {
            $this->line('Nothing was changed. Pass --apply to create the recommended indexes on Postgres.');

            return self::SUCCESS;
        }

        if ($recommendations === []) {
            return self::SUCCESS;
        }

        $driver = DB::connection('pgsql')->getDriverName();

        if ($driver !== 'pgsql') {
            $this->error('Refusing to apply indexes because the pgsql connection is not Postgres.');

            return self::FAILURE;
        }

        foreach ($recommendations as $recommendation) {
            try {
                DB::connection('pgsql')->unprepared($recommendation['sql']);
                $this->info('Created index on '.$recommendation['table'].'.'.$recommendation['column'].'.');
            } catch (Throwable $exception) {
                $this->error($recommendation['table'].'.'.$recommendation['column'].': '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function origin(DualWrite $dualWrite): ?string
    {
        $primary = $dualWrite->primaryConnection();
        $secondary = $dualWrite->secondaryConnection();

        if (in_array($primary, ['mysql', 'mariadb'], true)) {
            return $primary;
        }

        if ($primary === 'pgsql' && in_array($secondary, ['mysql', 'mariadb'], true)) {
            return $secondary;
        }

        return null;
    }

    /**
     * @return list<array{columns: list<string>, primary: bool}>
     */
    private function indexes(string $connection, string $table): array
    {
        try {
            if (! Schema::connection($connection)->hasTable($table)) {
                return [];
            }

            $indexes = [];

            foreach (Schema::connection($connection)->getIndexes($table) as $index) {
                $columns = array_values(array_filter(
                    $index['columns'] ?? [],
                    fn (mixed $column): bool => is_string($column) && $column !== '',
                ));
                $indexes[] = [
                    'columns' => $columns,
                    'primary' => (bool) ($index['primary'] ?? false),
                ];
            }

            return $indexes;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function columns(string $connection, string $table): array
    {
        try {
            if (! Schema::connection($connection)->hasTable($table)) {
                return [];
            }

            return Schema::connection($connection)->getColumnListing($table);
        } catch (Throwable) {
            return [];
        }
    }

    private function originIsPartitioned(string $connection, string $table): bool
    {
        try {
            $driver = DB::connection($connection)->getDriverName();

            if (! in_array($driver, ['mysql', 'mariadb'], true)) {
                return false;
            }

            $row = DB::connection($connection)->selectOne(
                'select count(*) as aggregate from information_schema.partitions where table_schema = database() and table_name = ? and partition_name is not null',
                [$table],
            );

            return (int) ($row->aggregate ?? 0) > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
