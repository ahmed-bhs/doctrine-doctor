<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collector\Helper;

/**
 * Helper for calculating query time statistics.
 * Extracted from DoctrineDoctorDataCollector to reduce complexity.
 */
final class QueryStatsCalculator
{
    /**
     * Calculate query time statistics.
     */
    public function calculateStats(array $queries): array
    {
        if ([] === $queries) {
            return [];
        }

        $times          = array_map(fn (array $query): float => (float) ($query['executionMS'] ?? 0), $queries);
        $convertedTimes = array_map(fn (float $time): float => $time > 0 && $time < 1 ? $time * 1000 : $time, $times);

        return [
            'total_queries'     => count($queries),
            'min_time_ms'       => round(min($convertedTimes), 4),
            'max_time_ms'       => round(max($convertedTimes), 4),
            'avg_time_ms'       => round(array_sum($convertedTimes) / count($convertedTimes), 4),
            'queries_over_20ms' => count(array_filter($convertedTimes, fn (float $timeMs): bool => $timeMs > 20)),
            'queries_over_50ms' => count(array_filter($convertedTimes, fn (float $timeMs): bool => $timeMs > 50)),
        ];
    }
}
