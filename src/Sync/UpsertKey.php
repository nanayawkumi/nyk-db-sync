<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Sync;

use Illuminate\Support\Facades\Schema;

final class UpsertKey
{
    /**
     * The columns a row copy upserts on.
     *
     * @return list<string>
     */
    public function columns(string $connection, string $table): array
    {
        $indexes = Schema::connection($connection)->getIndexes($table);

        foreach ($indexes as $index) {
            if (($index['primary'] ?? false) === true && ! empty($index['columns'])) {
                return array_values($index['columns']);
            }
        }

        $columns = Schema::connection($connection)->getColumnListing($table);

        foreach (['id', 'key', 'email'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return [$candidate];
            }
        }

        return array_values($columns);
    }

    /**
     * Whether a primary or unique index covers exactly these columns. An upsert
     * (ON CONFLICT / ON DUPLICATE KEY) fails without one.
     *
     * @param  list<array<string, mixed>>  $indexes
     * @param  list<string>  $columns
     */
    public function isBackedByIndex(array $indexes, array $columns): bool
    {
        $wanted = $columns;
        sort($wanted);

        foreach ($indexes as $index) {
            if (! ($index['primary'] ?? false) && ! ($index['unique'] ?? false)) {
                continue;
            }

            $have = array_values($index['columns'] ?? []);
            sort($have);

            if ($have === $wanted) {
                return true;
            }
        }

        return false;
    }
}
