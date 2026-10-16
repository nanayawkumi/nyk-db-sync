<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Nanayawkumi\NykDbSync\Cutover\DatabaseEngineSwitcher;
use Nanayawkumi\NykDbSync\DualWrite;
use Nanayawkumi\NykDbSync\Postgres\PostgresDatabaseSetup;
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
        $counts = $reachable ? $sync->compareCounts() : [];
        $summary = $summaries->get();
        $lastDuration = is_array($summary) && isset($summary['duration_seconds'])
            ? (new SyncEstimate())->duration((float) $summary['duration_seconds'])
            : null;

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
            'alignment' => $reachable ? $sync->misalignedReason(0, $counts) : 'Secondary database is unreachable.',
            'reachable' => $reachable,
            'engines' => $switcher->engines(),
        ];
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
            return back()->with('error', $exception->getMessage());
        }

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

    public function repairSequences(DatabaseEngineSwitcher $switcher): RedirectResponse
    {
        $this->assertAllowed();

        $result = $switcher->repairSequences();

        return back()->with($result['ok'] ? 'status' : 'error', $result['message']);
    }

    private function assertAllowed(): void
    {
        $ability = config('nyk-db-sync.ui.gate');

        if (is_string($ability) && $ability !== '') {
            abort_unless(Gate::allows($ability), 403);
        }
    }

    private function configured(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
