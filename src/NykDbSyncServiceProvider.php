<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Nanayawkumi\NykDbSync\Console\RepairPostgresSequencesCommand;
use Nanayawkumi\NykDbSync\Console\SyncSecondaryDatabaseCommand;
use Nanayawkumi\NykDbSync\Database\DualWriteMariaDbConnection;
use Nanayawkumi\NykDbSync\Database\DualWriteMySqlConnection;
use Nanayawkumi\NykDbSync\Database\DualWriteObserver;
use Nanayawkumi\NykDbSync\Database\DualWritePostgresConnection;
use Nanayawkumi\NykDbSync\Database\DualWriteSQLiteConnection;
use Nanayawkumi\NykDbSync\Http\DatabaseSyncController;
use ReflectionClass;

final class NykDbSyncServiceProvider extends ServiceProvider
{
    /**
     * @var array<string, true>
     */
    private array $observed = [];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nyk-db-sync.php', 'nyk-db-sync');

        Connection::resolverFor('mysql', function ($connection, $database, $prefix, $config) {
            return new DualWriteMySqlConnection($connection, $database, $prefix, $config);
        });

        Connection::resolverFor('pgsql', function ($connection, $database, $prefix, $config) {
            return new DualWritePostgresConnection($connection, $database, $prefix, $config);
        });

        Connection::resolverFor('sqlite', function ($connection, $database, $prefix, $config) {
            return new DualWriteSQLiteConnection($connection, $database, $prefix, $config);
        });

        if (class_exists(\Illuminate\Database\MariaDbConnection::class)) {
            Connection::resolverFor('mariadb', function ($connection, $database, $prefix, $config) {
                return new DualWriteMariaDbConnection($connection, $database, $prefix, $config);
            });
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'nyk-db-sync');
        $this->registerObservers();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nyk-db-sync.php' => $this->app->configPath('nyk-db-sync.php'),
            ], 'nyk-db-sync-config');

            $this->publishes([
                __DIR__.'/Views' => $this->app->resourcePath('views/vendor/nyk-db-sync'),
            ], 'nyk-db-sync-views');

            $this->commands([
                SyncSecondaryDatabaseCommand::class,
                RepairPostgresSequencesCommand::class,
            ]);
        }

        if (! config('nyk-db-sync.ui.enabled')) {
            return;
        }

        $middleware = config('nyk-db-sync.ui.middleware', ['web']);

        if (is_string($middleware)) {
            $middleware = [$middleware];
        }

        $path = trim((string) config('nyk-db-sync.ui.path', 'nyk-db-sync'), '/');

        Route::middleware(is_array($middleware) ? $middleware : ['web'])
            ->prefix($path)
            ->name('nyk-db-sync.')
            ->group(function (): void {
                Route::get('/', [DatabaseSyncController::class, 'index'])->name('index');
                Route::post('/sync', [DatabaseSyncController::class, 'sync'])->name('sync');
                Route::post('/switch', [DatabaseSyncController::class, 'switchEngine'])->name('switch');
                Route::post('/repair-sequences', [DatabaseSyncController::class, 'repairSequences'])->name('repair');
            });
    }

    private function registerObservers(): void
    {
        foreach (config('nyk-db-sync.models.extra', []) as $class) {
            if (is_string($class)) {
                $this->observe($class);
            }
        }

        if (! config('nyk-db-sync.models.discover', true)) {
            return;
        }

        $path = config('nyk-db-sync.models.path');

        if (! is_string($path) || $path === '') {
            $path = app_path('Models');
        }

        if (! is_dir($path)) {
            return;
        }

        $namespace = trim((string) config('nyk-db-sync.models.namespace', 'App\\Models'), '\\');

        foreach (File::files($path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $this->observe($namespace.'\\'.$file->getFilenameWithoutExtension());
        }
    }

    private function observe(string $class): void
    {
        if (isset($this->observed[$class]) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            return;
        }

        $class::observe(DualWriteObserver::class);
        $this->observed[$class] = true;
    }
}
