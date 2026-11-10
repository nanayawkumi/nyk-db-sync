<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nanayawkumi\NykDbSync\Analysis\DecisionStore;

class AnalysisPageTest extends TestCase
{
    public function test_the_page_runs_an_analysis_and_lists_what_blocks_a_table(): void
    {
        $this->documents();

        $this->get('/nyk-db-sync')->assertOk()->assertSee('No analysis yet');

        $this->from('/nyk-db-sync')->post('/nyk-db-sync/analyze')
            ->assertRedirect('/nyk-db-sync')
            ->assertSessionHas('error');

        $this->get('/nyk-db-sync')
            ->assertOk()
            ->assertSee('1 of 1 tables need a decision')
            ->assertSee('Blocked')
            ->assertSee('are not valid JSON')
            ->assertSee('Let the package fix it: Store as text', false)
            ->assertSee('Blocked until decided above: documents');
    }

    public function test_decisions_are_saved_and_unblock_the_sync(): void
    {
        $this->documents();
        $this->post('/nyk-db-sync/analyze');

        $this->from('/nyk-db-sync')->post('/nyk-db-sync/sync')
            ->assertSessionHas('error');
        $this->assertFalse(Schema::connection('sqlite_secondary')->hasTable('documents'));

        $this->from('/nyk-db-sync')->post('/nyk-db-sync/analysis/decisions', [
            'decisions' => ['', 'auto|documents.payload.invalid_json'],
        ])->assertSessionHas('status', 'Saved 1 decision(s).');

        $this->assertSame('auto', app(DecisionStore::class)->all()['documents.payload.invalid_json']['mode']);

        $this->get('/nyk-db-sync')->assertSee('Every analyzed table can be converted and copied.')->assertDontSee('Blocked until decided');

        $this->from('/nyk-db-sync')->post('/nyk-db-sync/sync')->assertSessionHas('status', 'Sync finished.');
        $this->assertSame(2, DB::connection('sqlite_secondary')->table('documents')->count());
    }

    public function test_an_unknown_issue_is_reported_and_the_gate_still_applies(): void
    {
        $this->documents();

        $this->from('/nyk-db-sync')->post('/nyk-db-sync/analysis/decisions', ['decisions' => ['skip|nope.*.nothing']])
            ->assertSessionHas('error');

        config(['nyk-db-sync.ui.gate' => 'manage-database']);
        $this->post('/nyk-db-sync/analyze')->assertForbidden();
        $this->post('/nyk-db-sync/analysis/decisions', ['decisions' => ['skip|x']])->assertForbidden();
    }

    private function documents(): void
    {
        DB::statement('create table documents (id integer primary key autoincrement, payload json)');
        DB::table('documents')->insert(['payload' => '{"ok":true}']);
        DB::table('documents')->insert(['payload' => 'not json']);
    }
}
