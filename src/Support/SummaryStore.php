<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SummaryStore
{
    public const CACHE_KEY = 'nyk-db-sync.summary';

    /**
     * @param  array<string, mixed>  $summary
     */
    public function put(array $summary): void
    {
        Cache::put(self::CACHE_KEY, $summary, now()->addDay());

        try {
            $path = $this->path();
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } catch (Throwable $exception) {
            Log::warning('[nyk-db-sync] Could not write the sync summary file', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $path = $this->path();

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

    public function path(): string
    {
        return storage_path('app/nyk-db-sync/last-sync.json');
    }
}
