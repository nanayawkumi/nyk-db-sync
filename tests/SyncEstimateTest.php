<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Nanayawkumi\NykDbSync\Sync\SyncEstimate;

class SyncEstimateTest extends TestCase
{
    public function test_it_reports_an_empty_copy(): void
    {
        $estimate = (new SyncEstimate())->build([], null, null);

        $this->assertSame('No rows to copy.', $estimate['label']);
        $this->assertSame('', $estimate['note']);
    }

    public function test_it_uses_the_last_sync_speed_when_one_exists(): void
    {
        $estimate = (new SyncEstimate())->build([
            ['source' => 10000],
        ], 100, null);

        $this->assertSame('previous', $estimate['basis']);
        $this->assertSame('10,000 rows across 1 table. Estimated 1 minute–2 minutes.', $estimate['label']);
        $this->assertSame('Based on the speed of the last sync.', $estimate['note']);
    }

    public function test_it_gives_a_rough_range_before_any_sync(): void
    {
        $estimate = (new SyncEstimate())->build([
            ['source' => 500000],
            ['source' => 0],
        ], null, null);

        $this->assertSame('rough', $estimate['basis']);
        $this->assertStringContainsString('500,000 rows across 2 tables.', $estimate['label']);
        $this->assertStringContainsString('Estimated', $estimate['label']);
    }
}
