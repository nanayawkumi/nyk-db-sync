<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Nanayawkumi\NykDbSync\Optimize\IndexAdvisor;

class IndexAdvisorTest extends TestCase
{
    public function test_it_recommends_only_columns_the_origin_does_not_index(): void
    {
        $recommendations = (new IndexAdvisor())->recommend([
            ['table' => 'orders', 'column' => 'status', 'source' => 'app/Models/Order.php'],
            ['table' => 'orders', 'column' => 'id', 'source' => 'app/Models/Order.php'],
            ['table' => 'orders', 'column' => 'missing', 'source' => 'app/Models/Order.php'],
        ], [
            'orders' => [
                ['columns' => ['id'], 'primary' => true],
            ],
        ], [
            'orders' => [],
        ], [
            'orders' => ['id', 'status'],
        ]);

        $this->assertCount(1, $recommendations);
        $this->assertSame('status', $recommendations[0]['column']);
        $this->assertSame(
            'create index concurrently "nyk_orders_status_idx" on "orders" ("status")',
            $recommendations[0]['sql'],
        );
    }

    public function test_it_skips_a_column_postgres_already_indexes(): void
    {
        $recommendations = (new IndexAdvisor())->recommend([
            ['table' => 'orders', 'column' => 'status', 'source' => 'app/Models/Order.php'],
        ], [
            'orders' => [],
        ], [
            'orders' => [
                ['columns' => ['status'], 'primary' => false],
            ],
        ], [
            'orders' => ['status'],
        ]);

        $this->assertSame([], $recommendations);
    }
}
