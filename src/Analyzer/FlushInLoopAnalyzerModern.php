<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;

/**
 * VERSION: FlushInLoopAnalyzer using the new architecture.
 * Key improvements over the old version:
 * - Uses SuggestionFactory instead of creating suggestions directly
 * - No hardcoded suggestion logic
 * - Better separation of concerns
 * - Easier to test and maintain
 * - Type-safe factory methods
 * Compare this with the original FlushInLoopAnalyzer.php to see the benefits!
 */
class FlushInLoopAnalyzerModern implements AnalyzerInterface
{
    public function __construct(
        /**
         * @readonly
         */
        private IssueFactoryInterface $issueFactory,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
        /**
         * @readonly
         */
        private int $flushCountThreshold = 5,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        //  Article pattern: Use generator instead of array
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                $queriesArray  = iterator_to_array($queryDataCollection);
                $flushPatterns = $this->detectFlushPatterns($queriesArray);

                assert(is_iterable($flushPatterns), '$flushPatterns must be iterable');

                foreach ($flushPatterns as $flushPattern) {
                    if ($flushPattern['flush_count'] >= $this->flushCountThreshold) {
                        //  NEW: Use factory to create suggestion
                        // No need to know about suggestion internals!
                        $suggestion = $this->suggestionFactory->createFlushInLoop(
                            flushCount: $flushPattern['flush_count'],
                            operationsBetweenFlush: $flushPattern['operations_between_flush'],
                        );

                        $issueData = new IssueData(
                            type: 'flush_in_loop',
                            title: sprintf('Performance Anti-Pattern: %d flush() calls in loop', $flushPattern['flush_count']),
                            description: sprintf(
                                'Detected %d flush() calls with an average of %.1f operations between each flush. ' .
                                'This anti-pattern causes severe performance degradation. Batch operations and flush once (threshold: %d)',
                                $flushPattern['flush_count'],
                                $flushPattern['operations_between_flush'],
                                $this->flushCountThreshold,
                            ),
                            severity: $suggestion->getMetadata()->severity, //  NEW: Get severity from suggestion
                            suggestion: $suggestion,
                            queries: $flushPattern['queries'],
                            backtrace: $flushPattern['backtrace'] ?? null,
                        );

                        yield $this->issueFactory->create($issueData);
                    }
                }
            },
        );
    }

    /**
     * @param QueryData[] $queries
     */
    private function detectFlushPatterns(array $queries): array
    {
        $insertUpdateGroups = $this->detectFlushGroups($queries);

        if ($this->hasInsufficientFlushGroups($insertUpdateGroups)) {
            return [];
        }

        $pattern = $this->analyzeFlushGroups($insertUpdateGroups, $queries);

        return null !== $pattern ? [$pattern] : [];
    }

    /**
     * Check if there are enough flush groups to analyze.
     * @param array<array{start_index: int, end_index: int, operations_between_flush: int}> $flushGroups
     */
    private function hasInsufficientFlushGroups(array $flushGroups): bool
    {
        return count($flushGroups) < $this->flushCountThreshold;
    }

    /**
     * Detect flush groups by identifying flush boundaries.
     * @param QueryData[] $queries
     * @return array<array{start_index: int, end_index: int, operations_between_flush: int}>
     */
    private function detectFlushGroups(array $queries): array
    {
        $insertUpdateGroups       = [];
        $lastFlushIndex           = -1;
        $operationsSinceLastFlush = 0;

        assert(is_iterable($queries), '$queries must be iterable');

        foreach ($queries as $index => $queryData) {
            assert(is_int($index), 'Array index must be int');

            if ($queryData->isInsert() || $queryData->isUpdate() || $queryData->isDelete()) {
                ++$operationsSinceLastFlush;
            }

            if ($this->isPotentialFlushBoundary($queries, $index)) {
                if ($lastFlushIndex >= 0) {
                    $insertUpdateGroups[] = [
                        'start_index'              => $lastFlushIndex,
                        'end_index'                => $index,
                        'operations_between_flush' => $operationsSinceLastFlush,
                    ];
                }

                $lastFlushIndex           = $index;
                $operationsSinceLastFlush = 0;
            }
        }

        return $insertUpdateGroups;
    }

    /**
     * Analyze flush groups to detect loop patterns.
     * @param array<array{start_index: int, end_index: int, operations_between_flush: int}> $flushGroups
     * @param QueryData[]                                                                    $queries
     * @return array<string, mixed>|null
     */
    private function analyzeFlushGroups(array $flushGroups, array $queries): ?array
    {
        $avgOperationsBetweenFlush = array_sum(array_column($flushGroups, 'operations_between_flush'))
                                    / count($flushGroups);

        if ($avgOperationsBetweenFlush <= 0 || $avgOperationsBetweenFlush > 10) {
            return null;
        }

        [$affectedQueries, $totalTime] = $this->collectAffectedQueries($flushGroups, $queries);

        return [
            'flush_count'              => count($flushGroups),
            'total_time'               => $totalTime,
            'operations_between_flush' => round($avgOperationsBetweenFlush, 1),
            'backtrace'                => $affectedQueries[0]->backtrace ?? null,
            'queries'                  => array_slice($affectedQueries, 0, 20),
        ];
    }

    /**
     * Collect affected queries from flush groups.
     * @param array<array{start_index: int, end_index: int, operations_between_flush: int}> $flushGroups
     * @param QueryData[]                                                                    $queries
     * @return array{QueryData[], float}
     */
    private function collectAffectedQueries(array $flushGroups, array $queries): array
    {
        $affectedQueries = [];
        $totalTime       = 0;

        assert(is_iterable($flushGroups), '$flushGroups must be iterable');

        foreach ($flushGroups as $flushGroup) {
            for ($i = $flushGroup['start_index']; $i <= $flushGroup['end_index']; ++$i) {
                if (isset($queries[$i])) {
                    $affectedQueries[] = $queries[$i];
                    $totalTime += $queries[$i]->executionTime->inMilliseconds();
                }
            }
        }

        return [$affectedQueries, $totalTime];
    }

    /**
     * @param QueryData[] $queries
     */
    private function isPotentialFlushBoundary(array $queries, int $currentIndex): bool
    {
        if (!isset($queries[$currentIndex], $queries[$currentIndex + 1])) {
            return false;
        }

        $current = $queries[$currentIndex];
        $next    = $queries[$currentIndex + 1];

        // Pattern 1: INSERT/UPDATE followed by SELECT
        if (($current->isInsert() || $current->isUpdate()) && $next->isSelect()) {
            return true;
        }

        // Pattern 2: Backtrace changes (indicates new loop iteration or function call)
        if (null !== $current->backtrace && null !== $next->backtrace) {
            $currentTrace = $this->getTopBacktraceFrame($current->backtrace);
            $nextTrace    = $this->getTopBacktraceFrame($next->backtrace);

            if ($currentTrace !== $nextTrace) {
                return true;
            }
        }

        return false;
    }

    private function getTopBacktraceFrame(?array $backtrace): string
    {
        if (null === $backtrace || [] === $backtrace || !is_array($backtrace)) {
            return '';
        }

        assert(is_iterable($backtrace), '$backtrace must be iterable');

        foreach ($backtrace as $frame) {
            if (isset($frame['file'], $frame['line'])) {
                return $frame['file'] . ':' . $frame['line'];
            }
        }

        return '';
    }
}
