<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Nanayawkumi\NykDbSync\Analysis\ColumnRules;
use Nanayawkumi\NykDbSync\Schema\ColumnTypeMapper;
use PHPUnit\Framework\TestCase;

class ColumnRulesTest extends TestCase
{
    public function test_a_value_longer_than_the_fallback_string_is_a_blocker_with_a_text_fix(): void
    {
        $issues = $this->judge(['name' => 'tag', 'type' => 'geometry', 'type_name' => 'geometry', 'nullable' => true], ['tag:max_length' => 300]);

        $this->assertCount(2, $issues); // the mapper note, then the overflow
        $overflow = $issues[1];
        $this->assertSame('length_overflow', $overflow['code']);
        $this->assertSame('blocker', $overflow['severity']);
        $this->assertSame('text', $overflow['fix']['method']);
    }

    public function test_a_declared_varchar_that_cannot_overflow_is_not_scanned(): void
    {
        $probes = (new ColumnRules())->probes('t', ['name' => 'a', 'type' => 'varchar(50)', 'type_name' => 'varchar'], $this->mapped('varchar(50)', 'varchar'), $this->context());

        $this->assertNotContains('max_length', array_column($probes, 'kind'));
    }

    public function test_unsigned_int_values_above_the_signed_range_overflow_on_postgres(): void
    {
        $column = ['name' => 'views', 'type' => 'int unsigned', 'type_name' => 'int', 'nullable' => false];

        $this->assertSame([], $this->judge($column, ['views:range' => ['min' => '0', 'max' => '2147483647']]));

        $issues = $this->judge($column, ['views:range' => ['min' => '0', 'max' => '4000000000']]);

        $this->assertSame('integer_overflow', $issues[0]['code']);
        $this->assertSame('bigInteger', $issues[0]['fix']['method']);
    }

    public function test_an_unsigned_bigint_beyond_signed_bigint_needs_a_decimal(): void
    {
        $column = ['name' => 'big', 'type' => 'bigint unsigned', 'type_name' => 'bigint', 'nullable' => false];
        $issues = $this->judge($column, ['big:range' => ['min' => '0', 'max' => '18446744073709551000']]);

        $this->assertSame('decimal', $issues[0]['fix']['method']);
        $this->assertSame([20, 0], $issues[0]['fix']['parameters']);
    }

    public function test_integer_ranges_compare_exactly_at_the_64_bit_edge(): void
    {
        $rules = new ColumnRules();

        $this->assertSame(0, $rules->compare('9223372036854775807', '9223372036854775807'));
        $this->assertSame(1, $rules->compare('9223372036854775808', '9223372036854775807'));
        $this->assertSame(-1, $rules->compare('-9223372036854775808', '-1'));
        $this->assertSame(1, $rules->compare('0', '-1'));
        $this->assertSame(0, $rules->compare('-0', '0'));
    }

    public function test_zero_dates_block_a_not_null_column_and_only_warn_on_a_nullable_one(): void
    {
        $required = ['name' => 'born', 'type' => 'datetime', 'type_name' => 'datetime', 'nullable' => false];
        $optional = ['name' => 'born', 'type' => 'datetime', 'type_name' => 'datetime', 'nullable' => true];

        $blocker = $this->judge($required, ['born:zero_dates' => 3])[0];
        $warning = $this->judge($optional, ['born:zero_dates' => 3])[0];

        $this->assertSame('blocker', $blocker['severity']);
        $this->assertSame('nullable', $blocker['fix']['kind']);
        $this->assertSame('warning', $warning['severity']);
        $this->assertNull($warning['fix']);
    }

    public function test_nul_bytes_only_matter_when_copying_mysql_text_to_postgres(): void
    {
        $column = ['name' => 'body', 'type' => 'longtext', 'type_name' => 'longtext', 'nullable' => true];

        $issue = $this->judge($column, ['body:nul_bytes' => 2])[0];

        $this->assertSame('value', $issue['fix']['kind']);
        $this->assertSame('strip_nul', $issue['fix']['transform']);
        $this->assertSame([], (new ColumnRules())->probes('t', $column, $this->mapped('longtext', 'longtext'), $this->context('sqlite', 'sqlite')));
    }

    public function test_an_enum_value_outside_the_list_is_a_blocker(): void
    {
        $column = ['name' => 'status', 'type' => "enum('a','b')", 'type_name' => 'enum', 'nullable' => false];
        $probe = (new ColumnRules())->probes('t', $column, $this->mapped("enum('a','b')", 'enum'), $this->context())[0];

        $this->assertSame('invalid_enum', $probe['kind']);
        $this->assertSame(['a', 'b'], $probe['args']['values']);

        $key = ColumnRules::probeKey('status', 'invalid_enum', $probe['args']);

        $this->assertSame('invalid_enum', $this->judge($column, [$key => 4])[0]['code']);
    }

    public function test_non_binary_booleans_warn_and_offer_to_keep_the_integer(): void
    {
        $issue = $this->judge(['name' => 'flag', 'type' => 'tinyint(1)', 'type_name' => 'tinyint', 'nullable' => false], ['flag:non_binary_bool' => 5])[0];

        $this->assertSame('warning', $issue['severity']);
        $this->assertSame('tinyInteger', $issue['fix']['method']);
    }

    public function test_a_column_that_already_exists_on_postgres_only_gets_fixes_that_can_be_altered(): void
    {
        $text = ['name' => 'tag', 'type' => 'geometry', 'type_name' => 'geometry', 'nullable' => true];
        $existing = ['name' => 'tag', 'type' => 'character varying(255)', 'type_name' => 'varchar', 'nullable' => true];

        $alterable = $this->judge($text, ['tag:max_length' => 400], $this->context('mysql', 'pgsql', $existing));
        $this->assertSame('text', $alterable[1]['fix']['method']);
        $this->assertArrayNotHasKey('existing', $alterable[1]['fix']);

        $flag = ['name' => 'flag', 'type' => 'tinyint(1)', 'type_name' => 'tinyint', 'nullable' => false];
        $boolean = ['name' => 'flag', 'type' => 'boolean', 'type_name' => 'bool', 'nullable' => false];
        $manual = $this->judge($flag, ['flag:non_binary_bool' => 5], $this->context('mysql', 'pgsql', $boolean))[0];

        $this->assertNull($manual['fix']);
        $this->assertStringContainsString('by hand', $manual['recommendation']);
    }

    public function test_a_failed_probe_produces_no_issue(): void
    {
        $this->assertSame([], $this->judge(['name' => 'views', 'type' => 'int unsigned', 'type_name' => 'int', 'nullable' => false], ['views:range' => null]));
    }

    /**
     * @param  array<string, mixed>  $column
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>|null  $context
     * @return list<array<string, mixed>>
     */
    private function judge(array $column, array $answers, ?array $context = null): array
    {
        $mapped = (new ColumnTypeMapper())->map($column);

        return (new ColumnRules())->issues('t', $column, $mapped, $context ?? $this->context(), $answers);
    }

    /**
     * @param  array<string, mixed>|null  $destinationColumn
     * @return array{source_driver: string, dest_driver: string, dest_column: array<string, mixed>|null}
     */
    private function context(string $source = 'mysql', string $destination = 'pgsql', ?array $destinationColumn = null): array
    {
        return ['source_driver' => $source, 'dest_driver' => $destination, 'dest_column' => $destinationColumn];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapped(string $type, string $typeName): array
    {
        return (new ColumnTypeMapper())->map(['name' => 'a', 'type' => $type, 'type_name' => $typeName, 'nullable' => true]);
    }
}
