<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Nanayawkumi\NykDbSync\Postgres\PostgresSession;
use Nanayawkumi\NykDbSync\Postgres\PostgresSessionFactory;
use Nanayawkumi\NykDbSync\Tests\Fixtures\FakePostgresSession;
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
            ->assertSee('0%')
            ->assertSee('Create Postgres database')
            ->assertSee('Generate a password')
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

    public function test_the_page_creates_a_postgres_database_and_writes_env(): void
    {
        $path = sys_get_temp_dir().'/nyk-db-sync-'.uniqid('', true);
        file_put_contents($path, "DB_CONNECTION=mysql\nPGSQL_HOST=old\n");
        config(['nyk-db-sync.dotenv_path' => $path]);

        $this->app->instance(PostgresSessionFactory::class, new class(new FakePostgresSession()) implements PostgresSessionFactory
        {
            public function __construct(private PostgresSession $session) {}

            public function open(string $host, string $port, string $adminPassword): PostgresSession
            {
                return $this->session;
            }
        });

        try {
            $this->from('/nyk-db-sync')
                ->post('/nyk-db-sync/create-database', [
                    'host' => '127.0.0.1',
                    'port' => '5432',
                    'database' => 'geonet',
                    'username' => 'geonet_app',
                    'password' => 'secret',
                    'admin_password' => '',
                ])
                ->assertRedirect('/nyk-db-sync')
                ->assertSessionHas('status');

            $contents = (string) file_get_contents($path);

            $this->assertStringContainsString('DB_CONNECTION=mysql', $contents);
            $this->assertStringContainsString('PGSQL_DATABASE=geonet', $contents);
            $this->assertStringContainsString('PGSQL_USERNAME=geonet_app', $contents);
            $this->assertStringContainsString('PGSQL_PASSWORD=secret', $contents);
            $this->assertStringNotContainsString('PGSQL_HOST=old', $contents);
        } finally {
            foreach (glob($path.'.bak.*') ?: [] as $backup) {
                @unlink($backup);
            }

            @unlink($path);
        }
    }

    public function test_the_page_can_generate_the_login_password(): void
    {
        $path = sys_get_temp_dir().'/nyk-db-sync-'.uniqid('', true);
        file_put_contents($path, "DB_CONNECTION=mysql\n");
        config(['nyk-db-sync.dotenv_path' => $path]);

        $this->app->instance(PostgresSessionFactory::class, new class(new FakePostgresSession()) implements PostgresSessionFactory
        {
            public function __construct(private PostgresSession $session) {}

            public function open(string $host, string $port, string $adminPassword): PostgresSession
            {
                return $this->session;
            }
        });

        try {
            $this->from('/nyk-db-sync')
                ->post('/nyk-db-sync/create-database', [
                    'host' => '127.0.0.1',
                    'port' => '5432',
                    'database' => 'geonet',
                    'username' => 'geonet_app',
                    'generate_password' => '1',
                    'admin_password' => '',
                ])
                ->assertRedirect('/nyk-db-sync')
                ->assertSessionHas('status');

            $status = (string) session('status');
            $contents = (string) file_get_contents($path);

            $this->assertStringContainsString('Generated password:', $status);
            $this->assertMatchesRegularExpression('/^PGSQL_PASSWORD=.+$/m', $contents);
        } finally {
            foreach (glob($path.'.bak.*') ?: [] as $backup) {
                @unlink($backup);
            }

            @unlink($path);
        }
    }

    public function test_repair_sequences_reports_the_postgres_side(): void
    {
        $this->from('/nyk-db-sync')
            ->post('/nyk-db-sync/repair-sequences')
            ->assertRedirect('/nyk-db-sync')
            ->assertSessionHas('status', 'Postgres ID sequences on sqlite_secondary were aligned to MAX(id).');
    }
}
