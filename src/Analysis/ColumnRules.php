<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Analysis;

/**
 * Decides whether one source column converts and copies cleanly. Every check
 * is a probe (a question asked of the source data) plus a judge (what the
 * answer means), so the rules can be tested with made-up answers.
 */
final class ColumnRules
{
    public const BLOCKER = 'blocker';

    public const WARNING = 'warning';

    public const INFO = 'info';

    private const INTEGER_METHODS = ['tinyInteger', 'smallInteger', 'mediumInteger', 'integer', 'bigInteger'];

    private const STRING_METHODS = ['string', 'char'];

    private const TEMPORAL_METHODS = ['date', 'dateTime', 'dateTimeTz', 'timestamp', 'timestampTz'];

    /**
     * @param  array<string, mixed>  $column  source column
     * @param  array<string, mixed>  $mapped  {@see \Nanayawkumi\NykDbSync\Schema\ColumnTypeMapper::map()}
     * @param  array{source_driver: string, dest_driver: string, dest_column: array<string, mixed>|null}  $context
     * @return list<array{kind: string, args: array<string, mixed>}>
     */
    public function probes(string $table, array $column, array $mapped, array $context): array
    {
        return array_map(
            static fn (array $check): array => ['kind' => $check['kind'], 'args' => $check['args']],
            $this->checks($table, $column, $mapped, $context),
        );
    }

    /**
     * @param  array<string, mixed>  $column
     * @param  array<string, mixed>  $mapped
     * @param  array{source_driver: string, dest_driver: string, dest_column: array<string, mixed>|null}  $context
     * @param  array<string, mixed>  $results  probe answers keyed by {@see self::probeKey()}
     * @return list<array<string, mixed>>
     */
    public function issues(string $table, array $column, array $mapped, array $context, array $results): array
    {
        $issues = [];
        $name = (string) $column['name'];

        foreach ($mapped['warnings'] as $warning) {
            $issues[] = $this->issue($table, $name, $mapped['skipped'] ? 'column_skipped' : 'mapping_note', self::INFO, 'schema', $warning, null, null);
        }

        if ($mapped['skipped']) {
            return $issues;
        }

        foreach ($this->checks($table, $column, $mapped, $context) as $check) {
            $key = self::probeKey($name, $check['kind'], $check['args']);

            if (! array_key_exists($key, $results) || $results[$key] === null) {
                continue;
            }

            $found = ($check['judge'])($results[$key]);

            if ($found === null) {
                continue;
            }

            $issues[] = $this->finish($table, $name, $found, $context);
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public static function probeKey(string $column, string $kind, array $args = []): string
    {
        return $column.':'.$kind.($args === [] ? '' : ':'.md5(json_encode($args)));
    }

    /**
     * @param  array<string, mixed>  $column
     * @param  array<string, mixed>  $mapped
     * @param  array{source_driver: string, dest_driver: string, dest_column: array<string, mixed>|null}  $context
     * @return list<array{kind: string, args: array<string, mixed>, judge: callable(mixed): (array<string, mixed>|null)}>
     */
    private function checks(string $table, array $column, array $mapped, array $context): array
    {
        if ($mapped['skipped']) {
            return [];
        }

        $checks = [];
        $name = (string) $column['name'];
        $method = $mapped['method'];
        $source = $context['source_driver'];
        $dest = $context['dest_driver'];
        $existing = $context['dest_column'];
        $sourceIsMysql = in_array($source, ['mysql', 'mariadb'], true);
        $sourceType = strtolower((string) ($column['type'] ?? ''));

        if (in_array($method, self::STRING_METHODS, true)) {
            $limit = $this->stringLimit($mapped, $existing);
            $declared = preg_match('/\((\d+)\)/', $sourceType, $m) === 1 ? (int) $m[1] : null;

            if ($limit !== null && ($declared === null || $declared > $limit)) {
                $checks[] = [
                    'kind' => 'max_length',
                    'args' => [],
                    'judge' => fn (mixed $longest): ?array => (int) $longest > $limit
                        ? $this->found(
                            'length_overflow',
                            self::BLOCKER,
                            'data',
                            "Values reach {$longest} characters but the destination column holds {$limit}.",
                            'Store the column as text so no value is cut off or rejected.',
                            ['kind' => 'column_type', 'method' => 'text', 'parameters' => [], 'label' => 'Store as text', 'existing' => true],
                        )
                        : null,
                ];
            }
        }

        if (in_array($method, self::INTEGER_METHODS, true)) {
            $target = $this->targetRange($mapped, $existing, $dest);
            $origin = $this->sourceRange($column, $source);

            if ($target !== null && $origin !== null && ! $this->contains($target, $origin)) {
                $checks[] = [
                    'kind' => 'range',
                    'args' => [],
                    'judge' => function (mixed $range) use ($target): ?array {
                        $low = (string) ($range['min'] ?? '');
                        $high = (string) ($range['max'] ?? '');

                        if ($low === '' && $high === '') {
                            return null;
                        }

                        $outside = ($low !== '' && $this->compare($low, $target['min']) < 0)
                            || ($high !== '' && $this->compare($high, $target['max']) > 0);

                        if (! $outside) {
                            return null;
                        }

                        $needsDecimal = $this->compare($high === '' ? '0' : $high, '9223372036854775807') > 0
                            || ($low !== '' && $this->compare($low, '-9223372036854775808') < 0);

                        return $this->found(
                            'integer_overflow',
                            self::BLOCKER,
                            'data',
                            "Values from {$low} to {$high} do not fit the destination integer ({$target['min']} to {$target['max']}).",
                            $needsDecimal ? 'Widen the column to decimal(20,0).' : 'Widen the column to bigint.',
                            $needsDecimal
                                ? ['kind' => 'column_type', 'method' => 'decimal', 'parameters' => [20, 0], 'label' => 'Widen to decimal(20,0)', 'existing' => true]
                                : ['kind' => 'column_type', 'method' => 'bigInteger', 'parameters' => [], 'label' => 'Widen to bigint', 'existing' => true],
                        );
                    },
                ];
            }
        }

        if (in_array($method, self::TEMPORAL_METHODS, true) && in_array($source, ['mysql', 'mariadb', 'sqlite'], true)) {
            $nullable = $existing !== null ? (bool) ($existing['nullable'] ?? true) : (bool) $mapped['nullable'];
            $checks[] = [
                'kind' => 'zero_dates',
                'args' => [],
                'judge' => fn (mixed $count): ?array => (int) $count > 0
                    ? ($nullable
                        ? $this->found('zero_dates', self::WARNING, 'data', "{$count} zero dates (0000-00-00) will be stored as NULL.", 'No action needed unless the zero date carries meaning.', null)
                        : $this->found(
                            'zero_dates_not_null',
                            self::BLOCKER,
                            'data',
                            "{$count} zero dates (0000-00-00) become NULL, but the destination column is NOT NULL.",
                            'Allow NULL on the destination column.',
                            ['kind' => 'nullable', 'label' => 'Allow NULL', 'existing' => true],
                        ))
                    : null,
            ];
        }

        if ($dest === 'pgsql' && $sourceIsMysql && (in_array($method, array_merge(self::STRING_METHODS, ['text']), true) || in_array($method, ['json', 'jsonb'], true))) {
            $checks[] = [
                'kind' => 'nul_bytes',
                'args' => [],
                'judge' => fn (mixed $count): ?array => (int) $count > 0
                    ? $this->found(
                        'nul_bytes',
                        self::BLOCKER,
                        'data',
                        "{$count} values contain a NUL byte (0x00), which Postgres rejects in text.",
                        'Strip the NUL bytes while copying.',
                        ['kind' => 'value', 'transform' => 'strip_nul', 'label' => 'Strip NUL bytes'],
                    )
                    : null,
            ];
        }

        if (in_array($method, ['json', 'jsonb'], true) && (in_array($source, ['mariadb', 'sqlite'], true) || ($sourceIsMysql && ($column['type_name'] ?? '') !== 'json'))) {
            $checks[] = [
                'kind' => 'invalid_json',
                'args' => [],
                'judge' => fn (mixed $count): ?array => (int) $count > 0
                    ? $this->found(
                        'invalid_json',
                        self::BLOCKER,
                        'data',
                        "{$count} values are not valid JSON and the destination column is JSON.",
                        'Store the column as text so the values are kept as they are.',
                        ['kind' => 'column_type', 'method' => 'text', 'parameters' => [], 'label' => 'Store as text', 'existing' => true],
                    )
                    : null,
            ];
        }

        if ($method === 'enum' && $sourceIsMysql && $mapped['parameters'] !== []) {
            $values = array_values($mapped['parameters'][0]);
            $checks[] = [
                'kind' => 'invalid_enum',
                'args' => ['values' => $values],
                'judge' => fn (mixed $count): ?array => (int) $count > 0
                    ? $this->found(
                        'invalid_enum',
                        self::BLOCKER,
                        'data',
                        "{$count} values are not in the enum list (MySQL stores a rejected value as an empty string).",
                        'Store the column as string so the existing values are kept.',
                        ['kind' => 'column_type', 'method' => 'string', 'parameters' => [255], 'label' => 'Store as string', 'existing' => false],
                    )
                    : null,
            ];
        }

        if ($method === 'boolean' && in_array($source, ['mysql', 'mariadb', 'sqlite'], true)) {
            $checks[] = [
                'kind' => 'non_binary_bool',
                'args' => [],
                'judge' => fn (mixed $count): ?array => (int) $count > 0
                    ? $this->found(
                        'lossy_boolean',
                        self::WARNING,
                        'data',
                        "{$count} values are neither 0 nor 1 and will be stored as true or false.",
                        'Keep the column as a small integer if those values matter.',
                        ['kind' => 'column_type', 'method' => 'tinyInteger', 'parameters' => [], 'label' => 'Keep as integer', 'existing' => false],
                    )
                    : null,
            ];
        }

        return $checks;
    }

    /**
     * Turn a judge result into a full issue, dropping fixes the package cannot
     * apply to a column that already exists on the destination.
     *
     * @param  array<string, mixed>  $found
     * @param  array{source_driver: string, dest_driver: string, dest_column: array<string, mixed>|null}  $context
     * @return array<string, mixed>
     */
    private function finish(string $table, string $name, array $found, array $context): array
    {
        $fix = $found['fix'];
        $recommendation = $found['recommendation'];

        if ($fix !== null && $context['dest_column'] !== null && ($fix['kind'] ?? '') !== 'value') {
            $alterable = ($fix['existing'] ?? false) === true && $context['dest_driver'] === 'pgsql';

            if (! $alterable) {
                $fix = null;
                $recommendation .= " [{$name}] already exists on the destination, so change that column by hand.";
            }
        }

        if ($fix !== null) {
            unset($fix['existing']);
        }

        return $this->issue($table, $name, $found['code'], $found['severity'], $found['phase'], $found['message'], $recommendation, $fix);
    }

    /**
     * @param  array<string, mixed>|null  $fix
     * @return array<string, mixed>
     */
    private function found(string $code, string $severity, string $phase, string $message, string $recommendation, ?array $fix): array
    {
        return compact('code', 'severity', 'phase', 'message', 'recommendation', 'fix');
    }

    /**
     * @param  array<string, mixed>|null  $fix
     * @return array<string, mixed>
     */
    public function issue(string $table, ?string $column, string $code, string $severity, string $phase, string $message, ?string $recommendation, ?array $fix): array
    {
        return [
            'id' => $table.'.'.($column ?? '*').'.'.$code,
            'table' => $table,
            'column' => $column,
            'code' => $code,
            'severity' => $severity,
            'phase' => $phase,
            'message' => $message,
            'recommendation' => $recommendation,
            'fix' => $fix,
        ];
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @param  array<string, mixed>|null  $existing
     */
    private function stringLimit(array $mapped, ?array $existing): ?int
    {
        if ($existing !== null) {
            $typeName = strtolower((string) ($existing['type_name'] ?? ''));

            if (! in_array($typeName, ['varchar', 'char', 'bpchar', 'character varying', 'character', 'nvarchar'], true)) {
                return null;
            }

            return preg_match('/\((\d+)\)/', (string) ($existing['type'] ?? ''), $m) === 1 ? (int) $m[1] : null;
        }

        return isset($mapped['parameters'][0]) && is_int($mapped['parameters'][0]) ? $mapped['parameters'][0] : null;
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @param  array<string, mixed>|null  $existing
     * @return array{min: string, max: string}|null
     */
    private function targetRange(array $mapped, ?array $existing, string $driver): ?array
    {
        if ($existing !== null) {
            return $this->rangeFromType((string) ($existing['type'] ?? ''), (string) ($existing['type_name'] ?? ''), $driver);
        }

        $bits = match ($mapped['method']) {
            'tinyInteger' => $driver === 'pgsql' ? 16 : 8,
            'smallInteger' => 16,
            'mediumInteger' => $driver === 'pgsql' ? 32 : 24,
            'integer' => 32,
            'bigInteger' => 64,
            default => null,
        };

        return $bits === null ? null : $this->range($bits, $driver !== 'pgsql' && $mapped['unsigned'], $driver);
    }

    /**
     * @param  array<string, mixed>  $column
     * @return array{min: string, max: string}|null
     */
    private function sourceRange(array $column, string $driver): ?array
    {
        return $this->rangeFromType((string) ($column['type'] ?? ''), (string) ($column['type_name'] ?? ''), $driver);
    }

    /**
     * @return array{min: string, max: string}|null
     */
    private function rangeFromType(string $type, string $typeName, string $driver): ?array
    {
        $name = strtolower($typeName !== '' ? $typeName : $type);
        $unsigned = str_contains(strtolower($type), 'unsigned');

        $bits = match (true) {
            str_contains($name, 'bigint'), $name === 'int8', $name === 'bigserial' => 64,
            str_contains($name, 'smallint'), $name === 'int2', $name === 'smallserial' => 16,
            str_contains($name, 'mediumint') => 24,
            str_contains($name, 'tinyint') => 8,
            in_array($name, ['int', 'integer', 'int4', 'serial'], true) => 32,
            default => null,
        };

        return $bits === null ? null : $this->range($bits, $unsigned, $driver);
    }

    /**
     * @return array{min: string, max: string}
     */
    private function range(int $bits, bool $unsigned, string $driver): array
    {
        if ($driver === 'sqlite') {
            $bits = 64;
            $unsigned = false;
        }

        if ($unsigned) {
            return ['min' => '0', 'max' => $bits === 64 ? '18446744073709551615' : (string) ((1 << $bits) - 1)];
        }

        return $bits === 64
            ? ['min' => '-9223372036854775808', 'max' => '9223372036854775807']
            : ['min' => (string) (-(1 << ($bits - 1))), 'max' => (string) ((1 << ($bits - 1)) - 1)];
    }

    /**
     * @param  array{min: string, max: string}  $outer
     * @param  array{min: string, max: string}  $inner
     */
    private function contains(array $outer, array $inner): bool
    {
        return $this->compare($outer['min'], $inner['min']) <= 0 && $this->compare($outer['max'], $inner['max']) >= 0;
    }

    /**
     * Compare two integers held as decimal strings, so 64-bit bounds do not
     * lose precision as floats.
     */
    public function compare(string $left, string $right): int
    {
        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');
        $left = ltrim($left, '-+');
        $right = ltrim($right, '-+');

        if ($left === '' || $left === '0') {
            $leftNegative = false;
        }

        if ($right === '' || $right === '0') {
            $rightNegative = false;
        }

        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        $magnitude = strlen($left) <=> strlen($right) ?: strcmp($left, $right) <=> 0;

        return $leftNegative ? -$magnitude : $magnitude;
    }
}
