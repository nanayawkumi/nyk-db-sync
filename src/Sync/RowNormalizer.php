<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Sync;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Nanayawkumi\NykDbSync\Analysis\DecisionStore;
use UnitEnum;

final class RowNormalizer
{
    /**
     * @var array<string, array{at: int, version: int, fixes: array<string, list<string>>}>
     */
    private array $fixCache = [];

    public function __construct(private ?DecisionStore $decisions = null) {}

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, mixed>>  $columns
     * @return array<string, mixed>
     */
    public function normalize(array $row, array $columns, string $driver, ?string $table = null): array
    {
        $fixes = $table !== null ? $this->valueFixes($table) : [];

        foreach ($row as $key => $value) {
            $row[$key] = $this->normalizeValue(
                $value,
                $columns[$key] ?? null,
                $driver,
            );

            if (isset($fixes[$key]) && is_string($row[$key]) && in_array('strip_nul', $fixes[$key], true)) {
                $row[$key] = str_replace("\0", '', $row[$key]);
            }
        }

        return $row;
    }

    /**
     * Value fixes change rarely but are asked for on every row, so keep them for a moment.
     * A decision saved by this process drops the cache at once.
     *
     * @return array<string, list<string>>
     */
    private function valueFixes(string $table): array
    {
        if ($this->decisions === null) {
            return [];
        }

        $now = hrtime(true);
        $version = $this->decisions->version();
        $hit = $this->fixCache[$table] ?? null;

        if ($hit !== null && $hit['version'] === $version && ($now - $hit['at']) < 2_000_000_000) {
            return $hit['fixes'];
        }

        $fixes = $this->decisions->valueFixesFor($table);
        $this->fixCache[$table] = ['at' => $now, 'version' => $version, 'fixes' => $fixes];

        return $fixes;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function normalizeValue(mixed $value, ?array $meta, string $driver): mixed
    {
        if ($value === null) {
            return null;
        }

        // DB::raw() and increment() send expressions. They must reach the replica as expressions.
        if ($value instanceof Expression) {
            return $value;
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

        if (is_string($value)
            && in_array($value, ['0000-00-00', '0000-00-00 00:00:00'], true)
            && ($meta === null || $this->isTemporal($meta))) {
            return null;
        }

        if ($meta !== null && $driver === 'pgsql' && $this->isBoolean($meta)) {
            return $this->toBool($value);
        }

        // A bytea value sent as text breaks on backslashes and invalid UTF-8, and is cut at a NUL byte.
        // A stream is bound as a large object, which is sent byte for byte.
        if (is_string($value) && $meta !== null && $driver === 'pgsql' && $this->isBinary($meta)) {
            return $this->toStream($value);
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
    private function isTemporal(array $meta): bool
    {
        $type = strtolower((string) ($meta['type_name'] ?? $meta['type'] ?? ''));

        return str_contains($type, 'date') || str_contains($type, 'time');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function isBinary(array $meta): bool
    {
        return strtolower((string) ($meta['type_name'] ?? '')) === 'bytea'
            || str_starts_with(strtolower((string) ($meta['type'] ?? '')), 'bytea');
    }

    /**
     * @return resource|string
     */
    private function toStream(string $value): mixed
    {
        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            return $value;
        }

        fwrite($stream, $value);
        rewind($stream);

        return $stream;
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
