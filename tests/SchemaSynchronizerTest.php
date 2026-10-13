<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nanayawkumi\NykDbSync\Sync\SecondaryDatabaseSyncService;

class SchemaSynchronizerTest extends TestCase
{
    public function test_it_creates_the_secondary_schema_in_dependency_order_and_copies_rows(): void
    {
        Schema::create('z_parents', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(false);
        });
        Schema::create('a_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('z_parent_id')->constrained('z_parents')->cascadeOnDelete();
            $table->string('label');
        });

        $parentId = DB::table('z_parents')->insertGetId(['name' => 'Ada', 'active' => true]);
        DB::table('a_children')->insert(['z_parent_id' => $parentId, 'label' => 'Child']);

        $summary = app(SecondaryDatabaseSyncService::class)->sync();

        $this->assertSame(['z_parents', 'a_children'], $summary['schema']['created']);
        $this->assertTrue(Schema::connection('sqlite_secondary')->hasTable('a_children'));

        $foreignKeys = Schema::connection('sqlite_secondary')->getForeignKeys('a_children');
        $this->assertNotEmpty($foreignKeys);
        $this->assertSame('z_parents', $foreignKeys[0]['foreign_table']);

        $parent = DB::connection('sqlite_secondary')->table('z_parents')->first();
        $child = DB::connection('sqlite_secondary')->table('a_children')->first();

        $this->assertSame($parentId, (int) $parent->id);
        $this->assertSame('Ada', $parent->name);
        $this->assertSame(1, (int) $parent->active);
        $this->assertSame($parentId, (int) $child->z_parent_id);
        $this->assertSame('Child', $child->label);

        $second = app(SecondaryDatabaseSyncService::class)->sync();

        $this->assertSame([], $second['schema']['created']);
        $this->assertSame([], $second['schema']['foreign_keys']);
        $this->assertSame(1, DB::connection('sqlite_secondary')->table('a_children')->count());
    }

    public function test_a_second_sync_does_not_duplicate_rows_and_adds_missing_columns(): void
    {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::connection('sqlite_secondary')->create('widgets', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('widgets')->insert(['name' => 'Ada']);

        $first = app(SecondaryDatabaseSyncService::class)->sync(onlyTable: 'widgets');
        $second = app(SecondaryDatabaseSyncService::class)->sync(onlyTable: 'widgets');

        $this->assertSame(['name'], $first['schema']['columns_added']['widgets']);
        $this->assertArrayNotHasKey('widgets', $second['schema']['columns_added']);
        $this->assertSame(1, DB::connection('sqlite_secondary')->table('widgets')->count());
        $this->assertSame('Ada', DB::connection('sqlite_secondary')->table('widgets')->value('name'));
    }

    public function test_schema_only_does_not_copy_rows(): void
    {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        DB::table('widgets')->insert(['name' => 'Ada']);

        app(SecondaryDatabaseSyncService::class)->sync(schemaOnly: true);

        $this->assertTrue(Schema::connection('sqlite_secondary')->hasTable('widgets'));
        $this->assertSame(0, DB::connection('sqlite_secondary')->table('widgets')->count());
    }
}
