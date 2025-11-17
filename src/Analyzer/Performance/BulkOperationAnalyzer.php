<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Cache\SqlNormalizationCache;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

class BulkOperationAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    private SqlStructureExtractor $sqlExtractor;

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
        /**
         * @readonly
         */
        private ?LoggerInterface $logger = null,
        ?SqlStructureExtractor $sqlExtractor = null,
    ) {
        $this->sqlExtractor = $sqlExtractor ?? new SqlStructureExtractor();
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        $this->logger?->info('[BulkOperationAnalyzer] Starting analysis...');
        $bulkOperations = $this->detectBulkOperations($queryDataCollection);

        $this->logger?->info('[BulkOperationAnalyzer] Detected ' . count($bulkOperations) . ' bulk operations');

        //  Use generator for memory efficiency
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($bulkOperations) {
                Assert::isIterable($bulkOperations, '$bulkOperations must be iterable');

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

        $this->logger?->info('[BulkOperationAnalyzer] detectBulkOperations() called');

        // Group UPDATE/DELETE queries by table and pattern
        Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

        $queryCount = 0;
        foreach ($queryDataCollection as $index => $queryData) {
            $queryCount++;

            // OPTIMIZATION: Fast string check before expensive SQL parsing
            // This reduces parsing from O(n) to O(m) where m << n (only UPDATE/DELETE queries)
            $sqlUpper = strtoupper(substr($queryData->sql, 0, 10));
            $isUpdateQuery = str_starts_with($sqlUpper, 'UPDATE ');
            $isDeleteQuery = str_starts_with($sqlUpper, 'DELETE ');

            // Skip queries that are neither UPDATE nor DELETE (majority of queries)
            if (!$isUpdateQuery && !$isDeleteQuery) {
                continue;
            }

            // Detect UPDATE queries using SQL parser
            if ($isUpdateQuery) {
                $updateTable = $this->sqlExtractor->detectUpdateQuery($queryData->sql);
                if (null !== $updateTable) {
                    $this->logger?->info('[BulkOperationAnalyzer] Found UPDATE on table: ' . $updateTable);
                    $table = $updateTable;
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
            }

            // Detect DELETE queries using SQL parser
            $deleteTable = null;
            if ($isDeleteQuery) {
                $deleteTable = $this->sqlExtractor->detectDeleteQuery($queryData->sql);
            }
            if (null !== $deleteTable) {
                $table = $deleteTable;
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

        $this->logger?->info('[BulkOperationAnalyzer] Examined ' . $queryCount . ' queries total');
        $this->logger?->info('[BulkOperationAnalyzer] Found ' . count($updateDeleteQueries) . ' unique UPDATE/DELETE patterns');

        // Analyze patterns
        Assert::isIterable($updateDeleteQueries, '$updateDeleteQueries must be iterable');

        foreach ($updateDeleteQueries as $updateDeleteQuery) {
            $count = count($updateDeleteQuery['queries']);

            $this->logger?->info('[BulkOperationAnalyzer] Checking ' . $updateDeleteQuery['type'] . ' on ' . $updateDeleteQuery['table'] . ': ' . $count . ' queries (threshold: ' . $this->threshold . ')');

            if ($count >= $this->threshold) {
                // Check if similar operations (likely in a loop)
                $isBulkOperation = $this->isBulkOperation($updateDeleteQuery['queries']);

                $this->logger?->info('[BulkOperationAnalyzer] isBulkOperation() returned: ' . ($isBulkOperation ? 'TRUE' : 'FALSE'));

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

        // Check if queries have similar structure using SQL parser
        $patterns = [];

        Assert::isIterable($queries, '$queries must be iterable');

        foreach ($queries as $query) {
            // Use SQL parser to normalize query pattern
            // Use universal normalization method shared across all analyzers
            $normalized = SqlNormalizationCache::normalize($query->sql);
            $patterns[] = $normalized;
        }

        // If most queries have the same pattern, it's a bulk operation
        $uniquePatterns  = array_unique($patterns);
        $similarityRatio = count($uniquePatterns) / count($patterns);

        // If > 70% of queries are similar, consider it bulk
        return $similarityRatio <= 0.3;
    }
}
