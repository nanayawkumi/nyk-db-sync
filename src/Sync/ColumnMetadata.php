<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Sync;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class ColumnMetadata
{
    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $cache = [];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function forTable(string $connection, string $table): array
    {
        $key = $connection.'.'.$table;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        try {
            $columns = Schema::connection($connection)->getColumns($table);
        } catch (Throwable) {
            return $this->cache[$key] = [];
        }

        $map = [];

        foreach ($columns as $column) {
            if (isset($column['name']) && is_string($column['name'])) {
                $map[$column['name']] = $column;
            }
        }

        return $this->cache[$key] = $map;
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
