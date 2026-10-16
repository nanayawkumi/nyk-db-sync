<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Optimize;

use InvalidArgumentException;
use Nanayawkumi\NykDbSync\TableName;

final class PartitionRequest
{
    /**
     * @return array{table: string, column: string, interval: string}
     */
    public static function parse(string $value): array
    {
        $parts = explode(':', $value);

        if (count($parts) !== 3 || ! in_array($parts[2], ['day', 'month', 'year'], true)) {
            throw new InvalidArgumentException('A partition must be table:column:day, table:column:month, or table:column:year.');
        }

        if (! TableName::isSafe($parts[0]) || ! TableName::isSafe($parts[1])) {
            throw new InvalidArgumentException("The partition [{$value}] may contain only letters, numbers, and underscores.");
        }

        return [
            'table' => $parts[0],
            'column' => $parts[1],
            'interval' => $parts[2],
        ];
    }
}
