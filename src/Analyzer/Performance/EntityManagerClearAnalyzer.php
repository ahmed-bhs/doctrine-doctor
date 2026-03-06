<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Webmozart\Assert\Assert;

class EntityManagerClearAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    private const array MIGRATION_TABLE_PATTERNS = [
        'migration_versions',
        'doctrine_migration_versions',
        'schema_migrations',
        'migrations',
        'flyway_schema_history',
        'changelog',
        'db_changelog',
    ];

    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly int $batchSizeThreshold = 20,
        private readonly SqlStructureExtractor $sqlExtractor = new SqlStructureExtractor(),
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
                Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $index => $queryData) {
                    // Extract table name using SQL parser
                    $table = null;

                    if ($queryData->isInsert()) {
                        $table = $this->sqlExtractor->detectInsertQuery($queryData->sql);
                    } elseif ($queryData->isUpdate()) {
                        $table = $this->sqlExtractor->detectUpdateQuery($queryData->sql);
                    } elseif ($queryData->isDelete()) {
                        $table = $this->sqlExtractor->detectDeleteQuery($queryData->sql);
                    }

                    if (null !== $table) {
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
                Assert::isIterable($insertUpdateQueries, '$insertUpdateQueries must be iterable');

                foreach ($insertUpdateQueries as $table => $tableQueries) {
                    if ($this->isMigrationTable($table)) {
                        continue;
                    }

                    $count = count($tableQueries);

                    if ($count >= $this->batchSizeThreshold) {
                        // Check if queries are in sequence (potential loop)
                        $isSequential = $this->areQueriesSequential($tableQueries);

                        if ($isSequential) {
                            $queryDetails = [];

                            Assert::isIterable($tableQueries, '$tableQueries must be iterable');

                            foreach ($tableQueries as $tableQuery) {
                                $queryData = $tableQuery['query'];
                                $queryDetails[] = $queryData;
                            }

                            $suggestion = $this->suggestionFactory->createFromTemplate(
                                templateName: 'Performance/batch_operation',
                                context: [
                                    'table' => $table,
                                    'operation_count' => $count,
                                ],
                                suggestionMetadata: new SuggestionMetadata(
                                    type: SuggestionType::performance(),
                                    severity: Severity::warning(),
                                    title: sprintf('Memory Leak Risk: %d operations without clear()', $count),
                                    tags: ['performance', 'memory', 'batch'],
                                ),
                            );

                            $issueData = new IssueData(
                                type: IssueType::ENTITY_MANAGER_CLEAR->value,
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

    private function isMigrationTable(string $table): bool
    {
        $lowerTable = strtolower($table);
        return array_any(self::MIGRATION_TABLE_PATTERNS, fn ($pattern) => str_contains($lowerTable, (string) $pattern));
    }

    private function areQueriesSequential(array $queries): bool
    {
        if (count($queries) < 2) {
            return false;
        }

        $indices = array_column($queries, 'index');

        // Check if most queries are within close proximity (allow some gaps)
        $maxGap          = 3;
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
