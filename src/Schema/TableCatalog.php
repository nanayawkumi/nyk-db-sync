<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Schema;

use Illuminate\Support\Facades\Schema;
use Nanayawkumi\NykDbSync\TableName;
use Throwable;

final class TableCatalog
{
    /**
     * @return list<string>
     */
    public function discovered(string $connection): array
    {
        $names = [];

        foreach (Schema::connection($connection)->getTables() as $table) {
            $name = is_array($table) ? ($table['name'] ?? null) : null;

            if (! is_string($name) || ! TableName::isSafe($name) || str_starts_with($name, 'sqlite_')) {
                continue;
            }

            if ($this->isSkipped($name)) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * @param  list<string>|null  $only
     * @return list<string>
     */
    public function ordered(string $connection, ?array $only = null): array
    {
        $tables = $only ?? $this->discovered($connection);

        return $this->sort(
            $tables,
            fn (string $table): array => $this->dependencies($connection, $table),
        );
    }

    public function isSyncable(string $connection, string $table): bool
    {
        return TableName::isSafe($table)
            && ! $this->isSkipped($table)
            && Schema::connection($connection)->hasTable($table);
    }

    public function isSkipped(string $table): bool
    {
        $skipped = config('nyk-db-sync.skip_tables', ['migrations']);

        return is_array($skipped) && in_array($table, $skipped, true);
    }

    public function migrationTable(): string
    {
        $migrations = config('database.migrations');

        if (is_array($migrations) && is_string($migrations['table'] ?? null) && $migrations['table'] !== '') {
            return $migrations['table'];
        }

        if (is_string($migrations) && $migrations !== '') {
            return $migrations;
        }

        return 'migrations';
    }

    /**
     * @return list<string>
     */
    public function dependencies(string $connection, string $table): array
    {
        try {
            $keys = Schema::connection($connection)->getForeignKeys($table);
        } catch (Throwable) {
            return [];
        }

        $dependencies = [];

        foreach ($keys as $key) {
            $target = TableName::bare((string) ($key['foreign_table'] ?? ''));

            if ($target !== '' && $target !== $table && TableName::isSafe($target)) {
                $dependencies[] = $target;
            }
        }

        return array_values(array_unique($dependencies));
    }

    /**
     * @param  list<string>  $tables
     * @param  callable(string): list<string>  $dependencies
     * @return list<string>
     */
    public function sort(array $tables, callable $dependencies): array
    {
        $tables = array_values(array_unique($tables));
        $preferred = config('nyk-db-sync.table_order', []);
        $preferred = is_array($preferred) ? array_values(array_intersect($preferred, $tables)) : [];
        $rank = array_flip($preferred);

        $deps = [];

        foreach ($tables as $table) {
            $deps[$table] = array_values(array_filter(
                array_unique($dependencies($table)),
                fn (string $dependency): bool => $dependency !== $table && in_array($dependency, $tables, true),
            ));
        }

        $remaining = array_fill_keys($tables, true);
        $sorted = [];

        while ($remaining !== []) {
            $ready = [];

            foreach (array_keys($remaining) as $table) {
                $unmet = array_filter(
                    $deps[$table],
                    fn (string $dependency): bool => isset($remaining[$dependency]),
                );

                if ($unmet === []) {
                    $ready[] = $table;
                }
            }

            if ($ready === []) {
                $left = array_keys($remaining);
                sort($left);

                return array_merge($sorted, $left);
            }

            usort($ready, function (string $left, string $right) use ($rank): int {
                $leftRank = $rank[$left] ?? PHP_INT_MAX;
                $rightRank = $rank[$right] ?? PHP_INT_MAX;

                if ($leftRank !== $rightRank) {
                    return $leftRank <=> $rightRank;
                }

                return $left <=> $right;
            });

            $next = $ready[0];
            $sorted[] = $next;
            unset($remaining[$next]);
        }

        return $sorted;
    }
}
