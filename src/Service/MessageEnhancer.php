<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Service;

/**
 * Enhances issue messages with contextual information.
 */
final class MessageEnhancer
{
    /**
     * Enhance N+1 query message with impact metrics.
     */
    public function enhanceNPlusOneMessage(
        string $baseMessage,
        int $queryCount,
        float $totalTime,
        ?string $entityName = null,
    ): string {
        $parts = [$baseMessage];

        if (null !== $entityName && '' !== $entityName) {
            $parts[] = sprintf('Entity: %s', $entityName);
        }

        $parts[] = sprintf('Impact: %d queries, %.2fms total time', $queryCount, $totalTime);

        // Add severity context
        if ($queryCount > 100) {
            $parts[] = 'CRITICAL: This is causing significant performance degradation';
        } elseif ($queryCount > 20) {
            $parts[] = 'HIGH: This should be addressed soon';
        }

        return implode("\n", $parts);
    }

    /**
     * Enhance missing index message with scan metrics.
     */
    public function enhanceMissingIndexMessage(
        string $baseMessage,
        int $rowsScanned,
        float $queryTime,
        ?string $tableName = null,
    ): string {
        $parts = [$baseMessage];

        if (null !== $tableName && '' !== $tableName) {
            $parts[] = sprintf('Table: %s', $tableName);
        }

        $parts[] = sprintf(
            'Impact: %s rows scanned, %.2fms query time',
            number_format($rowsScanned),
            $queryTime,
        );

        // Add context based on impact
        if ($rowsScanned > 100000) {
            $parts[] = 'CRITICAL: Full table scan on large table - add index immediately';
        } elseif ($rowsScanned > 10000) {
            $parts[] = 'HIGH: Consider adding an index to improve performance';
        } elseif ($rowsScanned > 1000) {
            $parts[] = 'ℹ️ MEDIUM: Index could improve performance as table grows';
        }

        return implode("\n", $parts);
    }

    /**
     * Enhance slow query message with timing context.
     */
    public function enhanceSlowQueryMessage(
        string $baseMessage,
        float $queryTime,
        ?int $rowCount = null,
    ): string {
        $parts = [$baseMessage];

        $parts[] = sprintf('Query time: %.2fms', $queryTime);

        if (null !== $rowCount) {
            $parts[] = sprintf('Rows returned: %s', number_format($rowCount));
        }

        // Add UX context
        if ($queryTime > 100) {
            $parts[] = 'CRITICAL: User-noticeable delay (>100ms)';
        } elseif ($queryTime > 50) {
            $parts[] = 'HIGH: Starting to impact user experience';
        } elseif ($queryTime > 10) {
            $parts[] = 'ℹ️ MEDIUM: Worth optimizing for better performance';
        }

        return implode("\n", $parts);
    }

    /**
     * Enhance ORDER BY without LIMIT message with row count.
     */
    public function enhanceOrderByWithoutLimitMessage(
        string $baseMessage,
        int $rowCount,
        float $queryTime,
    ): string {
        $parts = [$baseMessage];

        $parts[] = sprintf(
            'Sorting %s rows (%.2fms)',
            number_format($rowCount),
            $queryTime,
        );

        if ($rowCount > 10000) {
            $parts[] = 'CRITICAL: Sorting huge dataset without LIMIT';
            $parts[] = 'Recommendation: Add LIMIT or add pagination';
        } elseif ($rowCount > 1000) {
            $parts[] = 'HIGH: Large sort operation';
            $parts[] = 'Recommendation: Consider adding LIMIT if you don\'t need all results';
        } elseif ($rowCount > 100) {
            $parts[] = 'ℹ️ MEDIUM: Moderate sort operation';
            $parts[] = 'Recommendation: Add LIMIT if appropriate';
        } else {
            $parts[] = sprintf('ℹ️ LOW: Small result set (%d rows), acceptable', $rowCount);
        }

        return implode("\n", $parts);
    }

    /**
     * Enhance findAll() message with row count and context.
     */
    public function enhanceFindAllMessage(
        string $baseMessage,
        int $rowCount,
        float $queryTime,
        ?string $entityName = null,
    ): string {
        $parts = [$baseMessage];

        if (null !== $entityName && '' !== $entityName) {
            $parts[] = sprintf('Entity: %s', $entityName);
        }

        $parts[] = sprintf(
            'Loading %s rows (%.2fms)',
            number_format($rowCount),
            $queryTime,
        );

        if ($rowCount > 10000) {
            $parts[] = 'CRITICAL: Loading huge dataset without criteria';
            $parts[] = 'Recommendation: Use findBy() with criteria or add pagination';
        } elseif ($rowCount > 1000) {
            $parts[] = 'HIGH: Loading large dataset';
            $parts[] = 'Recommendation: Consider adding filters or pagination';
        } elseif ($rowCount > 100) {
            $parts[] = 'ℹ️ MEDIUM: Moderate dataset';
            $parts[] = 'Recommendation: Add pagination if this is user-facing';
        } else {
            $parts[] = sprintf('ℹ️ LOW: Small table (%d rows), acceptable for now', $rowCount);
            $parts[] = 'Note: Monitor as table grows';
        }

        return implode("\n", $parts);
    }

    /**
     * Enhance hydration message with memory impact.
     */
    public function enhanceHydrationMessage(
        string $baseMessage,
        int $rowCount,
        ?int $memoryUsage = null,
    ): string {
        $parts = [$baseMessage];

        $parts[] = sprintf('Hydrating %s entities', number_format($rowCount));

        if (null !== $memoryUsage) {
            $memoryMB = $memoryUsage / 1024 / 1024;
            $parts[] = sprintf('Memory usage: %.2f MB', $memoryMB);

            if ($memoryMB > 50) {
                $parts[] = 'CRITICAL: High memory consumption';
            } elseif ($memoryMB > 20) {
                $parts[] = 'HIGH: Significant memory usage';
            }
        }

        if ($rowCount > 10000) {
            $parts[] = 'Recommendation: Use batch processing or DTO hydration';
        } elseif ($rowCount > 5000) {
            $parts[] = 'Recommendation: Consider batch processing or limiting results';
        }

        return implode("\n", $parts);
    }

    /**
     * Enhance frequent query message with execution statistics.
     */
    public function enhanceFrequentQueryMessage(
        string $baseMessage,
        int $count,
        float $totalTime,
        float $avgTime,
    ): string {
        $parts = [$baseMessage];

        $parts[] = sprintf(
            'Executed %d times: %.2fms total, %.2fms average',
            $count,
            $totalTime,
            $avgTime,
        );

        if ($count > 100 || $totalTime > 100) {
            $parts[] = 'HIGH: Consider query result caching';
        } elseif ($count > 50 || $totalTime > 50) {
            $parts[] = 'ℹ️ MEDIUM: Query caching might help';
        } else {
            $parts[] = 'ℹ️ LOW: Frequent but low impact';
        }

        return implode("\n", $parts);
    }
}
