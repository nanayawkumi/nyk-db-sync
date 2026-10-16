<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Nanayawkumi\NykDbSync\Optimize\QueryIndexScanner;

class QueryIndexScannerTest extends TestCase
{
    public function test_it_reads_query_columns_from_models_and_call_sites(): void
    {
        $directory = sys_get_temp_dir().'/nyk-db-sync-scan-'.uniqid('', true);
        mkdir($directory.'/Models', 0777, true);

        file_put_contents($directory.'/Models/Order.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    public function scopeOpen($query)
    {
        return $query->where('status', 'open')->orderBy('placed_at');
    }
}
PHP);
        file_put_contents($directory.'/ListOrders.php', <<<'PHP'
<?php

class ListOrders
{
    public function run()
    {
        return Order::where('customer_id', 1)->latest();
    }
}
PHP);

        try {
            $uses = (new QueryIndexScanner())->scan([$directory]);
            $columns = [];

            foreach ($uses as $use) {
                $this->assertSame('orders', $use['table']);
                $columns[$use['column']] = true;
            }

            $this->assertArrayHasKey('status', $columns);
            $this->assertArrayHasKey('placed_at', $columns);
            $this->assertArrayHasKey('customer_id', $columns);
            $this->assertArrayHasKey('created_at', $columns);
        } finally {
            @unlink($directory.'/Models/Order.php');
            @unlink($directory.'/ListOrders.php');
            @rmdir($directory.'/Models');
            @rmdir($directory);
        }
    }
}
