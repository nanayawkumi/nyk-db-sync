<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Schema;

final class ColumnTypeMapper
{
    /**
     * @param  array<string, mixed>  $column
     * @return array{
     *     method: string,
     *     parameters: list<mixed>,
     *     nullable: bool,
     *     default: mixed,
     *     has_default: bool,
     *     use_current: bool,
     *     unsigned: bool,
     *     comment: string|null,
     *     skipped: bool,
     *     warnings: list<string>
     * }
     */
    public function map(array $column): array
    {
        $name = (string) ($column['name'] ?? '');

        if ($column['generation'] ?? null) {
            return $this->skipped(["Skipped generated column [{$name}]."]);
        }

        $raw = trim((string) ($column['type'] ?? ''));
        $clean = strtolower($raw);
        $typeName = strtolower(trim((string) ($column['type_name'] ?? '')));
        $unsigned = str_contains($clean, 'unsigned');
        $clean = trim((string) preg_replace('/\s+/', ' ', str_replace(['unsigned', 'zerofill'], '', $clean)));

        $auto = (bool) ($column['auto_increment'] ?? false)
            || in_array($typeName, ['serial', 'bigserial', 'smallserial'], true);

        [$method, $parameters, $unsigned, $warnings] = $this->resolve($name, $raw, $clean, $typeName, $unsigned, $auto);

        $parsed = $this->parseDefault($column['default'] ?? null, $method);
        $nullable = $auto ? false : (bool) ($column['nullable'] ?? false);

        if ($parsed['has_default'] && $this->isTemporal($method) && $this->isZeroDate($parsed['default'])) {
            $parsed['has_default'] = false;
            $parsed['default'] = null;
            $parsed['warnings'][] = "Replaced zero-date default on [{$name}] with null.";
            $nullable = true;
        }

        $comment = $column['comment'] ?? null;

        return [
            'method' => $method,
            'parameters' => $parameters,
            'nullable' => $nullable,
            'default' => $parsed['default'],
            'has_default' => $parsed['has_default'] && ! $auto,
            'use_current' => $parsed['use_current'] && ! $auto,
            'unsigned' => $unsigned && ! $auto,
            'comment' => is_string($comment) && $comment !== '' ? $comment : null,
            'skipped' => false,
            'warnings' => array_merge($warnings, $parsed['warnings']),
        ];
    }

    /**
     * @return array{0: string, 1: list<mixed>, 2: bool, 3: list<string>}
     */
    private function resolve(string $name, string $raw, string $clean, string $typeName, bool $unsigned, bool $auto): array
    {
        if ($typeName === 'enum' || str_starts_with($clean, 'enum(')) {
            preg_match_all("/'((?:\\\\'|[^'])*)'/", $raw, $matches);
            $values = array_map(static fn (string $value): string => str_replace("\\'", "'", $value), $matches[1]);

            if ($values === []) {
                return ['string', [255], false, ["Enum column [{$name}] had no values and was stored as string."]];
            }

            return ['enum', [$values], false, []];
        }

        if ($typeName === 'set' || str_starts_with($clean, 'set(')) {
            return ['string', [255], false, ["SET column [{$name}] was stored as string."]];
        }

        if ($this->isBooleanType($clean, $typeName)) {
            return ['boolean', [], false, []];
        }

        if (str_ends_with($clean, '[]') || str_ends_with($typeName, '[]')) {
            return ['json', [], false, ["Array column [{$name}] was stored as json."]];
        }

        if ($auto && $this->isIntegerType($clean, $typeName)) {
            return [$this->incrementMethod($clean, $typeName), [], false, []];
        }

        if (preg_match('/^(?:varchar|character varying|nvarchar|national character varying)\((\d+)\)$/', $clean, $matches) === 1) {
            return ['string', [(int) $matches[1]], false, []];
        }

        if (in_array($typeName, ['varchar', 'character varying', 'nvarchar'], true) || $clean === 'varchar') {
            return ['string', [255], false, []];
        }

        if (preg_match('/^(?:char|character|bpchar|nchar)\((\d+)\)$/', $clean, $matches) === 1) {
            return ['char', [(int) $matches[1]], false, []];
        }

        if ($typeName === 'jsonb' || $clean === 'jsonb') {
            return ['jsonb', [], false, []];
        }

        if ($typeName === 'json' || $clean === 'json') {
            return ['json', [], false, []];
        }

        if ($typeName === 'uuid' || $clean === 'uuid') {
            return ['uuid', [], false, []];
        }

        if (preg_match('/^(?:decimal|numeric|fixed)\((\d+)\s*,\s*(\d+)\)$/', $clean, $matches) === 1) {
            return ['decimal', [(int) $matches[1], (int) $matches[2]], $unsigned, []];
        }

        if (in_array($typeName, ['decimal', 'numeric', 'fixed', 'money'], true)) {
            $parameters = $typeName === 'money' ? [19, 4] : [10, 2];

            return ['decimal', $parameters, $unsigned, []];
        }

        if ($this->isFloatType($clean, $typeName)) {
            $method = str_contains($clean, 'double') || $typeName === 'float8' ? 'double' : 'float';

            return [$method, [], false, []];
        }

        if ($this->isIntegerType($clean, $typeName)) {
            return [$this->integerMethod($clean, $typeName), [], $unsigned, []];
        }

        if (str_contains($clean, 'with time zone') || $typeName === 'timestamptz') {
            return ['timestampTz', [], false, []];
        }

        if (str_starts_with($typeName, 'timestamp') || str_starts_with($clean, 'timestamp')) {
            return ['timestamp', [], false, []];
        }

        if ($typeName === 'datetime' || str_starts_with($clean, 'datetime')) {
            return ['dateTime', [], false, []];
        }

        if ($typeName === 'date' || $clean === 'date') {
            return ['date', [], false, []];
        }

        if ($typeName === 'time' || str_starts_with($clean, 'time')) {
            return ['time', [], false, []];
        }

        if ($typeName === 'year' || str_starts_with($clean, 'year')) {
            return ['year', [], false, []];
        }

        if ($this->isBinaryType($clean, $typeName)) {
            return ['binary', [], false, []];
        }

        if (in_array($typeName, ['text', 'tinytext', 'mediumtext', 'longtext', 'ntext', 'clob'], true) || $clean === 'text') {
            return ['text', [], false, []];
        }

        if (preg_match('/^bit(?:\((\d+)\))?$/', $clean, $matches) === 1) {
            $length = isset($matches[1]) ? (int) $matches[1] : 1;

            return $length === 1
                ? ['boolean', [], false, []]
                : ['integer', [], false, []];
        }

        return ['string', [255], false, ["Column [{$name}] type [{$raw}] was stored as string(255)."]];
    }

    private function isBooleanType(string $clean, string $typeName): bool
    {
        return in_array($typeName, ['bool', 'boolean'], true)
            || $clean === 'bool'
            || $clean === 'boolean'
            || preg_match('/^tinyint\(1\)/', $clean) === 1;
    }

    private function isIntegerType(string $clean, string $typeName): bool
    {
        return preg_match('/^(?:tinyint|smallint|mediumint|bigint|integer|int|int2|int4|int8|serial|bigserial|smallserial)(?:\(\d+\))?$/', $clean) === 1
            || in_array($typeName, ['tinyint', 'smallint', 'mediumint', 'bigint', 'integer', 'int', 'int2', 'int4', 'int8', 'serial', 'bigserial', 'smallserial'], true);
    }

    private function isFloatType(string $clean, string $typeName): bool
    {
        return preg_match('/^(?:float|double precision|double|real)(?:\(\d+(?:\s*,\s*\d+)?\))?$/', $clean) === 1
            || in_array($typeName, ['float', 'float4', 'float8', 'double', 'real'], true);
    }

    private function isBinaryType(string $clean, string $typeName): bool
    {
        return preg_match('/^(?:blob|tinyblob|mediumblob|longblob|bytea|binary|varbinary)(?:\(\d+\))?$/', $clean) === 1
            || in_array($typeName, ['blob', 'tinyblob', 'mediumblob', 'longblob', 'bytea', 'binary', 'varbinary'], true);
    }

    private function incrementMethod(string $clean, string $typeName): string
    {
        return match (true) {
            $this->isBig($clean, $typeName) => 'bigIncrements',
            $this->isSmall($clean, $typeName) => 'smallIncrements',
            $this->isMedium($clean, $typeName) => 'mediumIncrements',
            $this->isTiny($clean, $typeName) => 'tinyIncrements',
            default => 'increments',
        };
    }

    private function integerMethod(string $clean, string $typeName): string
    {
        return match (true) {
            $this->isBig($clean, $typeName) => 'bigInteger',
            $this->isSmall($clean, $typeName) => 'smallInteger',
            $this->isMedium($clean, $typeName) => 'mediumInteger',
            $this->isTiny($clean, $typeName) => 'tinyInteger',
            default => 'integer',
        };
    }

    private function isBig(string $clean, string $typeName): bool
    {
        return str_contains($clean, 'bigint')
            || str_contains($typeName, 'bigint')
            || $typeName === 'int8'
            || $typeName === 'bigserial'
            || str_starts_with($clean, 'bigserial');
    }

    private function isSmall(string $clean, string $typeName): bool
    {
        return str_contains($clean, 'smallint')
            || $typeName === 'smallint'
            || $typeName === 'int2'
            || $typeName === 'smallserial';
    }

    private function isMedium(string $clean, string $typeName): bool
    {
        return str_contains($clean, 'mediumint') || $typeName === 'mediumint';
    }

    private function isTiny(string $clean, string $typeName): bool
    {
        return str_contains($clean, 'tinyint') || $typeName === 'tinyint';
    }

    /**
     * @return array{default: mixed, has_default: bool, use_current: bool, warnings: list<string>}
     */
    private function parseDefault(mixed $default, string $method): array
    {
        $empty = ['default' => null, 'has_default' => false, 'use_current' => false, 'warnings' => []];

        if ($default === null) {
            return $empty;
        }

        $value = trim((string) $default);

        if ($value === '' || strcasecmp($value, 'null') === 0) {
            return $empty;
        }

        $value = $this->stripCasts($value);

        if (str_starts_with($value, '(') && str_ends_with($value, ')')) {
            $value = trim(substr($value, 1, -1));
            $value = $this->stripCasts($value);
        }

        $bare = strtolower(trim($value, "'\""));

        if (in_array($bare, ['current_timestamp', 'current_timestamp()', 'now()', 'getdate()', 'localtimestamp', 'current_date'], true)) {
            return ['default' => null, 'has_default' => false, 'use_current' => true, 'warnings' => []];
        }

        if (str_contains($value, '(') || str_contains($value, ')')) {
            return [
                'default' => null,
                'has_default' => false,
                'use_current' => false,
                'warnings' => ["Skipped expression default [{$default}]."],
            ];
        }

        if ((str_starts_with($value, "'") && str_ends_with($value, "'")) || (str_starts_with($value, '"') && str_ends_with($value, '"'))) {
            $value = str_replace("''", "'", substr($value, 1, -1));
        }

        if ($method === 'boolean') {
            return [
                'default' => in_array(strtolower($value), ['1', 'true', 't', 'yes', 'on'], true),
                'has_default' => true,
                'use_current' => false,
                'warnings' => [],
            ];
        }

        if (is_numeric($value) && in_array($method, ['integer', 'bigInteger', 'smallInteger', 'mediumInteger', 'tinyInteger', 'year'], true)) {
            $value = str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return [
            'default' => $value,
            'has_default' => true,
            'use_current' => false,
            'warnings' => [],
        ];
    }

    private function isTemporal(string $method): bool
    {
        return in_array($method, ['date', 'dateTime', 'dateTimeTz', 'timestamp', 'timestampTz', 'time', 'timeTz'], true);
    }

    private function isZeroDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^0000-00-00(?:\s+00:00:00(?:\.\d+)?)?$/', $value) === 1;
    }

    private function stripCasts(string $value): string
    {
        while (preg_match('/::[A-Za-z0-9_." ]+$/', $value) === 1) {
            $value = (string) preg_replace('/::[A-Za-z0-9_." ]+$/', '', $value);
            $value = trim($value);
        }

        return $value;
    }

    /**
     * @param  list<string>  $warnings
     * @return array{
     *     method: string,
     *     parameters: list<mixed>,
     *     nullable: bool,
     *     default: mixed,
     *     has_default: bool,
     *     use_current: bool,
     *     unsigned: bool,
     *     comment: string|null,
     *     skipped: bool,
     *     warnings: list<string>
     * }
     */
    private function skipped(array $warnings): array
    {
        return [
            'method' => 'string',
            'parameters' => [],
            'nullable' => true,
            'default' => null,
            'has_default' => false,
            'use_current' => false,
            'unsigned' => false,
            'comment' => null,
            'skipped' => true,
            'warnings' => $warnings,
        ];
    }
}
