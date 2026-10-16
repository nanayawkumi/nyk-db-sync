<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Optimize;

final class IndexAdvisor
{
    /**
     * @param  list<array{table: string, column: string, source: string}>  $uses
     * @param  array<string, list<array{columns?: list<string>, primary?: bool}>>  $originIndexes
     * @param  array<string, list<array{columns?: list<string>, primary?: bool}>>  $targetIndexes
     * @param  array<string, list<string>>  $columns
     * @return list<array{table: string, column: string, source: string, sql: string}>
     */
    public function recommend(array $uses, array $originIndexes, array $targetIndexes, array $columns): array
    {
        $recommended = [];

        foreach ($uses as $use) {
            $table = $use['table'];
            $column = $use['column'];
            $key = $table.'.'.$column;

            if (isset($recommended[$key]) || ! isset($columns[$table]) || ! in_array($column, $columns[$table], true)) {
                continue;
            }

            if ($this->covered($originIndexes[$table] ?? [], $column) || $this->covered($targetIndexes[$table] ?? [], $column)) {
                continue;
            }

            $recommended[$key] = [
                'table' => $table,
                'column' => $column,
                'source' => $use['source'],
                'sql' => sprintf(
                    'create index concurrently %s on %s (%s)',
                    $this->quote($this->indexName($table, $column)),
                    $this->quote($table),
                    $this->quote($column),
                ),
            ];
        }

        return array_values($recommended);
    }

    /**
     * @param  list<array{columns?: list<string>, primary?: bool}>  $indexes
     */
    private function covered(array $indexes, string $column): bool
    {
        foreach ($indexes as $index) {
            $columns = array_values($index['columns'] ?? []);

            if ($columns === []) {
                continue;
            }

            if ($columns[0] === $column || (($index['primary'] ?? false) && in_array($column, $columns, true))) {
                return true;
            }
        }

        return false;
    }

    private function indexName(string $table, string $column): string
    {
        $name = 'nyk_'.$table.'_'.$column.'_idx';

        if (strlen($name) <= 63) {
            return $name;
        }

        return substr($name, 0, 59).'_idx';
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
