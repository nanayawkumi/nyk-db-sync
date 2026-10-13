<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Nanayawkumi\NykDbSync\Schema\ColumnTypeMapper;
use PHPUnit\Framework\TestCase;

class ColumnTypeMapperTest extends TestCase
{
    public function test_it_maps_mysql_and_postgres_types(): void
    {
        $mapper = new ColumnTypeMapper;

        $string = $mapper->map([
            'name' => 'name',
            'type' => 'varchar(100)',
            'type_name' => 'varchar',
            'nullable' => false,
        ]);
        $this->assertSame('string', $string['method']);
        $this->assertSame([100], $string['parameters']);

        $boolean = $mapper->map([
            'name' => 'active',
            'type' => 'tinyint(1)',
            'type_name' => 'tinyint',
            'nullable' => false,
            'default' => '0',
        ]);
        $this->assertSame('boolean', $boolean['method']);
        $this->assertTrue($boolean['has_default']);
        $this->assertFalse($boolean['default']);

        $id = $mapper->map([
            'name' => 'id',
            'type' => 'bigint unsigned',
            'type_name' => 'bigint',
            'auto_increment' => true,
            'nullable' => false,
        ]);
        $this->assertSame('bigIncrements', $id['method']);
        $this->assertFalse($id['unsigned']);

        $decimal = $mapper->map([
            'name' => 'amount',
            'type' => 'decimal(10,2)',
            'type_name' => 'decimal',
            'nullable' => true,
        ]);
        $this->assertSame('decimal', $decimal['method']);
        $this->assertSame([10, 2], $decimal['parameters']);
        $this->assertTrue($decimal['nullable']);

        $enum = $mapper->map([
            'name' => 'status',
            'type' => "enum('Pending','Paid')",
            'type_name' => 'enum',
            'nullable' => false,
        ]);
        $this->assertSame('enum', $enum['method']);
        $this->assertSame([['Pending', 'Paid']], $enum['parameters']);

        $json = $mapper->map([
            'name' => 'meta',
            'type' => 'jsonb',
            'type_name' => 'jsonb',
            'nullable' => true,
        ]);
        $this->assertSame('jsonb', $json['method']);

        $timestamp = $mapper->map([
            'name' => 'created_at',
            'type' => 'timestamp',
            'type_name' => 'timestamp',
            'nullable' => true,
            'default' => 'CURRENT_TIMESTAMP',
        ]);
        $this->assertSame('timestamp', $timestamp['method']);
        $this->assertTrue($timestamp['use_current']);

        $zeroDate = $mapper->map([
            'name' => 'updated_at',
            'type' => 'timestamp',
            'type_name' => 'timestamp',
            'nullable' => false,
            'default' => '0000-00-00 00:00:00',
        ]);
        $this->assertSame('timestamp', $zeroDate['method']);
        $this->assertFalse($zeroDate['has_default']);
        $this->assertTrue($zeroDate['nullable']);
        $this->assertNotEmpty($zeroDate['warnings']);

        $varying = $mapper->map([
            'name' => 'code',
            'type' => 'character varying(32)',
            'type_name' => 'varchar',
            'nullable' => false,
        ]);
        $this->assertSame('string', $varying['method']);
        $this->assertSame([32], $varying['parameters']);

        $serial = $mapper->map([
            'name' => 'id',
            'type' => 'bigint',
            'type_name' => 'int8',
            'auto_increment' => true,
            'nullable' => false,
            'default' => "nextval('widgets_id_seq')",
        ]);
        $this->assertSame('bigIncrements', $serial['method']);
        $this->assertFalse($serial['has_default']);

        $generated = $mapper->map([
            'name' => 'full_name',
            'type' => 'varchar(255)',
            'type_name' => 'varchar',
            'generation' => ['type' => 'stored', 'expression' => 'name'],
        ]);
        $this->assertTrue($generated['skipped']);

        $unknown = $mapper->map([
            'name' => 'network',
            'type' => 'cidr',
            'type_name' => 'cidr',
            'nullable' => true,
        ]);
        $this->assertSame('string', $unknown['method']);
        $this->assertNotEmpty($unknown['warnings']);
    }
}
