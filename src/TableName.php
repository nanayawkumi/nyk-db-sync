<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync;

final class TableName
{
    public static function isSafe(string $table): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $table) === 1;
    }

    public static function bare(string $table): string
    {
        $table = trim($table, " \t\"'`");

        if (str_contains($table, '.')) {
            $table = substr($table, (int) strrpos($table, '.') + 1);
        }

        return trim($table, " \t\"'`");
    }
}
