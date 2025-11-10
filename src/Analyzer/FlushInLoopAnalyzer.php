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
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;

class FlushInLoopAnalyzer implements AnalyzerInterface
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
                $flushPatterns = $this->detectFlushPatterns($queryDataCollection);

                assert(is_iterable($flushPatterns), '$flushPatterns must be iterable');

                foreach ($flushPatterns as $flushPattern) {
                    assert(is_array($flushPattern));
                    $flushCount = $flushPattern['flush_count'] ?? 0;
                    $operationsBetweenFlush = $flushPattern['operations_between_flush'] ?? 0;
                    assert(is_int($flushCount));
                    assert(is_float($operationsBetweenFlush) || is_int($operationsBetweenFlush));

                    if ($flushCount >= $this->flushCountThreshold) {
                        // Use factory to create suggestion (new architecture)
                        $suggestion = $this->suggestionFactory->createFlushInLoop(
                            flushCount: $flushCount,
                            operationsBetweenFlush: (float) $operationsBetweenFlush,
                        );

                        $queries = $flushPattern['queries'] ?? [];
                        $backtrace = $flushPattern['backtrace'] ?? null;
                        assert(is_array($queries));

                        $issueData = new IssueData(
                            type: 'flush_in_loop',
                            title: sprintf('Performance Anti-Pattern: %d flush() calls in loop', $flushCount),
                            description: DescriptionHighlighter::highlight(
                                'Detected {flushCount} {flushMethod} calls with an average of {avgOps} operations between each flush. ' .
                                'This anti-pattern causes severe performance degradation. Batch operations and flush once (threshold: {threshold})',
                                [
                                    'flushCount' => (string) $flushCount,
                                    'flushMethod' => 'flush()',
                                    'avgOps' => sprintf('%.1f', $operationsBetweenFlush),
                                    'threshold' => (string) $this->flushCountThreshold,
                                ],
                            ),
                            severity: $suggestion->getMetadata()->severity,
                            suggestion: $suggestion,
                            queries: $queries,
                            backtrace: $backtrace,
                        );

                        yield $this->issueFactory->create($issueData);
                    }
                }
            },
        );
    }

    /**
     * @return list<array{flush_count: int, operations_between_flush: float|int, queries: array<mixed>, backtrace: mixed}>
     */
    private function detectFlushPatterns(QueryDataCollection $queryDataCollection): array
    {
        $queriesArray       = $queryDataCollection->toArray();
        $insertUpdateGroups = $this->detectFlushGroups($queriesArray);

        if (count($insertUpdateGroups) < $this->flushCountThreshold) {
            return [];
        }

        $pattern = $this->analyzeFlushGroups($insertUpdateGroups, $queriesArray);

        return null !== $pattern ? [$pattern] : [];
    }

    /**
     * Detect flush groups by identifying flush boundaries.
     * @param QueryData[] $queriesArray
     * @return array<array{start_index: int, end_index: int, operations_between_flush: int}>
     */
    private function detectFlushGroups(array $queriesArray): array
    {
        $insertUpdateGroups       = [];
        $lastFlushIndex           = -1;
        $operationsSinceLastFlush = 0;

        assert(is_iterable($queriesArray), '$queriesArray must be iterable');

        foreach ($queriesArray as $index => $queryData) {
            assert(is_int($index), 'Array index must be int');

            if ($queryData->isInsert() || $queryData->isUpdate() || $queryData->isDelete()) {
                ++$operationsSinceLastFlush;
            }

            if ($this->isPotentialFlushBoundary($queriesArray, $index)) {
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
     * @param QueryData[]                                                                    $queriesArray
     * @return array{flush_count: int, operations_between_flush: float|int, queries: array<mixed>, backtrace: mixed}|null
     */
    private function analyzeFlushGroups(array $flushGroups, array $queriesArray): ?array
    {
        if ([] === $flushGroups) {
            return null;
        }

        $avgOperationsBetweenFlush = array_sum(array_column($flushGroups, 'operations_between_flush'))
                                    / count($flushGroups);

        // If operations between flushes are consistent and small (1-10), it's likely flush in a loop
        if ($avgOperationsBetweenFlush <= 0 || $avgOperationsBetweenFlush > 10) {
            return null;
        }

        [$affectedQueries, $totalTime] = $this->collectAffectedQueries($flushGroups, $queriesArray);

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
     * @param QueryData[]                                                                    $queriesArray
     * @return array{QueryData[], float}
     */
    private function collectAffectedQueries(array $flushGroups, array $queriesArray): array
    {
        $affectedQueries = [];
        $totalTime       = 0;

        assert(is_iterable($flushGroups), '$flushGroups must be iterable');

        foreach ($flushGroups as $flushGroup) {
            for ($i = $flushGroup['start_index']; $i <= $flushGroup['end_index']; ++$i) {
                if (isset($queriesArray[$i])) {
                    $affectedQueries[] = $queriesArray[$i];
                    $totalTime += $queriesArray[$i]->executionTime->inMilliseconds();
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
        // Look for patterns that suggest a flush happened:
        // 1. A sequence of INSERT/UPDATE followed by SELECTs (entity refresh)
        // 2. Time gap between queries
        // 3. Change in backtrace location (new iteration)

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

        // Get the first meaningful frame
        assert(is_iterable($backtrace), '$backtrace must be iterable');

        foreach ($backtrace as $frame) {
            if (isset($frame['file'], $frame['line'])) {
                return $frame['file'] . ':' . $frame['line'];
            }
        }

        return '';
    }
}
