<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Database;

use Illuminate\Database\MariaDbConnection;
use Nanayawkumi\NykDbSync\Database\Concerns\BuildsDualWriteQueries;

class DualWriteMariaDbConnection extends MariaDbConnection
{
    use BuildsDualWriteQueries;
}
