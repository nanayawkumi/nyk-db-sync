<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Console;

use Illuminate\Console\Command;
use Nanayawkumi\NykDbSync\Analysis\DatabaseAnalyzer;
use Nanayawkumi\NykDbSync\Analysis\DecisionStore;
use Nanayawkumi\NykDbSync\DualWrite;
use Nanayawkumi\NykDbSync\Schema\TableCatalog;
use Throwable;

class AnalyzeDatabaseCommand extends Command
{
    protected $signature = 'nyk-db-sync:analyze
        {--table= : Analyze a single table}
        {--sample=0 : Scan only the first N rows of each table (0 scans every row)}
        {--fix= : Answer every open issue without asking: auto (apply the package fix where one exists), manual, or skip (accept the risk)}
        {--report : Print the analysis as JSON and do not ask anything}';

    protected $description = 'Simulate the conversion, report tables that would fail or lose data, and choose how to fix them';

    public function handle(DatabaseAnalyzer $analyzer, TableCatalog $catalog, DualWrite $dualWrite): int
    {
        $fix = $this->option('fix');

        if ($fix !== null && ! in_array($fix, [DecisionStore::AUTO, DecisionStore::MANUAL, DecisionStore::SKIP], true)) {
            $this->error('--fix must be auto, manual, or skip.');

            return self::FAILURE;
        }

        $table = $this->option('table');
        $table = is_string($table) && $table !== '' ? $table : null;
        $report = (bool) $this->option('report');

        try {
            $tables = null;

            if ($table !== null) {
                if (! $catalog->isSyncable($dualWrite->primaryConnection(), $table)) {
                    $this->error("Table [{$table}] is not available to analyze.");

                    return self::FAILURE;
                }

                $tables = [$table];
            }

            $entries = $analyzer->analyze(
                $tables,
                max(0, (int) $this->option('sample')),
                $report ? null : fn (string $name) => $this->line("Analyzing {$name}...", null, 'vvv'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $report) {
            if ($fix !== null) {
                $this->answerAll($analyzer, $entries, (string) $fix);
            } elseif ($this->input->isInteractive()) {
                $this->askAboutEach($analyzer, $entries);
            }

        }

        $entries = $analyzer->resolve($entries);

        if ($report) {
            $this->line((string) json_encode(['tables' => array_values($entries)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($entries);
        }

        $blocked = array_filter($entries, static fn (array $entry): bool => $entry['status'] === DatabaseAnalyzer::BLOCKED);

        return $blocked === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, array<string, mixed>>  $entries
     */
    private function render(array $entries): void
    {
        if ($entries === []) {
            $this->info('No tables to analyze.');

            return;
        }

        $this->table(
            ['Table', 'Rows', 'Status', 'Blocking', 'Warnings'],
            array_map(static function (array $entry): array {
                $blockers = count(array_filter($entry['issues'], static fn (array $i): bool => $i['severity'] === 'blocker'));
                $warnings = count(array_filter($entry['issues'], static fn (array $i): bool => $i['severity'] === 'warning'));

                return [
                    $entry['table'],
                    number_format($entry['rows']).($entry['sampled'] ? ' (sampled)' : ''),
                    match ($entry['status']) {
                        DatabaseAnalyzer::BLOCKED => 'BLOCKED',
                        DatabaseAnalyzer::ATTENTION => 'ok, with notes',
                        default => 'ready',
                    },
                    $entry['unresolved'].' of '.$blockers,
                    $warnings,
                ];
            }, array_values($entries)),
        );

        foreach ($entries as $entry) {
            foreach ($entry['issues'] as $issue) {
                if ($issue['severity'] === 'info') {
                    continue;
                }

                $label = strtoupper($issue['severity']).($issue['decision'] !== null ? ' ['.$issue['decision'].']' : '');
                $where = $issue['column'] !== null ? "{$issue['table']}.{$issue['column']}" : $issue['table'];
                $line = "{$label} {$where}: {$issue['message']}";

                $issue['severity'] === 'blocker' && $issue['blocking'] ? $this->error($line) : $this->warn($line);

                if ($issue['blocking'] && $issue['recommendation'] !== null) {
                    $this->line('  Fix: '.$issue['recommendation']);
                }
            }
        }

        $blocked = array_keys(array_filter($entries, static fn (array $entry): bool => $entry['status'] === DatabaseAnalyzer::BLOCKED));

        if ($blocked === []) {
            $this->info('Every analyzed table can be converted and copied.');

            return;
        }

        $this->error('Blocked: '.implode(', ', $blocked).'. Fix them, choose an automatic fix, or accept the risk, then analyze again.');
    }

    /**
     * @param  array<string, array<string, mixed>>  $entries
     */
    private function askAboutEach(DatabaseAnalyzer $analyzer, array $entries): void
    {
        foreach ($analyzer->resolve($entries) as $entry) {
            foreach ($entry['issues'] as $issue) {
                if ($issue['severity'] !== 'blocker' || $issue['decision'] !== null) {
                    continue;
                }

                $where = $issue['column'] !== null ? "{$issue['table']}.{$issue['column']}" : $issue['table'];
                $this->newLine();
                $this->error("{$where}: {$issue['message']}");

                $choices = [];

                if ($issue['fix'] !== null) {
                    $choices[DecisionStore::AUTO] = 'Fix it for me: '.$issue['fix']['label'];
                }

                $choices[DecisionStore::MANUAL] = "I'll fix it myself".($issue['recommendation'] !== null ? ' ('.$issue['recommendation'].')' : '');
                $choices[DecisionStore::SKIP] = 'Accept the risk and continue anyway';

                $answer = $this->choice('How should this be handled?', array_values($choices), 0);
                $mode = (string) array_search($answer, $choices, true);

                $analyzer->decide($issue['id'], $mode);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $entries
     */
    private function answerAll(DatabaseAnalyzer $analyzer, array $entries, string $mode): void
    {
        foreach ($analyzer->resolve($entries) as $entry) {
            foreach ($entry['issues'] as $issue) {
                if ($issue['severity'] !== 'blocker' || $issue['decision'] !== null) {
                    continue;
                }

                if ($mode !== DecisionStore::AUTO || $issue['fix'] !== null) {
                    $analyzer->decide($issue['id'], $mode);
                }
            }
        }
    }
}
