<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;
use Nanayawkumi\NykDbSync\Cutover\DatabaseEngineSwitcher;
use Nanayawkumi\NykDbSync\DualWrite;
use Nanayawkumi\NykDbSync\Http\DatabaseSyncController;
use Nanayawkumi\NykDbSync\Support\SummaryStore;
use Nanayawkumi\NykDbSync\Sync\SecondaryDatabaseSyncService;

class Panel extends Component
{
    public function render(): View
    {
        return view('nyk-db-sync::content', array_merge(
            app(DatabaseSyncController::class)->pageData(
                app(DualWrite::class),
                app(SecondaryDatabaseSyncService::class),
                app(SummaryStore::class),
                app(DatabaseEngineSwitcher::class),
            ),
            ['standalone' => false],
        ));
    }
}
