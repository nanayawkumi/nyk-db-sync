<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nanayawkumi\NykDbSync\Sync\ForeignKeyGuard;

class ForeignKeyGuardTest extends TestCase
{
    public function test_rows_can_be_inserted_before_their_parent_exists(): void
    {
        Schema::create('parents', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->constrained('parents');
        });

        app(ForeignKeyGuard::class)->withoutChecks('sqlite', function (): void {
            DB::table('children')->insert(['id' => 1, 'parent_id' => 33]);
        });

        $this->assertSame(1, DB::table('children')->count());
        $this->assertSame(33, (int) DB::table('children')->value('parent_id'));

        $this->expectException(QueryException::class);
        DB::table('children')->insert(['id' => 2, 'parent_id' => 34]);
    }
}
