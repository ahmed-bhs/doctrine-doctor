<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Service;

use AhmedBhs\DoctrineDoctor\ValueObject\Severity;

/**
 * Calculates issue severity based on actual impact metrics.
 */
final class SeverityCalculator
{
    /**
     * Calculate severity for N+1 query issues.
     */
    public function calculateNPlusOneSeverity(int $queryCount, float $totalTime): Severity
    {
        // Critical: > 100 queries or > 100ms wasted
        if ($queryCount > 100 || $totalTime > 100) {
            return Severity::CRITICAL;
        }

        // Warning: > 10 queries or > 10ms wasted
        if ($queryCount > 10 || $totalTime > 10) {
            return Severity::WARNING;
        }

        // Info: Detectable but minor impact
        return Severity::INFO;
    }

    /**
     * Calculate severity for missing index issues.
     */
    public function calculateMissingIndexSeverity(int $rowsScanned, float $queryTime): Severity
    {
        // Critical: > 100k rows scanned or > 100ms query time
        if ($rowsScanned > 100000 || $queryTime > 100) {
            return Severity::CRITICAL;
        }

        // Warning: > 1k rows scanned or > 10ms query time
        if ($rowsScanned > 1000 || $queryTime > 10) {
            return Severity::WARNING;
        }

        // Info: Minor impact
        return Severity::INFO;
    }

    /**
     * Calculate severity for slow query issues.
     */
    public function calculateSlowQuerySeverity(float $queryTime): Severity
    {
        // Critical: > 100ms - User-noticeable delay
        if ($queryTime > 100) {
            return Severity::CRITICAL;
        }

        // Warning: > 10ms - Worth optimizing
        if ($queryTime > 10) {
            return Severity::WARNING;
        }

        // Info: > threshold but minimal impact
        return Severity::INFO;
    }

    /**
     * Calculate severity for hydration issues.
     */
    public function calculateHydrationSeverity(int $rowCount, ?int $memoryUsage = null): Severity
    {
        // Critical: > 10k rows or > 50MB memory
        if ($rowCount > 10000 || (null !== $memoryUsage && $memoryUsage > 52428800)) {
            return Severity::CRITICAL;
        }

        // Warning: > 1k rows or > 10MB memory
        if ($rowCount > 1000 || (null !== $memoryUsage && $memoryUsage > 10485760)) {
            return Severity::WARNING;
        }

        // Info: Notable but not problematic
        return Severity::INFO;
    }

    /**
     * Calculate severity for frequent query issues.
     */
    public function calculateFrequentQuerySeverity(int $count, float $totalTime): Severity
    {
        // Critical: > 100 executions or > 100ms total
        if ($count > 100 || $totalTime > 100) {
            return Severity::CRITICAL;
        }

        // Warning: > 20 executions or > 20ms total
        if ($count > 20 || $totalTime > 20) {
            return Severity::WARNING;
        }

        // Info: Noticeable pattern
        return Severity::INFO;
    }

    /**
     * Calculate severity for ORDER BY without LIMIT.
     */
    public function calculateOrderByWithoutLimitSeverity(int $rowCount, float $queryTime): Severity
    {
        // Critical: Sorting > 10k rows
        if ($rowCount > 10000) {
            return Severity::CRITICAL;
        }

        // Warning: Sorting > 100 rows or slow
        if ($rowCount > 100 || $queryTime > 50) {
            return Severity::WARNING;
        }

        // Info: Small result set, minor issue
        return Severity::INFO;
    }

    /**
     * Calculate severity for findAll() usage.
     */
    public function calculateFindAllSeverity(int $rowCount, float $queryTime): Severity
    {
        // Critical: Loading > 10k rows
        if ($rowCount > 10000) {
            return Severity::CRITICAL;
        }

        // Warning: Loading > 100 rows or slow
        if ($rowCount > 100 || $queryTime > 50) {
            return Severity::WARNING;
        }

        // Info: Small table, acceptable
        return Severity::INFO;
    }

    /**
     * Check if an issue should be suppressed based on impact.
     * Returns true if the issue is too minor to report.
     * @param array<string, int|float> $metrics
     */
    public function shouldSuppress(string $issueType, array $metrics): bool
    {
        return match ($issueType) {
            'slow_query' => ($metrics['time'] ?? 0) < 10, // Suppress < 10ms
            'missing_index' => ($metrics['rows_scanned'] ?? 0) < 500, // Suppress < 500 rows
            'n_plus_one' => ($metrics['count'] ?? 0) < 3, // Suppress < 3 queries
            'frequent_query' => ($metrics['count'] ?? 0) < 10, // Suppress < 10 executions
            'order_by_without_limit' => ($metrics['rows'] ?? 0) < 50, // Suppress < 50 rows
            'find_all' => ($metrics['rows'] ?? 0) < 100, // Suppress < 100 rows
            default => false,
        };
    }
}
