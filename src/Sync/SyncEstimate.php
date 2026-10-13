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
