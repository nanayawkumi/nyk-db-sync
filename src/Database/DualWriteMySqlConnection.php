<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Database;

use Illuminate\Database\MySqlConnection;
use Nanayawkumi\NykDbSync\Database\Concerns\BuildsDualWriteQueries;

class DualWriteMySqlConnection extends MySqlConnection
{
    use BuildsDualWriteQueries;
}
