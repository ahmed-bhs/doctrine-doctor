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
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;

class EntityManagerClearAnalyzer implements AnalyzerInterface
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
        private int $batchSizeThreshold = 20,
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
                $insertUpdateQueries = [];

                // Group INSERT/UPDATE/DELETE queries by table
                assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $index => $queryData) {
                    // Extract table name
                    if (($queryData->isInsert() || $queryData->isUpdate() || $queryData->isDelete()) && 1 === preg_match('/(?:INSERT INTO|UPDATE|DELETE FROM)\s+([^\s(]+)/i', $queryData->sql, $matches)) {
                        $table = $matches[1];

                        if (!isset($insertUpdateQueries[$table])) {
                            $insertUpdateQueries[$table] = [];
                        }

                        $insertUpdateQueries[$table][] = [
                            'query' => $queryData,
                            'index' => $index,
                        ];
                    }
                }

                // Detect potential batch operations without clear()
                assert(is_iterable($insertUpdateQueries), '$insertUpdateQueries must be iterable');

                foreach ($insertUpdateQueries as $table => $tableQueries) {
                    $count = count($tableQueries);

                    if ($count >= $this->batchSizeThreshold) {
                        // Check if queries are in sequence (potential loop)
                        $isSequential = $this->areQueriesSequential($tableQueries);

                        if ($isSequential) {
                            $queryDetails = [];

                            assert(is_iterable($tableQueries), '$tableQueries must be iterable');

                            foreach ($tableQueries as $tableQuery) {
                                $queryData = $tableQuery['query'];
                                $queryDetails[] = $queryData;
                            }

                            $suggestion = $this->suggestionFactory->createBatchOperation(
                                table: $table,
                                operationCount: $count,
                            );

                            $issueData = new IssueData(
                                type: 'entity_manager_clear',
                                title: sprintf('Memory Leak Risk: %d operations on %s', $count, $table),
                                description: DescriptionHighlighter::highlight(
                                    'Detected {count} sequential INSERT/UPDATE/DELETE operations on table {table} without {clearMethod}. ' .
                                    'This can cause memory leaks in batch operations (threshold: {threshold})',
                                    [
                                        'count' => $count,
                                        'table' => $table,
                                        'clearMethod' => 'EntityManager::clear()',
                                        'threshold' => $this->batchSizeThreshold,
                                    ],
                                ),
                                severity: $suggestion->getMetadata()->severity,
                                suggestion: $suggestion,
                                queries: $queryDetails,
                                backtrace: $queryDetails[0]->backtrace,
                            );

                            yield $this->issueFactory->create($issueData);
                        }
                    }
                }
            },
        );
    }

    private function areQueriesSequential(array $queries): bool
    {
        if (count($queries) < 2) {
            return false;
        }

        $indices = array_column($queries, 'index');

        // Check if most queries are within close proximity (allow some gaps)
        $maxGap          = 10; // Allow up to 10 queries between batch operations
        $sequentialCount = 0;
        $counter         = count($indices);

        for ($i = 1; $i < $counter; ++$i) {
            if ($maxGap >= $indices[$i] - $indices[$i - 1]) {
                ++$sequentialCount;
            }
        }

        // Consider sequential if at least 70% of queries are close together
        return ($sequentialCount / (count($indices) - 1)) >= 0.7;
    }
}
