<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Nanayawkumi\NykDbSync\Tests\Fixtures\HostShell;

class AdminPageTest extends TestCase
{
    public function test_the_page_syncs_and_a_gate_can_block_it(): void
    {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        DB::table('widgets')->insert(['name' => 'Ada']);

        $this->get('/nyk-db-sync')
            ->assertOk()
            ->assertSee('Live database engine')
            ->assertSee('Connected')
            ->assertSee('1 row')
            ->assertSee('Estimated')
            ->assertSee('0 copied')
            ->assertSee('1 left')
            ->assertDontSee('Not connected');

        $this->from('/nyk-db-sync')->post('/nyk-db-sync/sync')->assertRedirect('/nyk-db-sync');

        $this->assertSame('Ada', DB::connection('sqlite_secondary')->table('widgets')->value('name'));

        config(['nyk-db-sync.ui.gate' => 'manage-database']);

        $this->get('/nyk-db-sync')->assertForbidden();
    }

    public function test_the_sync_ui_renders_inside_a_host_layout(): void
    {
        View::addLocation(__DIR__.'/fixtures');
        config(['nyk-db-sync.ui.layout' => 'host-layout']);

        $this->get('/nyk-db-sync')
            ->assertOk()
            ->assertSee('id="sidebar"', false)
            ->assertSee('Live database engine')
            ->assertDontSee('<title>Database sync</title>', false);
    }

    public function test_the_sync_ui_renders_inside_a_host_component(): void
    {
        View::addLocation(__DIR__.'/fixtures');
        Blade::component('host-shell', HostShell::class);
        config(['nyk-db-sync.ui.component' => 'host-shell']);

        $this->get('/nyk-db-sync')
            ->assertOk()
            ->assertSee('id="sidebar"', false)
            ->assertSee('Live database engine')
            ->assertDontSee('<title>Database sync</title>', false);
    }

    public function test_the_sync_ui_can_be_placed_on_an_existing_page(): void
    {
        View::addLocation(__DIR__.'/fixtures');
        Route::middleware('web')->get('/settings/database', fn () => view('host-page'));

        $this->get('/settings/database')
            ->assertOk()
            ->assertSee('id="sidebar"', false)
            ->assertSee('id="host-content"', false)
            ->assertSee('Live database engine');
    }

    public function test_the_page_shows_when_a_connection_fails(): void
    {
        config(['nyk-db-sync.secondary' => 'pgsql']);

        $this->get('/nyk-db-sync')
            ->assertOk()
            ->assertSee('Connected')
            ->assertSee('Not connected');
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
