<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Sync;

final class SyncEstimate
{
    /**
     * @param  list<array{table?: string, source: int}>  $tables
     * @return array{rows: int, tables: int, label: string, note: string, basis: string}
     */
    public function build(array $tables, ?float $previousRowsPerSecond, ?float $sampleRowsPerSecond): array
    {
        $rows = 0;

        foreach ($tables as $table) {
            $rows += max(0, (int) ($table['source'] ?? 0));
        }

        $tableCount = count($tables);

        if ($rows === 0) {
            return [
                'rows' => 0,
                'tables' => $tableCount,
                'label' => 'No rows to copy.',
                'note' => '',
                'basis' => 'empty',
            ];
        }

        $counts = number_format($rows).' '.($rows === 1 ? 'row' : 'rows')
            .' across '.number_format($tableCount).' '.($tableCount === 1 ? 'table' : 'tables');

        if ($previousRowsPerSecond !== null && $previousRowsPerSecond > 0) {
            $seconds = $rows / $previousRowsPerSecond;

            return [
                'rows' => $rows,
                'tables' => $tableCount,
                'label' => $counts.'. Estimated '.$this->span($seconds * 0.75, $seconds * 1.25).'.',
                'note' => 'Based on the speed of the last sync.',
                'basis' => 'previous',
            ];
        }

        if ($sampleRowsPerSecond !== null && $sampleRowsPerSecond > 0) {
            return [
                'rows' => $rows,
                'tables' => $tableCount,
                'label' => $counts.'. Estimated '.$this->span(
                    $rows / $sampleRowsPerSecond * 3,
                    $rows / $sampleRowsPerSecond * 8,
                ).'.',
                'note' => 'Rough estimate from a short read of the largest table. A finished sync replaces it with a measured time.',
                'basis' => 'sample',
            ];
        }

        return [
            'rows' => $rows,
            'tables' => $tableCount,
            'label' => $counts.'. Estimated '.$this->span($rows / 2000, $rows / 400).'.',
            'note' => 'Rough estimate. A finished sync replaces it with a measured time.',
            'basis' => 'rough',
        ];
    }

    public function duration(float $seconds): string
    {
        return $this->phrase(max(0, $seconds));
    }

    /**
     * @param  list<array{source?: int, destination?: int|null}>  $counts
     * @return array{source: int, copied: int, left: int, percent: int}
     */
    public function copyProgress(array $counts): array
    {
        $source = 0;
        $copied = 0;
        $left = 0;

        foreach ($counts as $row) {
            $rowSource = max(0, (int) ($row['source'] ?? 0));
            $source += $rowSource;
            $destination = $row['destination'] ?? null;

            if ($destination === null) {
                $left += $rowSource;

                continue;
            }

            $destination = max(0, (int) $destination);
            $copied += min($rowSource, $destination);
            $left += max(0, $rowSource - $destination);
        }

        return [
            'source' => $source,
            'copied' => $copied,
            'left' => $left,
            'percent' => $source === 0 ? 100 : (int) min(100, round($copied / $source * 100)),
        ];
    }

    private function span(float $minSeconds, float $maxSeconds): string
    {
        $min = $this->phrase($minSeconds);
        $max = $this->phrase($maxSeconds);

        if ($min === $max) {
            return str_starts_with($min, 'under ') ? $min : 'about '.$min;
        }

        return $min.'–'.$max;
    }

    private function phrase(float $seconds): string
    {
        if ($seconds < 45) {
            return 'under a minute';
        }

        if ($seconds < 3600) {
            $minutes = max(1, (int) round($seconds / 60));

            return $minutes.' '.($minutes === 1 ? 'minute' : 'minutes');
        }

        $hours = (int) round($seconds / 3600);

        return max(1, $hours).' '.($hours === 1 ? 'hour' : 'hours');
    }
}
