<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Database;

use Illuminate\Database\SQLiteConnection;
use Nanayawkumi\NykDbSync\Database\Concerns\BuildsDualWriteQueries;

class DualWriteSQLiteConnection extends SQLiteConnection
{
    use BuildsDualWriteQueries;
}
