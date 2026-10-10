<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminPageTest extends TestCase
{
    public function test_the_page_syncs_and_a_gate_can_block_it(): void
    {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        DB::table('widgets')->insert(['name' => 'Ada']);

        $this->get('/nyk-db-sync')->assertOk()->assertSee('Live database engine');

        $this->from('/nyk-db-sync')->post('/nyk-db-sync/sync')->assertRedirect('/nyk-db-sync');

        $this->assertSame('Ada', DB::connection('sqlite_secondary')->table('widgets')->value('name'));

        config(['nyk-db-sync.ui.gate' => 'manage-database']);

        $this->get('/nyk-db-sync')->assertForbidden();
    }

    public function test_switch_requires_confirmation(): void
    {
        $this->from('/nyk-db-sync')
            ->post('/nyk-db-sync/switch', ['engine' => 'pgsql'])
            ->assertSessionHasErrors('confirmed');
    }

    public function test_repair_sequences_reports_the_postgres_side(): void
    {
        $this->from('/nyk-db-sync')
            ->post('/nyk-db-sync/repair-sequences')
            ->assertRedirect('/nyk-db-sync')
            ->assertSessionHas('status', 'Postgres ID sequences on sqlite_secondary were aligned to MAX(id).');
    }
}
