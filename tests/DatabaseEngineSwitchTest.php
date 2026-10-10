<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nanayawkumi\NykDbSync\Cutover\DatabaseEngineSwitcher;
use Nanayawkumi\NykDbSync\Support\DotenvEditor;

class DatabaseEngineSwitchTest extends TestCase
{
    public function test_it_updates_a_temp_dotenv_file(): void
    {
        $path = $this->tempEnv("APP_NAME=NYK\nDB_CONNECTION=mysql\nDB_DUAL_WRITE=false\n");

        try {
            $backup = app(DotenvEditor::class)->update([
                'DB_CONNECTION' => 'pgsql',
                'DB_DUAL_WRITE' => true,
            ]);

            $contents = (string) file_get_contents($path);

            $this->assertStringContainsString('DB_CONNECTION=pgsql', $contents);
            $this->assertStringContainsString('DB_DUAL_WRITE=true', $contents);
            $this->assertStringContainsString('APP_NAME=NYK', $contents);
            $this->assertFileExists($backup);
        } finally {
            $this->cleanup($path);
        }
    }

    public function test_it_refuses_an_invalid_engine_and_an_unreachable_secondary(): void
    {
        $path = $this->tempEnv("DB_CONNECTION=mysql\n");

        try {
            $invalid = app(DatabaseEngineSwitcher::class)->switchTo('sqlite');

            $this->assertFalse($invalid['ok']);
            $this->assertSame('Engine must be mysql or pgsql.', $invalid['message']);

            config([
                'database.connections.broken' => [
                    'driver' => 'sqlite',
                    'database' => '/this/path/does/not/exist/nyk-db-sync.sqlite',
                ],
                'nyk-db-sync.secondary' => 'broken',
            ]);

            $unreachable = app(DatabaseEngineSwitcher::class)->switchTo('pgsql');

            $this->assertFalse($unreachable['ok']);
            $this->assertSame('Secondary database is unreachable.', $unreachable['message']);
            $this->assertStringContainsString('DB_CONNECTION=mysql', (string) file_get_contents($path));
        } finally {
            $this->cleanup($path);
        }
    }

    public function test_it_refuses_to_switch_when_a_table_is_missing_on_the_secondary(): void
    {
        $path = $this->tempEnv("DB_CONNECTION=sqlite\nDB_DUAL_WRITE=false\n");
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
        });

        try {
            $result = app(DatabaseEngineSwitcher::class)->switchTo('pgsql', true);

            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('widgets is missing', $result['message']);
            $this->assertStringContainsString('DB_CONNECTION=sqlite', (string) file_get_contents($path));
        } finally {
            config(['database.default' => 'sqlite']);
            $this->cleanup($path);
        }
    }

    public function test_it_writes_the_env_switch_when_the_secondary_is_aligned(): void
    {
        $path = $this->tempEnv("DB_CONNECTION=sqlite\nDB_DUAL_WRITE=true\n");

        try {
            $result = app(DatabaseEngineSwitcher::class)->switchTo('pgsql', true);

            $this->assertTrue($result['ok'], $result['message']);
            $this->assertStringContainsString('DB_CONNECTION=pgsql', (string) file_get_contents($path));
            $this->assertStringContainsString('DB_DUAL_WRITE=true', (string) file_get_contents($path));
        } finally {
            config([
                'database.default' => 'sqlite',
                'nyk-db-sync.enabled' => false,
            ]);
            $this->cleanup($path);
        }
    }

    private function tempEnv(string $contents): string
    {
        $path = sys_get_temp_dir().'/nyk-db-sync-'.uniqid('', true);
        file_put_contents($path, $contents);
        config(['nyk-db-sync.dotenv_path' => $path]);

        return $path;
    }

    private function cleanup(string $path): void
    {
        foreach (glob($path.'.bak.*') ?: [] as $backup) {
            @unlink($backup);
        }

        @unlink($path);
    }
}
