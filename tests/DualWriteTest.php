<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nanayawkumi\NykDbSync\Database\DualWriteObserver;
use Nanayawkumi\NykDbSync\Database\DualWriteSQLiteConnection;
use Nanayawkumi\NykDbSync\DualWrite;
use Nanayawkumi\NykDbSync\Sync\SecondaryDatabaseSyncService;
use Nanayawkumi\NykDbSync\Tests\Fixtures\Item;
use Nanayawkumi\NykDbSync\Tests\Fixtures\Tag;
use Nanayawkumi\NykDbSync\Tests\Fixtures\Widget;

class DualWriteTest extends TestCase
{
    public function test_the_sqlite_connection_replicates_writes(): void
    {
        $this->assertInstanceOf(DualWriteSQLiteConnection::class, DB::connection());

        $this->widgets();
        config(['nyk-db-sync.enabled' => true]);

        DB::table('widgets')->insert(['name' => 'Ada']);
        $id = (int) DB::table('widgets')->value('id');
        DB::table('widgets')->where('id', $id)->update(['name' => 'Bea']);

        $this->assertSame('Bea', DB::connection('sqlite_secondary')->table('widgets')->where('id', $id)->value('name'));

        DB::table('widgets')->where('id', $id)->delete();

        $this->assertSame(0, DB::table('widgets')->count());
        $this->assertSame(0, DB::connection('sqlite_secondary')->table('widgets')->count());
    }

    public function test_an_eloquent_model_and_pivot_are_replicated(): void
    {
        $this->widgets();
        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('item_tag', function (Blueprint $table): void {
            $table->foreignId('item_id');
            $table->foreignId('tag_id');
        });
        app(SecondaryDatabaseSyncService::class)->sync();

        config(['nyk-db-sync.enabled' => true]);
        Widget::observe(DualWriteObserver::class);
        Item::observe(DualWriteObserver::class);

        $widget = Widget::query()->create(['name' => 'Ada']);
        $item = Item::query()->create(['name' => 'Box']);
        $tag = Tag::query()->create(['name' => 'Red']);
        $item->tags()->attach($tag->id);

        $this->assertTrue(DB::connection('sqlite_secondary')->table('widgets')->where('id', $widget->id)->where('name', 'Ada')->exists());
        $this->assertSame(1, DB::connection('sqlite_secondary')->table('item_tag')->count());
    }

    public function test_a_disabled_secondary_does_not_receive_the_primary_write(): void
    {
        $this->widgets();
        config(['nyk-db-sync.enabled' => false]);

        DB::table('widgets')->insert(['name' => 'Ada']);

        $this->assertSame(1, DB::table('widgets')->count());
        $this->assertSame(0, DB::connection('sqlite_secondary')->table('widgets')->count());
    }

    public function test_a_failing_secondary_write_keeps_the_primary_row(): void
    {
        $this->widgets();
        config(['nyk-db-sync.enabled' => true]);

        DB::connection('sqlite_secondary')->unprepared(
            "CREATE TRIGGER widgets_block BEFORE INSERT ON widgets BEGIN SELECT RAISE(ABORT, 'blocked'); END",
        );

        DB::table('widgets')->insert(['name' => 'Ada']);

        $this->assertSame(1, DB::table('widgets')->count());
        $this->assertSame(0, DB::connection('sqlite_secondary')->table('widgets')->count());
    }

    public function test_advancing_a_sequence_is_skipped_when_the_connection_is_not_postgres(): void
    {
        $this->expectNotToPerformAssertions();

        app(DualWrite::class)->advancePostgresId('sqlite', 'widgets', 15);
    }

    public function test_mysql_and_pgsql_infer_each_other(): void
    {
        config([
            'database.default' => 'mysql',
            'nyk-db-sync.secondary' => null,
        ]);

        $this->assertSame('pgsql', app(DualWrite::class)->secondaryConnection());

        config(['database.default' => 'pgsql']);

        $this->assertSame('mysql', app(DualWrite::class)->secondaryConnection());
    }

    private function widgets(): void
    {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        app(SecondaryDatabaseSyncService::class)->sync(onlyTable: 'widgets', schemaOnly: true);
    }
}
