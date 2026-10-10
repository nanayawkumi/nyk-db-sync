<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Nanayawkumi\NykDbSync\Sync\ColumnMetadata;
use Nanayawkumi\NykDbSync\Sync\RowNormalizer;
use Throwable;

class DualWrite
{
    /**
     * @var array<string, true>
     */
    protected array $secondaryTables = [];

    protected static bool $replicating = false;

    public function __construct(
        protected ColumnMetadata $columns,
        protected RowNormalizer $rows,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('nyk-db-sync.enabled')
            && filled($this->secondaryConnection())
            && $this->secondaryConnection() !== $this->primaryConnection();
    }

    public function primaryConnection(): string
    {
        return (string) config('database.default');
    }

    public function secondaryConnection(): ?string
    {
        $configured = config('nyk-db-sync.secondary');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return match ($this->primaryConnection()) {
            'mysql', 'mariadb' => 'pgsql',
            'pgsql' => 'mysql',
            'sqlite' => 'sqlite_secondary',
            default => null,
        };
    }

    public function isReplicating(): bool
    {
        return self::$replicating;
    }

    public function withoutReplication(callable $callback): mixed
    {
        $previous = self::$replicating;
        self::$replicating = true;

        try {
            return $callback();
        } finally {
            self::$replicating = $previous;
        }
    }

    public function flushCaches(): void
    {
        $this->secondaryTables = [];
        $this->columns->flush();
    }

    public function shouldReplicate(): bool
    {
        return $this->enabled() && ! self::$replicating;
    }

    public function shouldReplicateConnection(?string $connection, ?string $table = null): bool
    {
        if (! $this->shouldReplicate()) {
            return false;
        }

        $connection ??= $this->primaryConnection();

        if ($connection !== $this->primaryConnection()) {
            return false;
        }

        if ($table === null || $table === '') {
            return true;
        }

        if (! TableName::isSafe($table) || $this->isSkipped($table)) {
            return false;
        }

        return $this->secondaryHasTable($table);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function normalizeForSecondary(?string $table, array $row): array
    {
        $connection = $this->secondaryConnection();
        $meta = [];
        $driver = 'mysql';

        if ($table !== null && $connection !== null && TableName::isSafe($table)) {
            try {
                $meta = $this->columns->forTable($connection, $table);
                $driver = DB::connection($connection)->getDriverName();
            } catch (Throwable) {
                $meta = [];
            }
        }

        return $this->rows->normalize($row, $meta, $driver);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $uniqueBy
     */
    public function upsert(string $table, array $row, array $uniqueBy): void
    {
        if (! $this->shouldReplicateConnection($this->primaryConnection(), $table) || $row === [] || $uniqueBy === []) {
            return;
        }

        $this->withoutReplication(function () use ($table, $row, $uniqueBy): void {
            try {
                DB::connection($this->secondaryConnection())
                    ->table($table)
                    ->upsert([$this->normalizeForSecondary($table, $row)], $uniqueBy);
            } catch (Throwable $exception) {
                Log::warning('[nyk-db-sync] Dual-write upsert failed', [
                    'table' => $table,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $where
     */
    public function delete(string $table, array $where): void
    {
        if (! $this->shouldReplicateConnection($this->primaryConnection(), $table) || $where === []) {
            return;
        }

        $this->withoutReplication(function () use ($table, $where): void {
            try {
                $query = DB::connection($this->secondaryConnection())->table($table);

                foreach ($where as $column => $value) {
                    $query->where($column, $value);
                }

                $query->delete();
            } catch (Throwable $exception) {
                Log::warning('[nyk-db-sync] Dual-write delete failed', [
                    'table' => $table,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }

    public function replicateModel(Model $model): void
    {
        if (! $this->shouldReplicateModel($model)) {
            return;
        }

        $this->upsert($model->getTable(), $model->getAttributes(), $this->uniqueColumns($model));
    }

    public function deleteModel(Model $model): void
    {
        if (! $this->shouldReplicateModel($model)) {
            return;
        }

        $where = [];

        foreach ($this->uniqueColumns($model) as $column) {
            $where[$column] = $model->getAttribute($column);
        }

        $this->delete($model->getTable(), $where);
    }

    /**
     * @param  list<int|string>  $relatedIds
     * @param  array<int|string, array<string, mixed>>  $pivotAttributes
     */
    public function replicatePivot(Model $model, string $relationName, array $relatedIds, array $pivotAttributes = []): void
    {
        if (! $this->shouldReplicateModel($model) || ! method_exists($model, $relationName)) {
            return;
        }

        try {
            $relation = $model->{$relationName}();
        } catch (Throwable) {
            return;
        }

        if (! method_exists($relation, 'getTable')) {
            return;
        }

        $table = $relation->getTable();
        $foreignKey = $relation->getForeignPivotKeyName();
        $relatedKey = $relation->getRelatedPivotKeyName();
        $parentKey = $model->getAttribute($relation->getParentKeyName());

        foreach ($relatedIds as $relatedId) {
            $row = array_merge(
                [
                    $foreignKey => $parentKey,
                    $relatedKey => $relatedId,
                ],
                is_array($pivotAttributes[$relatedId] ?? null) ? $pivotAttributes[$relatedId] : [],
            );

            if (method_exists($relation, 'getMorphType')) {
                $row[$relation->getMorphType()] = $model->getMorphClass();
            }

            $uniqueBy = [$foreignKey, $relatedKey];

            if (method_exists($relation, 'getMorphType') && isset($row[$relation->getMorphType()])) {
                $uniqueBy[] = $relation->getMorphType();
            }

            $this->upsert($table, $row, $uniqueBy);
        }
    }

    /**
     * @param  list<int|string>  $relatedIds
     */
    public function deletePivot(Model $model, string $relationName, array $relatedIds): void
    {
        if (! $this->shouldReplicateModel($model) || ! method_exists($model, $relationName)) {
            return;
        }

        try {
            $relation = $model->{$relationName}();
        } catch (Throwable) {
            return;
        }

        if (! method_exists($relation, 'getTable')) {
            return;
        }

        foreach ($relatedIds as $relatedId) {
            $where = [
                $relation->getForeignPivotKeyName() => $model->getAttribute($relation->getParentKeyName()),
                $relation->getRelatedPivotKeyName() => $relatedId,
            ];

            if (method_exists($relation, 'getMorphType')) {
                $where[$relation->getMorphType()] = $model->getMorphClass();
            }

            $this->delete($relation->getTable(), $where);
        }
    }

    /**
     * @param  callable(Builder): mixed  $operation
     */
    public function replicateQuery(string $connection, string $table, callable $copyState, callable $operation): void
    {
        if (! $this->shouldReplicateConnection($connection, $table)) {
            return;
        }

        $this->withoutReplication(function () use ($table, $copyState, $operation): void {
            try {
                $query = DB::connection($this->secondaryConnection())->table($table);
                $copyState($query);
                $operation($query);
            } catch (Throwable $exception) {
                Log::warning('[nyk-db-sync] Dual-write query failed', [
                    'table' => $table,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }

    /**
     * An explicit id does not move a Postgres sequence. Set it to at least that id.
     */
    public function advancePostgresId(?string $connection, string $table, mixed $id): void
    {
        if ($connection === null || ! is_numeric($id) || ! TableName::isSafe($table)) {
            return;
        }

        try {
            if (DB::connection($connection)->getDriverName() !== 'pgsql') {
                return;
            }
        } catch (Throwable $exception) {
            Log::warning('[nyk-db-sync] Could not advance a Postgres sequence', [
                'table' => $table,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $quoted = '"'.str_replace('"', '""', $table).'"';
        $id = (int) $id;

        try {
            DB::connection($connection)->select(
                "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), GREATEST((SELECT MAX(id) FROM {$quoted}), {$id}), true)",
            );
        } catch (Throwable $exception) {
            Log::warning('[nyk-db-sync] Could not advance a Postgres sequence', [
                'table' => $table,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function secondaryHasTable(string $table): bool
    {
        $secondary = $this->secondaryConnection();

        if ($secondary === null) {
            return false;
        }

        $key = $secondary.'.'.$table;

        if (isset($this->secondaryTables[$key])) {
            return true;
        }

        try {
            $exists = Schema::connection($secondary)->hasTable($table);
        } catch (Throwable) {
            return false;
        }

        if ($exists) {
            $this->secondaryTables[$key] = true;
        }

        return $exists;
    }

    protected function shouldReplicateModel(Model $model): bool
    {
        $connection = $model->getConnectionName() ?: $this->primaryConnection();

        return $this->shouldReplicateConnection($connection, $model->getTable());
    }

    /**
     * @return list<string>
     */
    protected function uniqueColumns(Model $model): array
    {
        $key = $model->getKeyName();

        if (is_array($key)) {
            return array_values($key);
        }

        return [$key];
    }

    protected function isSkipped(string $table): bool
    {
        $skipped = config('nyk-db-sync.skip_tables', ['migrations']);

        return is_array($skipped) && in_array($table, $skipped, true);
    }
}
