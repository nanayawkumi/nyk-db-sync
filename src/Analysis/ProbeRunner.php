<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Analysis;

use Illuminate\Support\Facades\DB;

/**
 * Asks the source database every column question in one aggregate query, so
 * a table is read once and no rows are pulled into PHP.
 */
final class ProbeRunner
{
    /**
     * @param  list<array{column: string, kind: string, args: array<string, mixed>}>  $probes
     * @return array<string, mixed> answers keyed by {@see ColumnRules::probeKey()}
     */
    public function run(string $connection, string $table, array $probes, int $sample = 0): array
    {
        if ($probes === []) {
            return [];
        }

        $db = DB::connection($connection);
        $driver = $db->getDriverName();
        $selects = [];
        $bindings = [];
        $slots = [];

        foreach ($probes as $probe) {
            $key = ColumnRules::probeKey($probe['column'], $probe['kind'], $probe['args']);
            $column = $db->getQueryGrammar()->wrap($probe['column']);

            if ($probe['kind'] === 'range') {
                $selects[] = "MIN({$column}) as p".count($slots).'_min';
                $selects[] = "MAX({$column}) as p".count($slots).'_max';
                $slots[] = [$key, true];

                continue;
            }

            $expression = $this->expression($probe['kind'], $column, $probe['args'], $driver);

            if ($expression === null) {
                continue;
            }

            $selects[] = $expression['sql'].' as p'.count($slots);
            array_push($bindings, ...$expression['bindings']);
            $slots[] = [$key, false];
        }

        if ($selects === []) {
            return [];
        }

        $query = $sample > 0
            ? $db->query()->fromSub($db->table($table)->limit($sample), 'sample')
            : $db->table($table);

        $row = (array) $query->selectRaw(implode(', ', $selects), $bindings)->first();
        $answers = [];

        foreach ($slots as $index => [$key, $isRange]) {
            $answers[$key] = $isRange
                ? ['min' => $this->integer($row["p{$index}_min"] ?? null), 'max' => $this->integer($row["p{$index}_max"] ?? null)]
                : ($row["p{$index}"] ?? null);
        }

        return $answers;
    }

    private function integer(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{sql: string, bindings: list<mixed>}|null
     */
    private function expression(string $kind, string $column, array $args, string $driver): ?array
    {
        $sum = static fn (string $condition, array $bindings = []): array => [
            'sql' => "COALESCE(SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END), 0)",
            'bindings' => $bindings,
        ];

        return match ($kind) {
            'max_length' => [
                'sql' => 'MAX('.($driver === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH')."({$column}))",
                'bindings' => [],
            ],
            'zero_dates' => match ($driver) {
                'mysql', 'mariadb' => $sum("CAST({$column} AS CHAR) LIKE '0000-00-00%'"),
                'sqlite' => $sum("CAST({$column} AS TEXT) LIKE '0000-00-00%'"),
                default => null,
            },
            'nul_bytes' => in_array($driver, ['mysql', 'mariadb'], true)
                ? $sum("LOCATE(CHAR(0), {$column}) > 0")
                : null,
            'invalid_json' => match ($driver) {
                'mysql', 'mariadb' => $sum("{$column} IS NOT NULL AND JSON_VALID({$column}) = 0"),
                'sqlite' => $sum("{$column} IS NOT NULL AND json_valid({$column}) = 0"),
                default => null,
            },
            'invalid_enum' => $args['values'] === []
                ? null
                : $sum(
                    "{$column} IS NOT NULL AND {$column} NOT IN (".implode(', ', array_fill(0, count($args['values']), '?')).')',
                    array_values($args['values']),
                ),
            'non_binary_bool' => $sum("{$column} IS NOT NULL AND {$column} NOT IN (0, 1)"),
            default => null,
        };
    }
}
