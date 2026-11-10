<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

final class JsonFile
{
    /**
     * @return array<string, mixed>|null
     */
    public static function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function write(string $path, array $data): void
    {
        try {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } catch (Throwable $exception) {
            Log::warning('[nyk-db-sync] Could not write a state file', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
