<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests\Fixtures;

use Illuminate\View\Component;
use Illuminate\View\View;

class HostShell extends Component
{
    public function render(): View
    {
        return view('host-shell');
    }
}
