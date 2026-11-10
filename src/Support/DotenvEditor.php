<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Support;

use RuntimeException;

final class DotenvEditor
{
    public function path(): string
    {
        $configured = config('nyk-db-sync.dotenv_path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : base_path('.env');
    }

    /**
     * @param  array<string, scalar|null>  $values
     */
    public function update(array $values): string
    {
        $path = $this->path();

        if (! is_file($path)) {
            throw new RuntimeException("Dotenv file [{$path}] does not exist.");
        }

        $backup = $path.'.bak.'.now()->format('YmdHis');
        copy($path, $backup);
        // The backup holds every secret in .env. Keep it to the owner.
        @chmod($backup, 0600);
        $this->pruneBackups($path, $backup);

        $contents = (string) file_get_contents($path);

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->formatValue($value);

            if (preg_match('/^'.preg_quote($key, '/').'=/m', $contents) === 1) {
                // A callback keeps "$1" and backslashes in a value from being read as references.
                $contents = preg_replace_callback(
                    '/^'.preg_quote($key, '/').'=.*$/m',
                    static fn (): string => $line,
                    $contents,
                    1,
                ) ?? $contents;
            } else {
                $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
            }
        }

        $this->writeAtomically($path, $contents);

        return $backup;
    }

    /**
     * Write a temporary file beside the target and rename it over, so a crash never leaves a half-written .env.
     */
    private function writeAtomically(string $path, string $contents): void
    {
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(4));
        $mode = @fileperms($path);

        if (file_put_contents($temporary, $contents) === false) {
            throw new RuntimeException("Could not write [{$temporary}].");
        }

        if ($mode !== false) {
            @chmod($temporary, $mode & 0777);
        }

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Could not replace [{$path}].");
        }
    }

    /**
     * Keep the newest few backups. Each one holds plaintext passwords.
     */
    private function pruneBackups(string $path, string $current): void
    {
        $keep = max(1, (int) config('nyk-db-sync.dotenv_backups', 5));
        $backups = glob($path.'.bak.*') ?: [];
        sort($backups);

        foreach (array_slice($backups, 0, max(0, count($backups) - $keep)) as $old) {
            if ($old !== $current) {
                @unlink($old);
            }
        }
    }

    protected function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $string = (string) $value;

        if ($string === '' || preg_match('/^[A-Za-z0-9_.:\/+-]+$/', $string) === 1) {
            return $string;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $string).'"';
    }
}
