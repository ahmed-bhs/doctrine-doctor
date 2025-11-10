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

class BulkOperationAnalyzer implements AnalyzerInterface
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
        private int $threshold = 20,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        $bulkOperations = $this->detectBulkOperations($queryDataCollection);

        //  Use generator for memory efficiency
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($bulkOperations) {
                assert(is_iterable($bulkOperations), '$bulkOperations must be iterable');

                foreach ($bulkOperations as $bulkOperation) {
                    if ($bulkOperation['count'] >= $this->threshold) {
                        $suggestion = $this->suggestionFactory->createBatchOperation(
                            table: $bulkOperation['table'],
                            operationCount: $bulkOperation['count'],
                        );

                        $issueData = new IssueData(
                            type: 'bulk_operation',
                            title: sprintf('Inefficient Bulk Operations: %d %s on %s', $bulkOperation['count'], $bulkOperation['type'], $bulkOperation['table']),
                            description: DescriptionHighlighter::highlight(
                                'Detected {count} individual {type} queries on table {table}. ' .
                                'Use batch operations ({dql}) or iterate with batching for better performance (threshold: {threshold})',
                                [
                                    'count' => $bulkOperation['count'],
                                    'type' => $bulkOperation['type'],
                                    'table' => $bulkOperation['table'],
                                    'dql' => 'DQL UPDATE/DELETE',
                                    'threshold' => $this->threshold,
                                ],
                            ),
                            severity: $suggestion->getMetadata()->severity,
                            suggestion: $suggestion,
                            queries: $bulkOperation['queries'],
                            backtrace: $bulkOperation['backtrace'] ?? null,
                        );

                        yield $this->issueFactory->create($issueData);
                    }
                }
            },
        );
    }

    /**
     * @return list<array{type: string, table: string, count: int, total_time: float, backtrace: array<int, array<string, mixed>>|null, queries: array<QueryData>}>
     */
    private function detectBulkOperations(QueryDataCollection $queryDataCollection): array
    {
        $operations          = [];
        $updateDeleteQueries = [];

        // Group UPDATE/DELETE queries by table and pattern
        assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

        foreach ($queryDataCollection as $index => $queryData) {
            // Detect UPDATE queries
            if (1 === preg_match('/^UPDATE\s+(\w+)\s+.*WHERE\s+/i', $queryData->sql, $matches)) {
                $table = $matches[1];
                $type  = 'UPDATE';

                $key = $type . '_' . $table;

                if (!isset($updateDeleteQueries[$key])) {
                    $updateDeleteQueries[$key] = [
                        'type'    => $type,
                        'table'   => $table,
                        'queries' => [],
                        'indices' => [],
                    ];
                }

                $updateDeleteQueries[$key]['queries'][] = $queryData;
                $updateDeleteQueries[$key]['indices'][] = $index;
            }

            // Detect DELETE queries
            if (1 === preg_match('/^DELETE\s+FROM\s+(\w+)\s+.*WHERE\s+/i', $queryData->sql, $matches)) {
                $table = $matches[1];
                $type  = 'DELETE';

                $key = $type . '_' . $table;

                if (!isset($updateDeleteQueries[$key])) {
                    $updateDeleteQueries[$key] = [
                        'type'    => $type,
                        'table'   => $table,
                        'queries' => [],
                        'indices' => [],
                    ];
                }

                $updateDeleteQueries[$key]['queries'][] = $queryData;
                $updateDeleteQueries[$key]['indices'][] = $index;
            }
        }

        // Analyze patterns
        assert(is_iterable($updateDeleteQueries), '$updateDeleteQueries must be iterable');

        foreach ($updateDeleteQueries as $updateDeleteQuery) {
            if (count($updateDeleteQuery['queries']) >= $this->threshold) {
                // Check if similar operations (likely in a loop)
                $isBulkOperation = $this->isBulkOperation($updateDeleteQuery['queries']);

                if ($isBulkOperation) {
                    $totalTime = array_sum(
                        array_map(fn (QueryData $queryData): float => $queryData->executionTime->inMilliseconds(), $updateDeleteQuery['queries']),
                    );

                    $operations[] = [
                        'type'       => $updateDeleteQuery['type'],
                        'table'      => $updateDeleteQuery['table'],
                        'count'      => count($updateDeleteQuery['queries']),
                        'total_time' => $totalTime,
                        'backtrace'  => $updateDeleteQuery['queries'][0]->backtrace,
                        'queries'    => array_slice($updateDeleteQuery['queries'], 0, 20),
                    ];
                }
            }
        }

        return $operations;
    }

    /**
     * @param QueryData[] $queries
     */
    private function isBulkOperation(array $queries): bool
    {
        if (count($queries) < 2) {
            return false;
        }

        // Check if queries have similar structure (normalize the SQL)
        $patterns = [];

        assert(is_iterable($queries), '$queries must be iterable');

        foreach ($queries as $query) {
            // Normalize by removing values
            $normalized = preg_replace('/=\s*[\'"]?[^\'"\s,)]+[\'"]?/', '= ?', $query->sql);
            $normalized = preg_replace('/\s+/', ' ', (string) $normalized);
            $patterns[] = $normalized;
        }

        // If most queries have the same pattern, it's a bulk operation
        $uniquePatterns  = array_unique($patterns);
        $similarityRatio = count($uniquePatterns) / count($patterns);

        // If > 70% of queries are similar, consider it bulk
        return $similarityRatio <= 0.3;
    }
}
