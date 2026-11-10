<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nanayawkumi\NykDbSync\Analysis\DecisionStore;

class AnalyzeCommandTest extends TestCase
{
    public function test_it_reports_a_clean_database_as_ready(): void
    {
        $this->widgets();

        $this->artisan('nyk-db-sync:analyze')
            ->assertSuccessful()
            ->expectsOutputToContain('ready')
            ->expectsOutputToContain('Every analyzed table can be converted and copied.');
    }

    public function test_it_prints_json_and_fails_when_a_table_is_blocked(): void
    {
        $this->documents();

        $this->artisan('nyk-db-sync:analyze', ['--report' => true])
            ->assertFailed()
            ->expectsOutputToContain('"code": "invalid_json"');
    }

    public function test_it_asks_what_to_do_about_each_blocker(): void
    {
        $this->documents();

        $this->artisan('nyk-db-sync:analyze')
            ->expectsChoice(
                'How should this be handled?',
                'Fix it for me: Store as text',
                [
                    'Fix it for me: Store as text',
                    "I'll fix it myself (Store the column as text so the values are kept as they are.)",
                    'Accept the risk and continue anyway',
                ],
            )
            ->assertSuccessful();

        $this->assertSame('auto', app(DecisionStore::class)->all()['documents.payload.invalid_json']['mode']);
    }

    public function test_choosing_manual_keeps_the_command_failing(): void
    {
        $this->documents();

        $this->artisan('nyk-db-sync:analyze')
            ->expectsChoice(
                'How should this be handled?',
                "I'll fix it myself (Store the column as text so the values are kept as they are.)",
                [
                    'Fix it for me: Store as text',
                    "I'll fix it myself (Store the column as text so the values are kept as they are.)",
                    'Accept the risk and continue anyway',
                ],
            )
            ->assertFailed()
            ->expectsOutputToContain('Blocked: documents');
    }

    public function test_fix_auto_answers_without_asking_and_skips_issues_with_no_fix(): void
    {
        $this->documents();
        Schema::create('links', function (Blueprint $table): void {
            $table->unsignedBigInteger('a');
        });
        DB::table('links')->insert(['a' => 1]);

        $this->artisan('nyk-db-sync:analyze', ['--fix' => 'auto', '--no-interaction' => true])
            ->assertFailed()
            ->expectsOutputToContain('Blocked: links');

        $this->assertSame('auto', app(DecisionStore::class)->all()['documents.payload.invalid_json']['mode']);
        $this->assertArrayNotHasKey('links.*.no_upsert_key', app(DecisionStore::class)->all());

        $this->artisan('nyk-db-sync:analyze', ['--fix' => 'skip', '--no-interaction' => true])->assertSuccessful();
    }

    public function test_it_rejects_an_unknown_fix_mode_and_an_unknown_table(): void
    {
        $this->artisan('nyk-db-sync:analyze', ['--fix' => 'maybe'])->assertFailed()->expectsOutputToContain('--fix must be');
        $this->artisan('nyk-db-sync:analyze', ['--table' => 'nope'])->assertFailed()->expectsOutputToContain('not available');
    }

    public function test_sync_is_blocked_until_the_issue_is_decided_and_can_skip_the_check(): void
    {
        $this->documents();

        $this->artisan('nyk-db-sync:sync')->assertFailed()->expectsOutputToContain('Sync blocked');
        $this->artisan('nyk-db-sync:analyze', ['--fix' => 'auto', '--no-interaction' => true])->assertSuccessful();
        $this->artisan('nyk-db-sync:sync')->assertSuccessful();

        $this->assertSame(2, DB::connection('sqlite_secondary')->table('documents')->count());
    }

    public function test_skip_analysis_flag_bypasses_the_gate(): void
    {
        $this->documents();

        $this->artisan('nyk-db-sync:sync', ['--skip-analysis' => true])->assertSuccessful();
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
}
