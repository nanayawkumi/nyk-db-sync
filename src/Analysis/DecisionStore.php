<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Analysis;

use Nanayawkumi\NykDbSync\Support\JsonFile;

/**
 * The user's answer to each analysis issue: let the package fix it ("auto"),
 * fix it by hand ("manual"), or accept the risk ("skip").
 */
final class DecisionStore
{
    public const AUTO = 'auto';

    public const MANUAL = 'manual';

    public const SKIP = 'skip';

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $memo = null;

    private int $memoStamp = -1;

    private int $version = 0;

    public function path(): string
    {
        return storage_path('app/nyk-db-sync/decisions.json');
    }

    /**
     * @return array<string, array{mode: string, table: string, column: string|null, fix: array<string, mixed>|null, decided_at: string}>
     */
    public function all(): array
    {
        $stamp = is_file($this->path()) ? (int) filemtime($this->path()) * 1000 + (int) filesize($this->path()) : 0;

        if ($this->memo !== null && $stamp === $this->memoStamp) {
            return $this->memo;
        }

        $this->memoStamp = $stamp;

        return $this->memo = JsonFile::read($this->path()) ?? [];
    }

    /**
     * @param  array<string, mixed>|null  $fix
     */
    public function record(string $issueId, string $mode, string $table, ?string $column, ?array $fix, ?string $message = null): void
    {
        if (! in_array($mode, [self::AUTO, self::MANUAL, self::SKIP], true)) {
            throw new \InvalidArgumentException("Unknown decision [{$mode}].");
        }

        if ($mode === self::AUTO && $fix === null) {
            throw new \InvalidArgumentException("Issue [{$issueId}] has no automatic fix.");
        }

        $all = $this->all();
        $all[$issueId] = [
            'mode' => $mode,
            'table' => $table,
            'column' => $column,
            'fix' => $mode === self::AUTO ? $fix : null,
            // What the user accepted. A skip stops applying once the finding changes.
            'evidence' => $message !== null ? md5($message) : null,
            'decided_at' => now()->toDateTimeString(),
        ];

        $this->save($all);
    }

    public function forget(string $issueId): void
    {
        $all = $this->all();

        if (isset($all[$issueId])) {
            unset($all[$issueId]);
            $this->save($all);
        }
    }

    public function clear(): void
    {
        $this->save([]);
    }

    /**
     * Automatic fixes the schema builder applies to a column.
     *
     * @return list<array<string, mixed>>
     */
    public function fixesFor(string $table, string $column, string ...$kinds): array
    {
        $fixes = [];

        foreach ($this->all() as $decision) {
            $fix = $decision['fix'] ?? null;

            if (($decision['mode'] ?? null) !== self::AUTO || ! is_array($fix)) {
                continue;
            }

            if (($decision['table'] ?? null) !== $table || ($decision['column'] ?? null) !== $column) {
                continue;
            }

            if ($kinds === [] || in_array($fix['kind'] ?? '', $kinds, true)) {
                $fixes[] = $fix;
            }
        }

        return $fixes;
    }

    /**
     * Value transforms for a table, by column.
     *
     * @return array<string, list<string>>
     */
    public function valueFixesFor(string $table): array
    {
        $fixes = [];

        foreach ($this->all() as $decision) {
            $fix = $decision['fix'] ?? null;

            if (($decision['mode'] ?? null) !== self::AUTO || ! is_array($fix) || ($fix['kind'] ?? null) !== 'value') {
                continue;
            }

            if (($decision['table'] ?? null) === $table && is_string($decision['column'] ?? null)) {
                $fixes[$decision['column']][] = (string) $fix['transform'];
            }
        }

        return $fixes;
    }

    /**
     * @return list<array{column: string, fix: array<string, mixed>}>
     */
    public function schemaFixesFor(string $table): array
    {
        $fixes = [];

        foreach ($this->all() as $decision) {
            $fix = $decision['fix'] ?? null;

            if (($decision['mode'] ?? null) === self::AUTO && is_array($fix) && ($decision['table'] ?? null) === $table
                && in_array($fix['kind'] ?? '', ['column_type', 'nullable'], true) && is_string($decision['column'] ?? null)) {
                $fixes[] = ['column' => $decision['column'], 'fix' => $fix];
            }
        }

        return $fixes;
    }

    /**
     * @param  array<string, array<string, mixed>>  $all
     */
    private function save(array $all): void
    {
        JsonFile::write($this->path(), $all);
        $this->memo = null;
        $this->memoStamp = -1;
        $this->version++;
    }

    /**
     * Changes whenever this process saves a decision, so callers can cache what they derive.
     */
    public function version(): int
    {
        return $this->version;
    }
}
