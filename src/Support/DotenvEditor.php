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

        $contents = (string) file_get_contents($path);

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->formatValue($value);

            if (preg_match('/^'.preg_quote($key, '/').'=/m', $contents) === 1) {
                $contents = preg_replace(
                    '/^'.preg_quote($key, '/').'=.*$/m',
                    $line,
                    $contents,
                    1,
                ) ?? $contents;
            } else {
                $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
            }
        }

        file_put_contents($path, $contents);

        return $backup;
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
