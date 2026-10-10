<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Database;

use Illuminate\Database\PostgresConnection;
use Nanayawkumi\NykDbSync\Database\Concerns\BuildsDualWriteQueries;

class DualWritePostgresConnection extends PostgresConnection
{
    use BuildsDualWriteQueries;
}
