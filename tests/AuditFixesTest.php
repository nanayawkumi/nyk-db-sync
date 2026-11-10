<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nanayawkumi\NykDbSync\Analysis\AnalysisStore;
use Nanayawkumi\NykDbSync\Analysis\DatabaseAnalyzer;
use Nanayawkumi\NykDbSync\Analysis\DecisionStore;
use Nanayawkumi\NykDbSync\Support\DotenvEditor;
use Nanayawkumi\NykDbSync\Sync\RowNormalizer;
use Nanayawkumi\NykDbSync\Sync\SecondaryDatabaseSyncService;

class AuditFixesTest extends TestCase
{
    private function counters(): void
    {
        Schema::create('counters', function (Blueprint $table): void {
            $table->id();
            $table->integer('votes')->default(0);
        });

        app(SecondaryDatabaseSyncService::class)->sync(onlyTable: 'counters', schemaOnly: true);
    }

    public function test_increment_and_raw_updates_reach_the_secondary_as_numbers(): void
    {
        $this->counters();
        config(['nyk-db-sync.enabled' => true]);

        DB::table('counters')->insert(['votes' => 1]);
        DB::table('counters')->increment('votes', 5);
        DB::table('counters')->update(['votes' => DB::raw('votes * 2')]);

        $this->assertSame(12, (int) DB::table('counters')->value('votes'));
        $this->assertSame(12, (int) DB::connection('sqlite_secondary')->table('counters')->value('votes'));
    }

    public function test_a_disabled_dual_write_never_queries_the_secondary(): void
    {
        $this->counters();
        config(['nyk-db-sync.enabled' => false]);

        $secondary = DB::connection('sqlite_secondary');
        $secondary->enableQueryLog();

        DB::table('counters')->insert(['votes' => 1]);
        DB::table('counters')->update(['votes' => 2]);

        $this->assertSame([], $secondary->getQueryLog());
    }

    public function test_dotenv_values_with_dollar_signs_and_backslashes_survive(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "A=1\nDB_PASSWORD=old\nB=2\n");
        config(['nyk-db-sync.dotenv_path' => $path]);

        $backup = app(DotenvEditor::class)->update(['DB_PASSWORD' => 'ab$1cd\\q$&x']);

        $this->assertSame("A=1\nDB_PASSWORD=\"ab\$1cd\\\\q\$&x\"\nB=2\n", file_get_contents($path));

        unlink($path);
        unlink($backup);
    }

    public function test_only_date_columns_turn_a_zero_date_into_null(): void
    {
        $rows = new RowNormalizer;

        $this->assertSame('0000-00-00', $rows->normalize(['note' => '0000-00-00'], ['note' => ['type_name' => 'varchar']], 'mysql')['note']);
        $this->assertNull($rows->normalize(['at' => '0000-00-00 00:00:00'], ['at' => ['type_name' => 'datetime']], 'mysql')['at']);
        $this->assertNull($rows->normalize(['at' => '0000-00-00'], [], 'mysql')['at']);
    }

    public function test_the_page_refuses_everyone_until_a_gate_is_set(): void
    {
        config(['nyk-db-sync.ui.allow_without_gate' => false, 'nyk-db-sync.ui.gate' => null]);

        $this->get('/nyk-db-sync')->assertForbidden();
        $this->post('/nyk-db-sync/sync')->assertForbidden();
    }

    public function test_a_skipped_issue_blocks_again_when_the_data_gets_worse(): void
    {
        DB::statement('create table documents (id integer primary key autoincrement, payload json)');
        DB::table('documents')->insert(['payload' => 'not json']);

        $analyzer = app(DatabaseAnalyzer::class);
        $analyzer->analyze(['documents']);
        $analyzer->decide('documents.payload.invalid_json', DecisionStore::SKIP);

        $this->assertSame([], $analyzer->blocked(['documents']));

        DB::table('documents')->insert(['payload' => 'also not json']);
        $analyzer->analyze(['documents']);

        $this->assertArrayHasKey('documents', $analyzer->blocked(['documents']));
    }

    public function test_a_schema_only_gate_does_not_scan_or_store_anything(): void
    {
        DB::statement('create table documents (id integer primary key autoincrement, payload json)');
        DB::table('documents')->insert(['payload' => 'not json']);

        $this->assertSame([], app(DatabaseAnalyzer::class)->blocked(['documents'], schemaOnly: true));
        $this->assertNull(app(AnalysisStore::class)->get());
    }

    public function test_columns_missing_on_the_secondary_are_reported_not_dropped_silently(): void
    {
        $this->counters();
        Schema::table('counters', fn (Blueprint $table) => $table->string('note')->nullable());
        DB::table('counters')->insert(['votes' => 1, 'note' => 'x']);
        config(['nyk-db-sync.schema.add_missing_columns' => false]);

        $summary = app(SecondaryDatabaseSyncService::class)->sync(onlyTable: 'counters', skipAnalysis: true);

        $this->assertSame(['note'], $summary['tables'][0]['dropped_columns']);
    }

    public function test_binary_values_headed_for_postgres_are_sent_as_streams(): void
    {
        $rows = new RowNormalizer;
        $raw = "has\0nul\\and\xff";

        $value = $rows->normalize(['file' => $raw], ['file' => ['type_name' => 'bytea']], 'pgsql')['file'];

        $this->assertIsResource($value);
        $this->assertSame($raw, stream_get_contents($value));
        $this->assertSame($raw, $rows->normalize(['file' => $raw], ['file' => ['type_name' => 'bytea']], 'mysql')['file']);
    }
}
