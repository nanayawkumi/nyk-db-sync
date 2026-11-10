<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nanayawkumi\NykDbSync\Analysis\AnalysisBlockedException;
use Nanayawkumi\NykDbSync\Analysis\DatabaseAnalyzer;
use Nanayawkumi\NykDbSync\Analysis\DecisionStore;
use Nanayawkumi\NykDbSync\Sync\RowNormalizer;
use Nanayawkumi\NykDbSync\Sync\SecondaryDatabaseSyncService;

class AnalysisTest extends TestCase
{
    public function test_a_clean_table_is_ready(): void
    {
        $this->widgets();

        $entry = app(DatabaseAnalyzer::class)->analyze(['widgets'])['widgets'];

        $this->assertSame(DatabaseAnalyzer::READY, $entry['status']);
        $this->assertSame(1, $entry['rows']);
        $this->assertSame([], array_filter($entry['issues'], fn (array $issue): bool => $issue['severity'] !== 'info'));
    }

    public function test_invalid_json_blocks_the_table_and_names_a_fix(): void
    {
        $this->documents();

        $entry = app(DatabaseAnalyzer::class)->analyze(['documents'])['documents'];

        $this->assertSame(DatabaseAnalyzer::BLOCKED, $entry['status']);
        $issue = $this->issue($entry, 'documents.payload.invalid_json');
        $this->assertTrue($issue['blocking']);
        $this->assertSame('text', $issue['fix']['method']);
        $this->assertStringContainsString('1 values', $issue['message']);
    }

    public function test_a_value_longer_than_the_fallback_string_length_is_found(): void
    {
        DB::statement('create table gadgets (id integer primary key autoincrement, shape weirdtype)');
        DB::table('gadgets')->insert(['shape' => str_repeat('x', 300)]);

        $entry = app(DatabaseAnalyzer::class)->analyze(['gadgets'])['gadgets'];

        $this->assertSame('blocked', $entry['status']);
        $this->assertNotNull($this->issue($entry, 'gadgets.shape.length_overflow'));
    }

    public function test_sync_runs_the_analysis_itself_and_refuses_a_blocked_table(): void
    {
        $this->documents();

        try {
            app(SecondaryDatabaseSyncService::class)->sync();
            $this->fail('The sync should have been blocked.');
        } catch (AnalysisBlockedException $exception) {
            $this->assertArrayHasKey('documents', $exception->blocked);
            $this->assertStringContainsString('nyk-db-sync:analyze', $exception->getMessage());
        }

        $this->assertFalse(Schema::connection('sqlite_secondary')->hasTable('documents'));
    }

    public function test_the_gate_can_be_turned_off_or_skipped_for_one_run(): void
    {
        $this->documents();

        app(SecondaryDatabaseSyncService::class)->sync(skipAnalysis: true);
        $this->assertTrue(Schema::connection('sqlite_secondary')->hasTable('documents'));

        Schema::connection('sqlite_secondary')->drop('documents');
        config(['nyk-db-sync.analysis.required' => false]);
        app(SecondaryDatabaseSyncService::class)->sync();
        $this->assertTrue(Schema::connection('sqlite_secondary')->hasTable('documents'));
    }

    public function test_an_automatic_fix_unblocks_the_table_and_is_applied_by_the_sync(): void
    {
        $this->documents();
        $analyzer = app(DatabaseAnalyzer::class);
        $analyzer->analyze(['documents']);
        $analyzer->decide('documents.payload.invalid_json', DecisionStore::AUTO);

        $this->assertSame(DatabaseAnalyzer::ATTENTION, $analyzer->stored()['documents']['status'], 'a chosen fix unblocks the table but stays visible');
        $this->assertSame([], $analyzer->blocked(['documents']));

        app(SecondaryDatabaseSyncService::class)->sync();

        $columns = collect(Schema::connection('sqlite_secondary')->getColumns('documents'))->keyBy('name');
        $this->assertSame('text', strtolower((string) $columns['payload']['type_name']));
        $this->assertSame('not json', DB::connection('sqlite_secondary')->table('documents')->where('id', 2)->value('payload'));
    }

    public function test_a_second_sync_after_an_automatic_fix_is_not_refused(): void
    {
        $this->documents();
        $this->artisan('nyk-db-sync:analyze', ['--fix' => 'auto', '--no-interaction' => true])->assertSuccessful();

        $this->artisan('nyk-db-sync:sync')->assertSuccessful();
        $this->artisan('nyk-db-sync:sync')->assertSuccessful();

        $this->assertSame([], app(DatabaseAnalyzer::class)->blocked(['documents']));
        $this->assertSame(2, DB::connection('sqlite_secondary')->table('documents')->count());
    }

    public function test_manual_stays_blocked_until_the_source_is_fixed_and_skip_accepts_the_risk(): void
    {
        $this->documents();
        $analyzer = app(DatabaseAnalyzer::class);
        $analyzer->analyze(['documents']);
        $analyzer->decide('documents.payload.invalid_json', DecisionStore::MANUAL);

        $this->assertSame('blocked', $analyzer->stored()['documents']['status']);
        $this->assertArrayHasKey('documents', $analyzer->blocked(['documents']));

        DB::table('documents')->where('id', 2)->update(['payload' => '{"ok":true}']);
        $analyzer->analyze(['documents']);

        $this->assertNotSame('blocked', $analyzer->stored()['documents']['status']);
        $this->assertSame([], $analyzer->blocked(['documents']));
        $this->assertArrayNotHasKey('documents.payload.invalid_json', app(DecisionStore::class)->all(), 'a decision for a fixed issue is dropped');

        DB::table('documents')->where('id', 2)->update(['payload' => 'broken']);
        $analyzer->analyze(['documents']);
        $analyzer->decide('documents.payload.invalid_json', DecisionStore::SKIP);

        $this->assertSame([], $analyzer->blocked(['documents']));
    }

    public function test_an_automatic_decision_needs_a_fix(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(DecisionStore::class)->record('t.*.no_upsert_key', DecisionStore::AUTO, 't', null, null);
    }

    public function test_a_missing_upsert_key_blocks_only_when_rows_would_fail_to_copy(): void
    {
        Schema::create('links', function (Blueprint $table): void {
            $table->unsignedBigInteger('a');
            $table->unsignedBigInteger('b');
        });
        $analyzer = app(DatabaseAnalyzer::class);

        $empty = $analyzer->analyze(['links'])['links'];
        $this->assertSame('warning', $this->issue($empty, 'links.*.no_upsert_key')['severity']);
        $this->assertSame(DatabaseAnalyzer::ATTENTION, $empty['status']);

        DB::table('links')->insert(['a' => 1, 'b' => 2]);
        $filled = $analyzer->analyze(['links'])['links'];

        $this->assertSame('blocker', $this->issue($filled, 'links.*.no_upsert_key')['severity']);
        $this->assertNull($this->issue($filled, 'links.*.no_upsert_key')['fix']);
        $this->assertSame('blocked', $filled['status']);
    }

    public function test_a_stored_analysis_is_reused_until_the_schema_changes(): void
    {
        $this->widgets();
        $analyzer = app(DatabaseAnalyzer::class);
        $analyzer->analyze(['widgets']);
        $analyzedAt = app(\Nanayawkumi\NykDbSync\Analysis\AnalysisStore::class)->get()['tables']['widgets']['analyzed_at'];

        $this->travel(5)->seconds();
        $analyzer->blocked(['widgets']);
        $this->assertSame($analyzedAt, app(\Nanayawkumi\NykDbSync\Analysis\AnalysisStore::class)->get()['tables']['widgets']['analyzed_at']);

        Schema::table('widgets', function (Blueprint $table): void {
            $table->string('nickname')->nullable();
        });
        $analyzer->blocked(['widgets']);

        $this->assertNotSame($analyzedAt, app(\Nanayawkumi\NykDbSync\Analysis\AnalysisStore::class)->get()['tables']['widgets']['analyzed_at']);
    }

    public function test_schema_only_syncs_ignore_data_issues(): void
    {
        $this->documents();

        $this->assertArrayHasKey('documents', app(DatabaseAnalyzer::class)->blocked(['documents']));
        $this->assertSame([], app(DatabaseAnalyzer::class)->blocked(['documents'], schemaOnly: true));
    }

    public function test_the_strip_nul_fix_is_applied_to_copied_and_replicated_rows(): void
    {
        $store = app(DecisionStore::class);
        $store->record('notes.body.nul_bytes', DecisionStore::AUTO, 'notes', 'body', ['kind' => 'value', 'transform' => 'strip_nul', 'label' => 'Strip NUL bytes']);

        $row = (new RowNormalizer($store))->normalize(['body' => "a\0b", 'other' => "c\0d"], [], 'pgsql', 'notes');

        $this->assertSame('ab', $row['body']);
        $this->assertSame("c\0d", $row['other']);
        $this->assertSame("a\0b", (new RowNormalizer($store))->normalize(['body' => "a\0b"], [], 'pgsql', 'other_table')['body']);
    }

    private function widgets(): void
    {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        DB::table('widgets')->insert(['name' => 'Ada']);
    }

    private function documents(): void
    {
        DB::statement('create table documents (id integer primary key autoincrement, payload json)');
        DB::table('documents')->insert(['payload' => '{"ok":true}']);
        DB::table('documents')->insert(['payload' => 'not json']);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private function issue(array $entry, string $id): ?array
    {
        foreach ($entry['issues'] as $issue) {
            if ($issue['id'] === $id) {
                return $issue;
            }
        }

        return null;
    }
}
