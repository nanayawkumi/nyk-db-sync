<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Nanayawkumi\NykDbSync\Analysis\DatabaseAnalyzer;
use Nanayawkumi\NykDbSync\Analysis\DecisionStore;
use Nanayawkumi\NykDbSync\Cutover\DatabaseEngineSwitcher;
use Nanayawkumi\NykDbSync\DualWrite;
use Nanayawkumi\NykDbSync\Postgres\PostgresDatabaseCreator;
use Nanayawkumi\NykDbSync\Postgres\PostgresDatabasePresence;
use Nanayawkumi\NykDbSync\Postgres\PostgresDatabaseSetup;
use Nanayawkumi\NykDbSync\Postgres\PostgresSessionFactory;
use Nanayawkumi\NykDbSync\Support\SummaryStore;
use Nanayawkumi\NykDbSync\Sync\SecondaryDatabaseSyncService;
use Nanayawkumi\NykDbSync\Sync\SyncEstimate;
use Throwable;

final class DatabaseSyncController
{
    public function index(DualWrite $dualWrite, SecondaryDatabaseSyncService $sync, SummaryStore $summaries, DatabaseEngineSwitcher $switcher): View
    {
        $data = $this->pageData($dualWrite, $sync, $summaries, $switcher);
        $component = $this->configured('nyk-db-sync.ui.component');

        if ($component !== null) {
            return view('nyk-db-sync::in-component', array_merge($data, [
                'nykLayoutComponent' => $component,
                'standalone' => false,
            ]));
        }

        $layout = $this->configured('nyk-db-sync.ui.layout');

        if ($layout !== null) {
            return view('nyk-db-sync::embedded', array_merge($data, [
                'nykLayout' => $layout,
                'nykSection' => $this->configured('nyk-db-sync.ui.section') ?? 'content',
                'standalone' => false,
            ]));
        }

        return view('nyk-db-sync::index', array_merge($data, [
            'standalone' => true,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function pageData(DualWrite $dualWrite, SecondaryDatabaseSyncService $sync, SummaryStore $summaries, DatabaseEngineSwitcher $switcher): array
    {
        $this->assertAllowed();

        $primary = $dualWrite->primaryConnection();
        $secondary = $dualWrite->secondaryConnection();
        $connections = [
            array_merge($sync->probe($primary), ['role' => 'Primary']),
            array_merge($sync->probe($secondary), ['role' => 'Secondary']),
        ];
        $reachable = $connections[1]['connected'];
        $pgsqlProbe = $sync->probe('pgsql');
        $counts = $reachable ? $this->counts($sync) : [];
        $summary = $summaries->get();
        $lastDuration = is_array($summary) && isset($summary['duration_seconds'])
            ? (new SyncEstimate())->duration((float) $summary['duration_seconds'])
            : null;
        $analysis = $reachable ? app(DatabaseAnalyzer::class)->stored() : [];

        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'connections' => $connections,
            'enabled' => $dualWrite->enabled(),
            'summary' => $summary,
            'lastDuration' => $lastDuration,
            'estimate' => $sync->estimate(null, $reachable ? $counts : null),
            'progress' => array_merge((new SyncEstimate())->copyProgress($counts), [
                'known' => $reachable,
            ]),
            'counts' => $counts,
            'analysis' => $analysis,
            'analysisBlocked' => array_keys(array_filter($analysis, static fn (array $entry): bool => $entry['status'] === DatabaseAnalyzer::BLOCKED)),
            'alignment' => $reachable ? $sync->misalignedReason(0, $counts) : 'Secondary database is unreachable.',
            'reachable' => $reachable,
            'engines' => $switcher->engines(),
            'pgsql' => $this->pgsqlDefaults(),
            'postgresDatabaseMissing' => (new PostgresDatabasePresence())->missing(
                $pgsqlProbe['database'],
                $pgsqlProbe['connected'],
                $pgsqlProbe['error'],
            ),
        ];
    }

    public function analyze(Request $request, DatabaseAnalyzer $analyzer): RedirectResponse
    {
        $this->assertAllowed();

        $data = $request->validate([
            'table' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_]*$/'],
            'sample' => ['nullable', 'integer', 'min:0'],
        ]);

        $table = isset($data['table']) && $data['table'] !== '' ? $data['table'] : null;

        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            $entries = $analyzer->analyze($table === null ? null : [$table], (int) ($data['sample'] ?? 0));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $blocked = count(array_filter($entries, static fn (array $entry): bool => $entry['status'] === DatabaseAnalyzer::BLOCKED));

        return back()->with(
            $blocked > 0 ? 'error' : 'status',
            $blocked > 0
                ? "Analysis found {$blocked} table(s) that need a decision before they can sync."
                : 'Analysis finished. Every analyzed table can be converted and copied.',
        );
    }

    public function decide(Request $request, DatabaseAnalyzer $analyzer): RedirectResponse
    {
        $this->assertAllowed();

        $data = $request->validate([
            'decisions' => ['required', 'array'],
            'decisions.*' => ['nullable', 'string', 'max:500'],
        ]);

        $saved = 0;

        try {
            foreach ($data['decisions'] as $choice) {
                // Each choice is "mode|issue id". Issue ids contain dots, so they cannot be field names.
                if (! is_string($choice) || ! str_contains($choice, '|')) {
                    continue;
                }

                [$mode, $issueId] = explode('|', $choice, 2);

                if (! in_array($mode, [DecisionStore::AUTO, DecisionStore::MANUAL, DecisionStore::SKIP], true)) {
                    continue;
                }

                $analyzer->decide($issueId, $mode);
                $saved++;
            }
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', $saved === 0 ? 'No decisions were chosen.' : "Saved {$saved} decision(s).");
    }

    public function sync(Request $request, SecondaryDatabaseSyncService $sync): RedirectResponse
    {
        $this->assertAllowed();

        $data = $request->validate([
            'table' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_]*$/'],
            'schema_only' => ['nullable', 'boolean'],
        ]);

        $table = isset($data['table']) && $data['table'] !== '' ? $data['table'] : null;

        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            $sync->sync($table, 500, true, $request->boolean('schema_only'));
        } catch (Throwable $exception) {
            Cache::forget(self::COUNTS_CACHE_KEY);

            return back()->with('error', $exception->getMessage());
        }

        Cache::forget(self::COUNTS_CACHE_KEY);

        return back()->with('status', 'Sync finished.');
    }

    public function switchEngine(Request $request, DatabaseEngineSwitcher $switcher): RedirectResponse
    {
        $this->assertAllowed();

        $data = $request->validate([
            'engine' => ['required', 'string', 'in:'.implode(',', $switcher->engines())],
            'keep_dual_write' => ['nullable', 'boolean'],
            'confirmed' => ['accepted'],
        ]);

        $result = $switcher->switchTo($data['engine'], $request->boolean('keep_dual_write'));
        Cache::forget(self::COUNTS_CACHE_KEY);

        return back()->with($result['ok'] ? 'status' : 'error', $result['message']);
    }

    public function createDatabase(Request $request, PostgresDatabaseSetup $setup): RedirectResponse
    {
        $this->assertAllowed();

        try {
            $data = $request->validate([
                'host' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]+$/'],
                'port' => ['required', 'integer', 'min:1', 'max:65535'],
                'database' => ['required', 'string', 'regex:/^[A-Za-z0-9_]+$/'],
                'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_]+$/'],
                'password' => ['required_unless:generate_password,1', 'nullable', 'string'],
                'generate_password' => ['nullable', 'boolean'],
                'admin_username' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_]+$/'],
                'admin_password' => ['nullable', 'string'],
            ]);
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except(['password', 'admin_password']))
                ->withErrors($exception->errors());
        }

        $generated = $request->boolean('generate_password');
        $password = $generated ? $setup->generatePassword() : (string) $data['password'];

        try {
            $setup->install(
                $data['host'],
                (string) $data['port'],
                $data['database'],
                $data['username'],
                $password,
                (string) ($data['admin_password'] ?? ''),
                $this->adminUsername($data['admin_username'] ?? null),
            );
        } catch (Throwable $exception) {
            return back()
                ->withInput($request->except(['password', 'admin_password']))
                ->with('error', $exception->getMessage());
        }

        $status = "Postgres database [{$data['database']}] is ready for login [{$data['username']}]. Wrote PGSQL_HOST, PGSQL_PORT, PGSQL_DATABASE, PGSQL_USERNAME, and PGSQL_PASSWORD.";

        if ($generated) {
            $status .= ' Generated password: '.$password;
        }

        return back()->with('status', $status);
    }

    public function grantPrivileges(Request $request, PostgresDatabaseCreator $creator, PostgresSessionFactory $sessions): RedirectResponse
    {
        $this->assertAllowed();

        try {
            $data = $request->validate([
                'host' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]+$/'],
                'port' => ['required', 'integer', 'min:1', 'max:65535'],
                'database' => ['required', 'string', 'regex:/^[A-Za-z0-9_]+$/'],
                'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_]+$/'],
                'admin_username' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_]+$/'],
                'admin_password' => ['nullable', 'string'],
            ]);
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except('admin_password'))
                ->withErrors($exception->errors());
        }

        try {
            $session = $sessions->open(
                $data['host'],
                (string) $data['port'],
                (string) ($data['admin_password'] ?? ''),
                $this->adminUsername($data['admin_username'] ?? null),
            );
            $creator->grant($session, $data['database'], $data['username']);
        } catch (Throwable $exception) {
            return back()
                ->withInput($request->except('admin_password'))
                ->with('error', $exception->getMessage());
        }

        return back()->with('status', "Granted CONNECT, USAGE, and CREATE on [{$data['database']}] to [{$data['username']}].");
    }

    public function repairSequences(DatabaseEngineSwitcher $switcher): RedirectResponse
    {
        $this->assertAllowed();

        $result = $switcher->repairSequences();

        return back()->with($result['ok'] ? 'status' : 'error', $result['message']);
    }

    private const COUNTS_CACHE_KEY = 'nyk-db-sync.counts';

    /**
     * @return list<array{table: string, source: int, destination: int|null, delta: int}>
     */
    private function counts(SecondaryDatabaseSyncService $sync): array
    {
        $seconds = (int) config('nyk-db-sync.ui.counts_cache_seconds', 30);

        if ($seconds <= 0) {
            return $sync->compareCounts();
        }

        return Cache::remember(self::COUNTS_CACHE_KEY, $seconds, static fn (): array => $sync->compareCounts());
    }

    private function assertAllowed(): void
    {
        $ability = config('nyk-db-sync.ui.gate');

        if (is_string($ability) && $ability !== '') {
            abort_unless(Gate::allows($ability), 403);

            return;
        }

        // This page can run syncs, rewrite .env and take database superuser credentials.
        // "Any signed-in user" is not a safe default for that.
        abort_unless(
            (bool) config('nyk-db-sync.ui.allow_without_gate', false),
            403,
            'Set nyk-db-sync.ui.gate to a Gate ability before using the sync page.',
        );
    }

    /**
     * @return array{host: string, port: string, database: string, username: string}
     */
    private function pgsqlDefaults(): array
    {
        $configured = config('database.connections.pgsql');
        $configured = is_array($configured) ? $configured : [];

        return [
            'host' => is_string($configured['host'] ?? null) && $configured['host'] !== '' ? $configured['host'] : '127.0.0.1',
            'port' => isset($configured['port']) && (string) $configured['port'] !== '' ? (string) $configured['port'] : '5432',
            'database' => is_string($configured['database'] ?? null) ? $configured['database'] : '',
            'username' => is_string($configured['username'] ?? null) ? $configured['username'] : '',
        ];
    }

    private function adminUsername(mixed $username): string
    {
        return is_string($username) && $username !== '' ? $username : 'postgres';
    }

    private function configured(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
