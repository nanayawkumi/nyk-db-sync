<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Nanayawkumi\NykDbSync\TableName;
use RuntimeException;
use Throwable;

final class SchemaSynchronizer
{
    /**
     * @var list<string>
     */
    private const AUTO_METHODS = [
        'increments',
        'bigIncrements',
        'smallIncrements',
        'mediumIncrements',
        'tinyIncrements',
    ];

    public function __construct(private ColumnTypeMapper $mapper) {}

    /**
     * @param  list<string>  $tables
     * @return array{created: list<string>, columns_added: array<string, list<string>>, foreign_keys: list<string>}
     */
    public function ensure(string $source, string $destination, array $tables): array
    {
        $created = [];
        $columnsAdded = [];
        $definitions = [];

        foreach ($tables as $table) {
            if (! TableName::isSafe($table) || ! Schema::connection($source)->hasTable($table)) {
                continue;
            }

            $definitions[$table] = $this->read($source, $table);

            try {
                if (! Schema::connection($destination)->hasTable($table)) {
                    if (! config('nyk-db-sync.schema.create_missing', true)) {
                        continue;
                    }

                    $this->createTable($destination, $table, $definitions[$table]);
                    $created[] = $table;

                    continue;
                }

                if (config('nyk-db-sync.schema.add_missing_columns', true)) {
                    $added = $this->addMissingColumns($destination, $table, $definitions[$table]);

                    if ($added !== []) {
                        $columnsAdded[$table] = $added;
                    }
                }
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    "Could not translate [{$table}] onto [{$destination}]: {$exception->getMessage()}",
                    0,
                    $exception,
                );
            }
        }

        $foreignKeys = [];

        foreach ($definitions as $table => $definition) {
            if (! Schema::connection($destination)->hasTable($table)) {
                continue;
            }

            foreach ($this->addMissingForeignKeys($destination, $table, $definition['foreign_keys']) as $foreignKey) {
                $foreignKeys[] = $foreignKey;
            }
        }

        return [
            'created' => $created,
            'columns_added' => $columnsAdded,
            'foreign_keys' => $foreignKeys,
        ];
    }

    /**
     * @return array{columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>, foreign_keys: list<array<string, mixed>>}
     */
    private function read(string $connection, string $table): array
    {
        return [
            'columns' => Schema::connection($connection)->getColumns($table),
            'indexes' => Schema::connection($connection)->getIndexes($table),
            'foreign_keys' => Schema::connection($connection)->getForeignKeys($table),
        ];
    }

    /**
     * @param  array{columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>, foreign_keys: list<array<string, mixed>>}  $definition
     */
    private function createTable(string $destination, string $table, array $definition): void
    {
        $primaryColumns = $this->primaryColumns($definition['indexes']);
        $compositePrimary = count($primaryColumns) > 1;

        Schema::connection($destination)->create($table, function (Blueprint $blueprint) use ($definition, $compositePrimary, $destination, $table): void {
            $created = [];
            $auto = [];

            foreach ($definition['columns'] as $column) {
                $method = $this->addColumn($blueprint, $column, $compositePrimary);

                if ($method === null) {
                    continue;
                }

                $name = (string) $column['name'];
                $created[] = $name;

                if (in_array($method, self::AUTO_METHODS, true)) {
                    $auto[$name] = true;
                }
            }

            $this->addIndexes($blueprint, $definition['indexes'], $created, $auto);

            foreach ($definition['foreign_keys'] as $foreignKey) {
                if ($this->foreignKeyCanBeCreated($destination, $table, $foreignKey, $created)) {
                    $this->applyForeignKey($blueprint, $foreignKey);
                }
            }
        });
    }

    /**
     * @param  array{columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>, foreign_keys: list<array<string, mixed>>}  $definition
     * @return list<string>
     */
    private function addMissingColumns(string $destination, string $table, array $definition): array
    {
        $existing = Schema::connection($destination)->getColumnListing($table);
        $missing = array_values(array_filter(
            $definition['columns'],
            fn (array $column): bool => ! in_array($column['name'] ?? '', $existing, true),
        ));

        if ($missing === []) {
            return [];
        }

        $added = [];

        Schema::connection($destination)->table($table, function (Blueprint $blueprint) use ($missing, &$added): void {
            foreach ($missing as $column) {
                if ($this->addColumn($blueprint, $column, false) !== null) {
                    $added[] = (string) $column['name'];
                }
            }
        });

        return $added;
    }

    /**
     * @param  list<array<string, mixed>>  $foreignKeys
     * @return list<string>
     */
    private function addMissingForeignKeys(string $destination, string $table, array $foreignKeys): array
    {
        $driver = DB::connection($destination)->getDriverName();
        $pending = [];

        foreach ($foreignKeys as $foreignKey) {
            $columns = array_values($foreignKey['columns'] ?? []);

            if ($columns === [] || $this->foreignKeyExists($destination, $table, $columns)) {
                continue;
            }

            if (! $this->referencedTableExists($destination, $foreignKey)) {
                Log::warning('[nyk-db-sync] Skipped a foreign key because the referenced table is missing', [
                    'table' => $table,
                    'references' => $foreignKey['foreign_table'] ?? null,
                ]);

                continue;
            }

            $pending[] = $foreignKey;
        }

        if ($pending === []) {
            return [];
        }

        if ($driver === 'sqlite') {
            foreach ($pending as $foreignKey) {
                Log::warning('[nyk-db-sync] SQLite foreign key was not part of table creation and cannot be added later', [
                    'table' => $table,
                    'columns' => $foreignKey['columns'] ?? [],
                ]);
            }

            return [];
        }

        Schema::connection($destination)->table($table, function (Blueprint $blueprint) use ($pending): void {
            foreach ($pending as $foreignKey) {
                $this->applyForeignKey($blueprint, $foreignKey);
            }
        });

        $added = [];

        foreach ($pending as $foreignKey) {
            $columns = implode(',', $foreignKey['columns'] ?? []);
            $target = TableName::bare((string) ($foreignKey['foreign_table'] ?? ''));
            $added[] = "{$table}({$columns}) -> {$target}";
        }

        return $added;
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function addColumn(Blueprint $blueprint, array $column, bool $compositePrimary): ?string
    {
        $mapped = $this->mapper->map($column);

        foreach ($mapped['warnings'] as $warning) {
            Log::warning('[nyk-db-sync] '.$warning, [
                'column' => $column['name'] ?? null,
            ]);
        }

        if ($mapped['skipped']) {
            return null;
        }

        $method = $mapped['method'];

        if ($compositePrimary && in_array($method, self::AUTO_METHODS, true)) {
            $method = $this->downgrade($method);
        }

        $definition = $blueprint->{$method}((string) $column['name'], ...$mapped['parameters']);

        if (! in_array($method, self::AUTO_METHODS, true)) {
            if ($mapped['nullable']) {
                $definition->nullable();
            }

            if ($mapped['unsigned']) {
                $definition->unsigned();
            }

            if ($mapped['use_current']) {
                $definition->useCurrent();
            } elseif ($mapped['has_default']) {
                $definition->default($mapped['default']);
            }
        }

        if ($mapped['comment'] !== null) {
            $definition->comment($mapped['comment']);
        }

        return $method;
    }

    /**
     * @param  list<array<string, mixed>>  $indexes
     * @param  list<string>  $createdColumns
     * @param  array<string, true>  $autoColumns
     */
    private function addIndexes(Blueprint $blueprint, array $indexes, array $createdColumns, array $autoColumns): void
    {
        $seen = [];

        foreach ($indexes as $index) {
            $columns = array_values($index['columns'] ?? []);

            if ($columns === [] || array_diff($columns, $createdColumns) !== []) {
                continue;
            }

            $kind = ($index['primary'] ?? false) ? 'primary' : (($index['unique'] ?? false) ? 'unique' : 'index');
            $signature = $kind.':'.implode(',', $columns);

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;

            if ($kind === 'primary') {
                if (count($columns) === 1 && isset($autoColumns[$columns[0]])) {
                    continue;
                }

                $blueprint->primary($columns);

                continue;
            }

            if ($kind === 'unique') {
                $blueprint->unique($columns);

                continue;
            }

            $blueprint->index($columns);
        }
    }

    /**
     * @param  array<string, mixed>  $foreignKey
     * @param  list<string>  $createdColumns
     */
    private function foreignKeyCanBeCreated(string $destination, string $table, array $foreignKey, array $createdColumns): bool
    {
        $columns = array_values($foreignKey['columns'] ?? []);

        if ($columns === [] || array_diff($columns, $createdColumns) !== []) {
            return false;
        }

        $target = TableName::bare((string) ($foreignKey['foreign_table'] ?? ''));

        if ($target === $table) {
            return true;
        }

        return $target !== '' && Schema::connection($destination)->hasTable($target);
    }

    /**
     * @param  array<string, mixed>  $foreignKey
     */
    private function referencedTableExists(string $destination, array $foreignKey): bool
    {
        $target = TableName::bare((string) ($foreignKey['foreign_table'] ?? ''));

        return $target !== '' && Schema::connection($destination)->hasTable($target);
    }

    /**
     * @param  list<string>  $columns
     */
    private function foreignKeyExists(string $destination, string $table, array $columns): bool
    {
        try {
            return Schema::connection($destination)->hasForeignKey($table, $columns);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $foreignKey
     */
    private function applyForeignKey(Blueprint $blueprint, array $foreignKey): void
    {
        $columns = array_values($foreignKey['columns'] ?? []);
        $references = array_values($foreignKey['foreign_columns'] ?? []);
        $on = TableName::bare((string) ($foreignKey['foreign_table'] ?? ''));

        if ($columns === [] || $references === [] || $on === '') {
            return;
        }

        $definition = $blueprint->foreign($columns)->references($references)->on($on);
        $this->applyReferentialAction($definition, 'delete', (string) ($foreignKey['on_delete'] ?? ''));
        $this->applyReferentialAction($definition, 'update', (string) ($foreignKey['on_update'] ?? ''));
    }

    private function applyReferentialAction(object $definition, string $event, string $action): void
    {
        $action = strtolower(str_replace('_', ' ', trim($action)));

        $method = match ($action) {
            'cascade' => $event === 'delete' ? 'cascadeOnDelete' : 'cascadeOnUpdate',
            'set null' => $event === 'delete' ? 'nullOnDelete' : 'nullOnUpdate',
            'restrict' => $event === 'delete' ? 'restrictOnDelete' : 'restrictOnUpdate',
            default => null,
        };

        if ($method !== null) {
            $definition->{$method}();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $indexes
     * @return list<string>
     */
    private function primaryColumns(array $indexes): array
    {
        foreach ($indexes as $index) {
            if (($index['primary'] ?? false) && ! empty($index['columns'])) {
                return array_values($index['columns']);
            }
        }

        return [];
    }

    private function downgrade(string $method): string
    {
        return match ($method) {
            'bigIncrements' => 'bigInteger',
            'smallIncrements' => 'smallInteger',
            'mediumIncrements' => 'mediumInteger',
            'tinyIncrements' => 'tinyInteger',
            'increments' => 'integer',
            default => $method,
        };
    }
}
