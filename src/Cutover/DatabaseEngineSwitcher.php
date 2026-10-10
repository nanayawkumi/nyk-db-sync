<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Cutover;

use Illuminate\Support\Facades\Artisan;
use Nanayawkumi\NykDbSync\DualWrite;
use Nanayawkumi\NykDbSync\Support\DotenvEditor;
use Nanayawkumi\NykDbSync\Sync\SecondaryDatabaseSyncService;

final class DatabaseEngineSwitcher
{
    /**
     * @var list<string>
     */
    public const ENGINES = ['mysql', 'pgsql'];

    public function __construct(
        protected DualWrite $dualWrite,
        protected SecondaryDatabaseSyncService $sync,
        protected DotenvEditor $dotenv,
    ) {}

    public function current(): string
    {
        return $this->dualWrite->primaryConnection();
    }

    /**
     * @return list<string>
     */
    public function engines(): array
    {
        $configured = config('nyk-db-sync.engines', self::ENGINES);

        if (! is_array($configured) || $configured === []) {
            return self::ENGINES;
        }

        return array_values(array_map('strval', $configured));
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function switchTo(string $engine, bool $keepDualWrite = true): array
    {
        $engine = strtolower($engine);

        if (! in_array($engine, $this->engines(), true)) {
            return ['ok' => false, 'message' => 'Engine must be '.implode(' or ', $this->engines()).'.'];
        }

        if ($engine === $this->current()) {
            return ['ok' => false, 'message' => "Already using {$engine}."];
        }

        if (! $this->sync->secondaryIsReachable()) {
            return ['ok' => false, 'message' => 'Secondary database is unreachable.'];
        }

        $misaligned = $this->sync->misalignedReason();

        if ($misaligned !== null) {
            return ['ok' => false, 'message' => $misaligned];
        }

        Artisan::call('queue:restart');

        $this->dotenv->update([
            'DB_CONNECTION' => $engine,
            'DB_DUAL_WRITE' => $keepDualWrite ? 'true' : 'false',
        ]);

        config([
            'database.default' => $engine,
            'nyk-db-sync.enabled' => $keepDualWrite,
        ]);

        Artisan::call('config:clear');
        Artisan::call('queue:restart');

        if ($engine === 'pgsql') {
            $this->sync->repairSequences('pgsql');
        }

        return ['ok' => true, 'message' => "Live database is now {$engine}. Queue workers were signaled to restart."];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function repairSequences(): array
    {
        $connection = $this->sync->postgresConnection();
        $this->sync->repairSequences($connection);

        return [
            'ok' => true,
            'message' => "Postgres ID sequences on {$connection} were aligned to MAX(id).",
        ];
    }
}
