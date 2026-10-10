<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use DateTimeImmutable;
use Nanayawkumi\NykDbSync\Sync\RowNormalizer;
use PHPUnit\Framework\TestCase;

class RowNormalizerTest extends TestCase
{
    public function test_it_prepares_mysql_values_for_postgres(): void
    {
        $normalizer = new RowNormalizer;

        $row = $normalizer->normalize([
            'active' => 0,
            'title' => true,
            'when' => '0000-00-00 00:00:00',
            'meta' => ['a' => 1],
            'on' => new DateTimeImmutable('2026-10-10 12:00:00'),
        ], [
            'active' => ['type_name' => 'bool', 'type' => 'boolean'],
            'title' => ['type_name' => 'varchar', 'type' => 'character varying(255)'],
            'when' => ['type_name' => 'timestamp', 'type' => 'timestamp without time zone'],
            'meta' => ['type_name' => 'jsonb', 'type' => 'jsonb'],
            'on' => ['type_name' => 'timestamp', 'type' => 'timestamp'],
        ], 'pgsql');

        $this->assertFalse($row['active']);
        $this->assertSame(1, $row['title']);
        $this->assertNull($row['when']);
        $this->assertSame('{"a":1}', $row['meta']);
        $this->assertSame('2026-10-10 12:00:00', $row['on']);
    }
}
