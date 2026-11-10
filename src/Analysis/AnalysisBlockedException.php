<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Analysis;

use RuntimeException;

final class AnalysisBlockedException extends RuntimeException
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $blocked  unresolved blocking issues by table
     */
    public function __construct(public readonly array $blocked)
    {
        $parts = [];

        foreach ($blocked as $table => $issues) {
            $parts[] = $table.' ('.count($issues).')';
        }

        parent::__construct(
            'Sync blocked by unresolved analysis issues in: '.implode(', ', $parts)
            .'. Run php artisan nyk-db-sync:analyze to fix them, accept them, or pass --skip-analysis.',
        );
    }
}
