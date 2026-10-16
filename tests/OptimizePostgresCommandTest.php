<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

class OptimizePostgresCommandTest extends TestCase
{
    public function test_it_requires_a_mysql_origin(): void
    {
        $this->artisan('nyk-db-sync:optimize')
            ->assertFailed()
            ->expectsOutputToContain('The origin database must be the MySQL connection.');
    }

    public function test_a_partition_must_name_the_interval(): void
    {
        $this->artisan('nyk-db-sync:optimize', [
            '--partition' => 'orders:created_at',
        ])->assertFailed()
            ->expectsOutputToContain('table:column:day, table:column:month, or table:column:year');
    }
}
