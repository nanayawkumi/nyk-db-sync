<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SyncCommandTest extends TestCase
{
    public function test_the_command_copies_rows_and_rejects_unknown_tables(): void
    {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        \Illuminate\Support\Facades\DB::table('widgets')->insert(['name' => 'Ada']);

        $this->artisan('nyk-db-sync:sync')
            ->assertSuccessful()
            ->expectsOutputToContain('Copied sqlite → sqlite_secondary');

        $this->artisan('nyk-db-sync:sync', ['--table' => 'not_a_real_table'])
            ->assertFailed()
            ->expectsOutputToContain('not available to sync');

        $this->artisan('nyk-db-sync:sync', ['--schema-only' => true, '--no-schema' => true])
            ->assertFailed()
            ->expectsOutputToContain('not both');
    }

    public function test_repair_sequences_is_a_noop_when_the_connection_is_not_postgres(): void
    {
        $this->artisan('nyk-db-sync:repair-sequences')
            ->assertSuccessful()
            ->expectsOutputToContain('Postgres sequences were aligned');
    }
}
