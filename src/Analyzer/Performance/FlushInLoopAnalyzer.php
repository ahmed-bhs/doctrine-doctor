<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use Webmozart\Assert\Assert;

class FlushInLoopAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
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
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                $flushPatterns = $this->detectFlushPatterns($queryDataCollection);

                Assert::isIterable($flushPatterns, '$flushPatterns must be iterable');

                foreach ($flushPatterns as $flushPattern) {
                    Assert::isArray($flushPattern);
                    $flushCount = $flushPattern['flush_count'] ?? 0;
                    $operationsBetweenFlush = $flushPattern['operations_between_flush'] ?? 0;
                    Assert::integer($flushCount);
                    Assert::numeric($operationsBetweenFlush);

                    if ($flushCount >= $this->flushCountThreshold) {
                        $suggestion = $this->suggestionFactory->createFlushInLoop(
                            flushCount: $flushCount,
                            operationsBetweenFlush: (float) $operationsBetweenFlush,
                        );

                        $queries = $flushPattern['queries'] ?? [];
                        $backtrace = $flushPattern['backtrace'] ?? null;
                        Assert::isArray($queries);

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

        Assert::isIterable($queriesArray, '$queriesArray must be iterable');

        foreach ($queriesArray as $index => $queryData) {
            Assert::integer($index, 'Array index must be int');

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

        Assert::isIterable($flushGroups, '$flushGroups must be iterable');

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

        if (!isset($queries[$currentIndex], $queries[$currentIndex + 1])) {
            return false;
        }

        $current = $queries[$currentIndex];
        $next    = $queries[$currentIndex + 1];

        if (($current->isInsert() || $current->isUpdate()) && $next->isSelect()) {
            return true;
        }


        return false;
    }
}
