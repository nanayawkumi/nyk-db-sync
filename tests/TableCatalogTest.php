<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nanayawkumi\NykDbSync\Schema\TableCatalog;

class TableCatalogTest extends TestCase
{
    public function test_foreign_keys_are_ordered_ahead_of_a_preferred_child(): void
    {
        $this->createFamily();

        config(['nyk-db-sync.table_order' => ['a_children', 'z_parents']]);

        $ordered = app(TableCatalog::class)->ordered('sqlite');

        $this->assertSame(['z_parents', 'a_children'], $ordered);
    }

    public function test_preferred_order_is_kept_when_tables_are_independent(): void
    {
        Schema::create('a_items', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('b_items', function (Blueprint $table): void {
            $table->id();
        });

        config(['nyk-db-sync.table_order' => ['b_items', 'a_items']]);

        $this->assertSame(['b_items', 'a_items'], app(TableCatalog::class)->ordered('sqlite'));
    }

    public function test_migrations_are_skipped(): void
    {
        Schema::create('migrations', function (Blueprint $table): void {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
        });

        $this->assertSame(['widgets'], app(TableCatalog::class)->discovered('sqlite'));
        $this->assertFalse(app(TableCatalog::class)->isSyncable('sqlite', 'migrations'));
    }

    private function createFamily(): void
    {
        Schema::create('z_parents', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('a_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('z_parent_id')->constrained('z_parents')->cascadeOnDelete();
        });
    }
}
