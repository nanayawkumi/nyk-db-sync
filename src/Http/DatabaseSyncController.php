<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Nanayawkumi\NykDbSync\Cutover\DatabaseEngineSwitcher;
use Nanayawkumi\NykDbSync\DualWrite;
use Nanayawkumi\NykDbSync\Support\SummaryStore;
use Nanayawkumi\NykDbSync\Sync\SecondaryDatabaseSyncService;
use Throwable;

final class DatabaseSyncController
{
    public function index(DualWrite $dualWrite, SecondaryDatabaseSyncService $sync, SummaryStore $summaries, DatabaseEngineSwitcher $switcher): View
    {
        $this->assertAllowed();

        $reachable = $sync->secondaryIsReachable();
        $counts = $reachable ? $sync->compareCounts() : [];

        return view('nyk-db-sync::index', [
            'primary' => $dualWrite->primaryConnection(),
            'secondary' => $dualWrite->secondaryConnection(),
            'enabled' => $dualWrite->enabled(),
            'summary' => $summaries->get(),
            'counts' => $counts,
            'alignment' => $reachable ? $sync->misalignedReason(0, $counts) : 'Secondary database is unreachable.',
            'reachable' => $reachable,
            'engines' => $switcher->engines(),
        ]);
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
}
