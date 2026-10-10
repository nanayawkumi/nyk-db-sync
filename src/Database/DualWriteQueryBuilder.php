<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use Nanayawkumi\NykDbSync\DualWrite;
use Throwable;

class DualWriteQueryBuilder extends Builder
{
    /**
     * {@inheritdoc}
     */
    public function insert(array $values)
    {
        $result = parent::insert($values);

        $normalized = $this->normalizePayload($values);

        if ($normalized !== null) {
            $this->replicateWrite(fn (Builder $query) => $query->insert($normalized));
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function insertOrIgnore(array $values)
    {
        $result = parent::insertOrIgnore($values);

        $normalized = $this->normalizePayload($values);

        if ($normalized !== null) {
            $this->replicateWrite(fn (Builder $query) => $query->insertOrIgnore($normalized));
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $id = parent::insertGetId($values, $sequence);

        $row = $values;

        if ($id !== false && $id !== null && ! isset($row['id']) && ($sequence === null || $sequence === 'id')) {
            $row['id'] = $id;
        }

        $normalized = $this->normalizePayload($row);

        if ($normalized !== null) {
            $this->replicateWrite(fn (Builder $query) => $query->insert($normalized));
        }

        if (is_string($this->from) && isset($row['id'])) {
            $dualWrite = app(DualWrite::class);

            if ($dualWrite->shouldReplicateConnection($this->connection->getName(), $this->from)) {
                $dualWrite->advancePostgresId($dualWrite->secondaryConnection(), $this->from, $row['id']);
            }
        }

        return $id;
    }

    /**
     * {@inheritdoc}
     */
    public function insertUsing(array $columns, $query)
    {
        $result = parent::insertUsing($columns, $query);

        $this->replicateWrite(fn (Builder $replica) => $replica->insertUsing($columns, $query));

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function update(array $values)
    {
        $result = parent::update($values);

        $normalized = $this->normalizePayload($values);

        if ($normalized !== null) {
            $this->replicateWrite(fn (Builder $query) => $query->update($normalized));
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $result = parent::upsert($values, $uniqueBy, $update);

        $normalized = $this->normalizePayload($values);

        if ($normalized !== null) {
            $this->replicateWrite(fn (Builder $query) => $query->upsert($normalized, $uniqueBy, $update));
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($id = null)
    {
        $result = parent::delete($id);

        $this->replicateWrite(fn (Builder $query) => $query->delete($id));

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function truncate()
    {
        parent::truncate();

        $this->replicateWrite(fn (Builder $query) => $query->truncate());
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>|null
     */
    protected function normalizePayload(array $values): ?array
    {
        $table = is_string($this->from) ? $this->from : null;

        try {
            $dualWrite = app(DualWrite::class);

            if ($values !== [] && array_is_list($values) && isset($values[0]) && is_array($values[0])) {
                return array_map(
                    fn (array $row): array => $dualWrite->normalizeForSecondary($table, $row),
                    $values,
                );
            }

            return $dualWrite->normalizeForSecondary($table, $values);
        } catch (Throwable $exception) {
            Log::warning('[nyk-db-sync] Could not normalize a replica write', [
                'table' => $table,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  callable(Builder): mixed  $operation
     */
    protected function replicateWrite(callable $operation): void
    {
        $table = $this->from;

        if (! is_string($table)) {
            return;
        }

        app(DualWrite::class)->replicateQuery(
            $this->connection->getName(),
            $table,
            function (Builder $query): void {
                $query->wheres = $this->wheres;
                $query->groups = $this->groups;
                $query->havings = $this->havings;
                $query->orders = $this->orders;
                $query->limit = $this->limit;
                $query->offset = $this->offset;
                $query->joins = $this->joins;
                $query->distinct = $this->distinct;
                $query->bindings = $this->bindings;
                $query->unions = $this->unions;
                $query->unionLimit = $this->unionLimit;
                $query->unionOffset = $this->unionOffset;
                $query->unionOrders = $this->unionOrders;
                $query->useWritePdo();
            },
            $operation,
        );
    }
}
