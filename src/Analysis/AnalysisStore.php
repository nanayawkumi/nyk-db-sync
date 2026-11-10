<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Analysis;

use Nanayawkumi\NykDbSync\Support\JsonFile;

/**
 * The raw result of the last analysis of each table. Decisions are kept
 * apart in {@see DecisionStore} and merged in when the report is read.
 */
final class AnalysisStore
{
    public function path(): string
    {
        return storage_path('app/nyk-db-sync/analysis.json');
    }

    /**
     * @return array{primary: string, secondary: string, tables: array<string, array<string, mixed>>}|null
     */
    public function get(): ?array
    {
        $report = JsonFile::read($this->path());

        return is_array($report) && is_array($report['tables'] ?? null) ? $report : null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $entries
     */
    public function putTables(string $primary, string $secondary, array $entries): void
    {
        $report = $this->get();

        if ($report === null || ($report['primary'] ?? null) !== $primary || ($report['secondary'] ?? null) !== $secondary) {
            $report = ['primary' => $primary, 'secondary' => $secondary, 'tables' => []];
        }

        foreach ($entries as $table => $entry) {
            $report['tables'][$table] = $entry;
        }

        $report['generated_at'] = now()->toDateTimeString();

        JsonFile::write($this->path(), $report);
    }
}
