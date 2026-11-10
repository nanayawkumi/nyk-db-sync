<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Analysis;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Nanayawkumi\NykDbSync\DualWrite;
use Nanayawkumi\NykDbSync\Schema\ColumnTypeMapper;
use Nanayawkumi\NykDbSync\Schema\TableCatalog;
use Nanayawkumi\NykDbSync\Sync\UpsertKey;
use Nanayawkumi\NykDbSync\TableName;
use RuntimeException;
use Throwable;

/**
 * Simulates the conversion of every table, without writing anything, and
 * reports what would fail or lose data and how to fix it.
 */
final class DatabaseAnalyzer
{
    public const READY = 'ready';

    public const ATTENTION = 'attention';

    public const BLOCKED = 'blocked';

    /**
     * Bump when the rules change, so older stored analyses are run again.
     */
    private const RULES_VERSION = 1;

    public function __construct(
        private DualWrite $dualWrite,
        private TableCatalog $catalog,
        private ColumnTypeMapper $mapper,
        private ColumnRules $rules,
        private ProbeRunner $probes,
        private UpsertKey $upsertKey,
        private AnalysisStore $store,
        private DecisionStore $decisions,
    ) {}

    /**
     * Analyze tables now and store the result.
     *
     * @param  list<string>|null  $tables  defaults to every syncable table
     * @param  callable(string): void|null  $onTable
     * @return array<string, array<string, mixed>> entries by table, with decisions applied
     */
    public function analyze(?array $tables = null, int $sample = 0, ?callable $onTable = null): array
    {
        [$primary, $secondary] = $this->connections();
        $tables ??= $this->catalog->ordered($primary);
        $entries = [];

        foreach ($tables as $table) {
            if (! TableName::isSafe($table) || ! Schema::connection($primary)->hasTable($table)) {
                continue;
            }

            if ($onTable !== null) {
                $onTable($table);
            }

            $entries[$table] = $this->analyzeTable($primary, $secondary, $table, $sample);
        }

        $this->store->putTables($primary, $secondary, $entries);
        $this->pruneDecisions();

        return $this->resolve($entries);
    }

    /**
     * The stored analysis with the user's decisions applied.
     *
     * @return array<string, array<string, mixed>>
     */
    public function stored(): array
    {
        $report = $this->store->get();

        if ($report === null) {
            return [];
        }

        [$primary, $secondary] = $this->connections(false);

        if (($report['primary'] ?? null) !== $primary || ($report['secondary'] ?? null) !== $secondary) {
            return [];
        }

        return $this->resolve($report['tables']);
    }

    /**
     * Tables that cannot be synced yet: unresolved blocking issues, found by
     * reusing a fresh stored analysis or analyzing the table now.
     *
     * @param  list<string>  $tables
     * @return array<string, list<array<string, mixed>>>
     */
    public function blocked(array $tables, bool $schemaOnly = false): array
    {
        [$primary, $secondary] = $this->connections();
        $stored = $this->store->get();
        $reusable = $stored !== null && ($stored['primary'] ?? null) === $primary && ($stored['secondary'] ?? null) === $secondary
            ? $stored['tables']
            : [];
        $maxAge = (int) config('nyk-db-sync.analysis.max_age_minutes', 1440);
        $stale = [];

        foreach ($tables as $table) {
            $entry = $reusable[$table] ?? null;

            if ($entry === null || ! $this->isFresh($entry, $primary, $secondary, $table, $maxAge)) {
                $stale[] = $table;
            }
        }

        $entries = array_intersect_key($reusable, array_flip($tables));

        if ($stale !== [] && $schemaOnly) {
            // Only the schema is being built, so skip the data scan. The partial result is not stored,
            // which keeps a later full sync from trusting it.
            foreach ($stale as $table) {
                if (TableName::isSafe($table) && Schema::connection($primary)->hasTable($table)) {
                    $entries[$table] = $this->analyzeTable($primary, $secondary, $table, 0, false);
                }
            }
        } elseif ($stale !== []) {
            $entries = array_merge($entries, $this->analyze($stale, (int) config('nyk-db-sync.analysis.sample', 0)));
        }

        $blocked = [];

        foreach ($this->resolve($entries) as $table => $entry) {
            $issues = array_values(array_filter(
                $entry['issues'],
                fn (array $issue): bool => $issue['blocking'] && (! $schemaOnly || $issue['phase'] === 'schema'),
            ));

            if ($issues !== []) {
                $blocked[$table] = $issues;
            }
        }

        return $blocked;
    }

    /**
     * Mark each issue with the user's decision and work out table status.
     *
     * @param  array<string, array<string, mixed>>  $entries
     * @return array<string, array<string, mixed>>
     */
    public function resolve(array $entries): array
    {
        $decisions = $this->decisions->all();

        foreach ($entries as $table => $entry) {
            $unresolved = 0;
            $accepted = 0;

            foreach ($entry['issues'] as $index => $issue) {
                $decision = $decisions[$issue['id']] ?? null;
                $mode = is_array($decision) ? ($decision['mode'] ?? null) : null;

                // A skip accepted a specific finding. If the data got worse (or changed), ask again.
                if ($mode === DecisionStore::SKIP && is_string($decision['evidence'] ?? null)
                    && $decision['evidence'] !== md5((string) $issue['message'])) {
                    $mode = null;
                }

                $blocking = $issue['severity'] === ColumnRules::BLOCKER && ! in_array($mode, [DecisionStore::AUTO, DecisionStore::SKIP], true);

                // The decision was only recorded because a fix existed. If the issue
                // now has none, the sync already built the column with that fix and
                // the destination column is the one the rules no longer offer to change.
                $applied = $mode === DecisionStore::AUTO && $issue['fix'] === null && is_array($decision['fix'] ?? null);

                if ($mode === DecisionStore::AUTO && $issue['fix'] === null && ! $applied) {
                    $mode = null;
                    $blocking = $issue['severity'] === ColumnRules::BLOCKER;
                }

                $entry['issues'][$index]['decision'] = $mode;
                $entry['issues'][$index]['blocking'] = $blocking;

                if ($blocking) {
                    $unresolved++;
                } elseif ($issue['severity'] !== ColumnRules::INFO) {
                    $accepted++;
                }
            }

            $entry['unresolved'] = $unresolved;
            $entry['status'] = $unresolved > 0 ? self::BLOCKED : ($accepted > 0 ? self::ATTENTION : self::READY);
            $entries[$table] = $entry;
        }

        return $entries;
    }

    /**
     * Record a decision for an issue found in the stored analysis.
     */
    public function decide(string $issueId, string $mode): void
    {
        foreach ($this->stored() as $entry) {
            foreach ($entry['issues'] as $issue) {
                if ($issue['id'] !== $issueId) {
                    continue;
                }

                $this->decisions->record($issueId, $mode, $issue['table'], $issue['column'], $issue['fix'], (string) $issue['message']);

                return;
            }
        }

        throw new RuntimeException("No analysis issue [{$issueId}]. Run the analysis again.");
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function connections(bool $strict = true): array
    {
        $primary = $this->dualWrite->primaryConnection();
        $secondary = $this->dualWrite->secondaryConnection();

        if (($secondary === null || $secondary === '') && $strict) {
            throw new RuntimeException('No secondary database connection is configured.');
        }

        return [$primary, (string) $secondary];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isFresh(array $entry, string $primary, string $secondary, string $table, int $maxAgeMinutes): bool
    {
        $analyzedAt = strtotime((string) ($entry['analyzed_at'] ?? ''));

        if ($analyzedAt === false || ($maxAgeMinutes > 0 && time() - $analyzedAt > $maxAgeMinutes * 60)) {
            return false;
        }

        return ($entry['fingerprint'] ?? null) === $this->fingerprint($primary, $secondary, $table);
    }

    private function fingerprint(string $primary, string $secondary, string $table): string
    {
        $parts = [
            self::RULES_VERSION,
            Schema::connection($primary)->getColumns($table),
            Schema::connection($primary)->getIndexes($table),
            Schema::connection($primary)->getForeignKeys($table),
            Schema::connection($secondary)->hasTable($table) ? Schema::connection($secondary)->getColumns($table) : null,
        ];

        return md5(json_encode($parts) ?: '');
    }

    private function pruneDecisions(): void
    {
        $report = $this->store->get();
        $known = [];

        foreach ($report['tables'] ?? [] as $entry) {
            foreach ($entry['issues'] as $issue) {
                $known[$issue['id']] = true;
            }
        }

        foreach ($this->decisions->all() as $id => $decision) {
            $table = (string) ($decision['table'] ?? '');

            if (! isset($known[$id]) && isset($report['tables'][$table])) {
                $this->decisions->forget($id);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeTable(string $primary, string $secondary, string $table, int $sample, bool $scanData = true): array
    {
        $source = DB::connection($primary);
        $destination = DB::connection($secondary);
        $context = ['source_driver' => $source->getDriverName(), 'dest_driver' => $destination->getDriverName()];
        $columns = Schema::connection($primary)->getColumns($table);
        $indexes = Schema::connection($primary)->getIndexes($table);
        $foreignKeys = Schema::connection($primary)->getForeignKeys($table);
        $destinationExists = Schema::connection($secondary)->hasTable($table);
        $destinationColumns = [];
        $destinationIndexes = [];

        if ($destinationExists) {
            foreach (Schema::connection($secondary)->getColumns($table) as $existing) {
                $destinationColumns[(string) $existing['name']] = $existing;
            }

            $destinationIndexes = Schema::connection($secondary)->getIndexes($table);
        }

        $issues = $this->identifierIssues($table, $columns, $context['dest_driver']);
        $plan = [];
        $probes = [];

        foreach ($columns as $column) {
            $name = (string) $column['name'];
            $mapped = $this->mapper->map($column);
            $columnContext = $context + ['dest_column' => $destinationColumns[$name] ?? null];
            $plan[$name] = [$column, $mapped, $columnContext];

            foreach ($this->rules->probes($table, $column, $mapped, $columnContext) as $probe) {
                $probes[] = $probe + ['column' => $name];
            }

            if ($columnContext['dest_column'] !== null && ! $mapped['skipped']) {
                $mismatch = $this->familyMismatch($table, $name, $mapped['method'], $columnContext['dest_column']);

                if ($mismatch !== null) {
                    $issues[] = $mismatch;
                }
            }
        }

        $answers = [];

        try {
            $answers = $scanData ? $this->probes->run($primary, $table, $probes, $sample) : [];
        } catch (Throwable $exception) {
            Log::warning('[nyk-db-sync] Analysis could not scan a table', ['table' => $table, 'error' => $exception->getMessage()]);
            $issues[] = $this->rules->issue(
                $table,
                null,
                'scan_failed',
                ColumnRules::WARNING,
                'data',
                'The data could not be scanned: '.$exception->getMessage(),
                'Fix the error and run the analysis again. Only the column definitions were checked.',
                null,
            );
        }

        foreach ($plan as [$column, $mapped, $columnContext]) {
            array_push($issues, ...$this->rules->issues($table, $column, $mapped, $columnContext, $answers));
        }

        $rows = $this->countRows($primary, $table);
        array_push($issues, ...$this->keyIssues($table, $indexes, $destinationExists ? $destinationIndexes : null, $primary, $context['dest_driver'], $rows));

        if ($scanData) {
            array_push($issues, ...$this->foreignKeyIssues($primary, $table, $foreignKeys, $sample));
        }

        if ($scanData && $context['source_driver'] === 'pgsql' && in_array($context['dest_driver'], ['mysql', 'mariadb'], true)) {
            array_push($issues, ...$this->caseCollisionIssues($primary, $table, $columns, $indexes));
        }

        return [
            'table' => $table,
            'analyzed_at' => now()->toDateTimeString(),
            'fingerprint' => $this->fingerprint($primary, $secondary, $table),
            'rows' => $rows,
            'sampled' => $sample > 0,
            'destination_exists' => $destinationExists,
            'issues' => $issues,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return list<array<string, mixed>>
     */
    private function identifierIssues(string $table, array $columns, string $driver): array
    {
        $limit = $driver === 'pgsql' ? 63 : ($driver === 'sqlite' ? PHP_INT_MAX : 64);
        $issues = [];

        if (strlen($table) > $limit) {
            $issues[] = $this->rules->issue($table, null, 'table_name_too_long', ColumnRules::BLOCKER, 'schema', "The table name is longer than the {$limit} characters {$driver} allows.", 'Rename the table on the source.', null);
        }

        foreach ($columns as $column) {
            if (strlen((string) $column['name']) > $limit) {
                $issues[] = $this->rules->issue($table, (string) $column['name'], 'column_name_too_long', ColumnRules::BLOCKER, 'schema', "The column name is longer than the {$limit} characters {$driver} allows.", 'Rename the column on the source.', null);
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>|null
     */
    private function familyMismatch(string $table, string $name, string $method, array $existing): ?array
    {
        $want = $this->family($method);
        $have = $this->family(strtolower((string) ($existing['type_name'] ?? '')));

        if ($want === null || $have === null || $want === $have) {
            return null;
        }

        return $this->rules->issue(
            $table,
            $name,
            'type_mismatch',
            ColumnRules::WARNING,
            'schema',
            "The destination column is {$existing['type']} but the source converts to {$want}. Existing columns are not changed.",
            'Alter the destination column by hand, or drop the destination table and let the sync recreate it.',
            null,
        );
    }

    private function family(string $type): ?string
    {
        return match (true) {
            str_contains($type, 'int'), str_contains($type, 'serial'), in_array($type, ['increments', 'year'], true) => 'integer',
            in_array($type, ['decimal', 'numeric', 'float', 'double', 'real', 'float4', 'float8', 'double precision'], true) => 'number',
            in_array($type, ['string', 'char', 'text', 'enum', 'varchar', 'bpchar', 'longtext', 'mediumtext', 'tinytext', 'uuid'], true) => 'text',
            in_array($type, ['boolean', 'bool'], true) => 'boolean',
            in_array($type, ['json', 'jsonb'], true) => 'json',
            str_contains($type, 'date'), str_contains($type, 'time') => 'temporal',
            default => null,
        };
    }

    /**
     * The copy upserts on a key. Postgres and SQLite refuse an upsert whose
     * key has no matching unique index; MySQL does not, but then a second
     * sync adds the rows again instead of updating them.
     *
     * @param  list<array<string, mixed>>  $indexes
     * @param  list<array<string, mixed>>|null  $destinationIndexes
     * @return list<array<string, mixed>>
     */
    private function keyIssues(string $table, array $indexes, ?array $destinationIndexes, string $primary, string $destinationDriver, int $rows): array
    {
        $key = $this->upsertKey->columns($primary, $table);

        if ($this->upsertKey->isBackedByIndex($destinationIndexes ?? $indexes, $key)) {
            return [];
        }

        $strict = in_array($destinationDriver, ['pgsql', 'sqlite'], true);
        $severity = $strict && $rows > 0 ? ColumnRules::BLOCKER : ColumnRules::WARNING;
        $columns = implode(', ', $key);
        $where = $destinationIndexes === null ? 'source' : 'destination';
        $consequence = match (true) {
            ! $strict => 'Rows will copy, but running the sync again adds them a second time.',
            $rows > 0 => 'The copy will fail.',
            default => 'The table is empty now, but copying or replicating a row later will fail.',
        };

        return [$this->rules->issue(
            $table,
            null,
            'no_upsert_key',
            $severity,
            'data',
            "Rows are copied with an upsert on ({$columns}), but no primary or unique index on the {$where} covers those columns. {$consequence}",
            $destinationIndexes === null
                ? 'Add a primary key to the table on the source.'
                : "Add a unique index on ({$columns}) to the destination table, or a primary key on the source.",
            null,
        )];
    }

    /**
     * @param  list<array<string, mixed>>  $foreignKeys
     * @return list<array<string, mixed>>
     */
    private function foreignKeyIssues(string $connection, string $table, array $foreignKeys, int $sample): array
    {
        $db = DB::connection($connection);
        $grammar = $db->getQueryGrammar();
        $issues = [];
        $seen = [];

        foreach ($foreignKeys as $foreignKey) {
            $columns = array_values($foreignKey['columns'] ?? []);
            $references = array_values($foreignKey['foreign_columns'] ?? []);
            $parent = TableName::bare((string) ($foreignKey['foreign_table'] ?? ''));
            $signature = implode(',', $columns).'>'.$parent;

            if ($columns === [] || count($columns) !== count($references) || ! TableName::isSafe($parent) || isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $conditions = [];

            foreach ($columns as $i => $column) {
                $conditions[] = 'c.'.$grammar->wrap($column).' = p.'.$grammar->wrap($references[$i]);
            }

            $child = $sample > 0 ? '(select * from '.$grammar->wrapTable($table).' limit '.$sample.')' : $grammar->wrapTable($table);
            $sql = 'select count(*) as orphans from '.$child.' c left join '.$grammar->wrapTable($parent).' p on '.implode(' and ', $conditions)
                .' where p.'.$grammar->wrap($references[0]).' is null and c.'.$grammar->wrap($columns[0]).' is not null';

            try {
                $orphans = (int) ($db->selectOne($sql)->orphans ?? 0);
            } catch (Throwable) {
                continue;
            }

            if ($orphans > 0) {
                $issues[] = $this->rules->issue(
                    $table,
                    implode(',', $columns),
                    'orphan_rows',
                    ColumnRules::WARNING,
                    'data',
                    "{$orphans} rows point at a missing row in [{$parent}].",
                    'The foreign key is added as NOT VALID on Postgres so the copy still succeeds. Clean the orphan rows on the source if you want it enforced.',
                    null,
                );
            }
        }

        return $issues;
    }

    /**
     * Postgres compares strings exactly; MySQL's default collation does not.
     * Values that differ only by case collide in a unique index there.
     *
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $indexes
     * @return list<array<string, mixed>>
     */
    private function caseCollisionIssues(string $connection, string $table, array $columns, array $indexes): array
    {
        $db = DB::connection($connection);
        $grammar = $db->getQueryGrammar();
        $textual = [];

        foreach ($columns as $column) {
            if (in_array($this->mapper->map($column)['method'], ['string', 'char', 'text'], true)) {
                $textual[(string) $column['name']] = true;
            }
        }

        $issues = [];

        foreach ($indexes as $index) {
            $names = array_values($index['columns'] ?? []);

            if ((! ($index['unique'] ?? false) && ! ($index['primary'] ?? false)) || count($names) !== 1 || ! isset($textual[$names[0]])) {
                continue;
            }

            $column = $grammar->wrap($names[0]);

            try {
                $row = $db->selectOne("select count(*) - count(distinct lower({$column})) as collisions from ".$grammar->wrapTable($table));
            } catch (Throwable) {
                continue;
            }

            if ((int) ($row->collisions ?? 0) > 0) {
                $issues[] = $this->rules->issue(
                    $table,
                    $names[0],
                    'case_collision',
                    ColumnRules::BLOCKER,
                    'data',
                    (int) $row->collisions.' values differ only by letter case, which a unique index on MySQL treats as duplicates.',
                    'Make the values distinct on the source, or give the destination column a case-sensitive collation (for example utf8mb4_bin).',
                    null,
                );
            }
        }

        return $issues;
    }

    private function countRows(string $connection, string $table): int
    {
        try {
            return (int) DB::connection($connection)->table($table)->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
