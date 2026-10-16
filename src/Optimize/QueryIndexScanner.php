<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Optimize;

use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class QueryIndexScanner
{
    /**
     * @param  list<string>  $directories
     * @return list<array{table: string, column: string, source: string}>
     */
    public function scan(array $directories): array
    {
        $files = $this->phpFiles($directories);
        $models = [];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            foreach ($this->modelsIn($file, $contents) as $class => $table) {
                $models[$class] = $table;
            }
        }

        $uses = [];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            $relative = $this->relative($file);

            foreach ($this->modelsIn($file, $contents) as $class => $table) {
                foreach ($this->columnsIn($contents) as $column) {
                    $uses[] = $this->use($table, $column, $relative);
                }
            }

            foreach ($models as $class => $table) {
                foreach ($this->columnsOnModel($contents, $class) as $column) {
                    $uses[] = $this->use($table, $column, $relative);
                }
            }

            foreach ($this->qualifiedColumns($contents) as $table => $columns) {
                foreach ($columns as $column) {
                    $uses[] = $this->use($table, $column, $relative);
                }
            }
        }

        return $uses;
    }

    /**
     * @return array<string, string>
     */
    private function modelsIn(string $path, string $contents): array
    {
        if (! $this->isModel($path, $contents) || preg_match_all('/class\s+([A-Za-z_][A-Za-z0-9_]*)/', $contents, $classes) === false) {
            return [];
        }

        $models = [];

        foreach ($classes[1] as $class) {
            $table = preg_match('/\$table\s*=\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $contents, $named) === 1
                ? $named[1]
                : Str::plural(Str::snake($class));
            $models[$class] = $table;
        }

        return $models;
    }

    private function isModel(string $path, string $contents): bool
    {
        return str_contains($path, DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR)
            || preg_match('/extends\s+[A-Za-z0-9_\\\\]*Model\b/', $contents) === 1;
    }

    /**
     * @return list<string>
     */
    private function columnsIn(string $contents): array
    {
        $columns = [];

        if (preg_match_all('/->(?:where|orWhere|whereIn|whereNotIn|whereNull|whereNotNull|whereDate|whereBetween|whereYear|whereMonth|orderBy|orderByDesc|groupBy)\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $contents, $matches) !== false) {
            $columns = array_merge($columns, $matches[1]);
        }

        if (preg_match('/->(?:latest|oldest)\(\s*\)/', $contents) === 1) {
            $columns[] = 'created_at';
        }

        if (preg_match_all('/->(?:latest|oldest)\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $contents, $dated) !== false) {
            $columns = array_merge($columns, $dated[1]);
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function columnsOnModel(string $contents, string $class): array
    {
        $columns = [];
        $calls = 'where|orWhere|whereIn|whereNotIn|whereNull|whereNotNull|whereDate|whereBetween|whereYear|whereMonth|orderBy|orderByDesc|groupBy';

        if (preg_match_all('/\b'.preg_quote($class, '/').'::(?:query\(\)\s*->\s*)?(?:'.$calls.')\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $contents, $matches) !== false) {
            $columns = array_merge($columns, $matches[1]);
        }

        if (preg_match('/\b'.preg_quote($class, '/').'::[^;]*->(?:latest|oldest)\(\s*\)/', $contents) === 1) {
            $columns[] = 'created_at';
        }

        if (preg_match_all('/\b'.preg_quote($class, '/').'::[^;]*->(?:latest|oldest)\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $contents, $dated) !== false) {
            $columns = array_merge($columns, $dated[1]);
        }

        return $columns;
    }

    /**
     * @return array<string, list<string>>
     */
    private function qualifiedColumns(string $contents): array
    {
        $found = [];

        if (preg_match_all('/[\'"]([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $contents, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($matches as $match) {
            $found[$match[1]][] = $match[2];
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();

                if (str_contains($path, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                    continue;
                }

                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * @return array{table: string, column: string, source: string}
     */
    private function use(string $table, string $column, string $source): array
    {
        return [
            'table' => $table,
            'column' => $column,
            'source' => $source,
        ];
    }
}
