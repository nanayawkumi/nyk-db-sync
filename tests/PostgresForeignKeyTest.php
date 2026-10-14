<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Nanayawkumi\NykDbSync\Schema\PostgresForeignKey;
use PDOException;
use RuntimeException;

class PostgresForeignKeyTest extends TestCase
{
    public function test_a_missing_parent_is_added_without_checking_existing_rows(): void
    {
        $sql = (new PostgresForeignKey())->notValidStatement(
            'order_details',
            'order_details_order_id_foreign',
            ['order_id'],
            'orders',
            ['id'],
            'cascade',
            '',
        );

        $this->assertSame(
            'alter table "order_details" add constraint "order_details_order_id_foreign" foreign key ("order_id") references "orders" ("id") on delete cascade not valid',
            $sql,
        );
    }

    public function test_it_recognizes_a_postgres_foreign_key_violation(): void
    {
        $violation = new PDOException(
            'SQLSTATE[23503]: Foreign key violation: 7 ERROR: insert or update on table "order_details" violates foreign key constraint "order_details_order_id_foreign"',
        );

        $postgres = new PostgresForeignKey();

        $this->assertTrue($postgres->isViolation($violation));
        $this->assertFalse($postgres->isViolation(new RuntimeException('connection refused')));
    }
}
