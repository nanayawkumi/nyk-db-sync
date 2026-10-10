<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Sync;

use BackedEnum;
use DateTimeInterface;
use UnitEnum;

final class RowNormalizer
{
    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, mixed>>  $columns
     * @return array<string, mixed>
     */
    public function normalize(array $row, array $columns, string $driver): array
    {
        foreach ($row as $key => $value) {
            $row[$key] = $this->normalizeValue(
                $value,
                $columns[$key] ?? null,
                $driver,
            );
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function normalizeValue(mixed $value, ?array $meta, string $driver): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof UnitEnum) {
            $value = $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            $value = $this->formatDate($value, $meta);
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_THROW_ON_ERROR);
        }

        if (is_string($value) && in_array($value, ['0000-00-00', '0000-00-00 00:00:00'], true)) {
            return null;
        }

        if ($meta !== null && $driver === 'pgsql' && $this->isBoolean($meta)) {
            return $this->toBool($value);
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function formatDate(DateTimeInterface $value, ?array $meta): string
    {
        $type = strtolower((string) ($meta['type_name'] ?? ''));

        if ($type === 'date') {
            return $value->format('Y-m-d');
        }

        if ($type === 'time') {
            return $value->format('H:i:s');
        }

        return $value->format('Y-m-d H:i:s');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function isBoolean(array $meta): bool
    {
        $typeName = strtolower((string) ($meta['type_name'] ?? ''));
        $type = strtolower((string) ($meta['type'] ?? ''));

        return in_array($typeName, ['bool', 'boolean'], true)
            || $type === 'boolean'
            || $type === 'bool'
            || str_starts_with($type, 'tinyint(1)');
    }

    private function toBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 't', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
