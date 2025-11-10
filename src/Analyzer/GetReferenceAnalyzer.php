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
use Psr\Log\LoggerInterface;

class GetReferenceAnalyzer implements AnalyzerInterface
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
        private int $threshold = 2,
        /**
         * @readonly
         */
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        //  Article pattern: Use generator instead of accumulating array
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                $simpleSelectQueries = [];
                $queriesExamined     = 0;

                $this->logger?->info('[GetReferenceAnalyzer] Starting analysis...');

                // Detect simple SELECT queries by primary key
                assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $queryData) {
                    ++$queriesExamined;

                    // Debug: log all SELECT queries to see what we're getting
                    if (0 === stripos($queryData->sql, 'SELECT')) {
                        $matches = $this->isSimpleSelectById($queryData->sql);
                        $this->logger?->debug('[GetReferenceAnalyzer] SELECT query', [
                            'sql' => substr($queryData->sql, 0, 100),
                            'matches' => $matches ? 'YES' : 'NO',
                        ]);
                    }

                    // Pattern: SELECT ... FROM table WHERE id = ?
                    // This pattern suggests find() usage that could be getReference()
                    if ($this->isSimpleSelectById($queryData->sql)) {
                        $table = $this->extractTableName($queryData->sql);

                        if (null !== $table) {
                            if (!isset($simpleSelectQueries[$table])) {
                                $simpleSelectQueries[$table] = [];
                            }

                            $simpleSelectQueries[$table][] = $queryData;
                            $this->logger?->info('[GetReferenceAnalyzer] Found simple SELECT by ID', ['table' => $table]);
                        }
                    }
                }

                // Calculate total count across all tables
                $totalCount = array_sum(array_map(function ($queries) {
                    return count($queries);
                }, $simpleSelectQueries));

                $this->logger?->info('[GetReferenceAnalyzer] Summary', [
                    'examined' => $queriesExamined,
                    'matched' => $totalCount,
                    'threshold' => $this->threshold,
                    'tables' => array_keys($simpleSelectQueries),
                ]);

                // Check if total queries meet threshold (global check)
                if ($totalCount >= $this->threshold) {
                    $this->logger?->info('[GetReferenceAnalyzer] ISSUE DETECTED!', [
                        'count' => $totalCount,
                        'threshold' => $this->threshold,
                    ]);

                    // Collect all queries from all tables
                    $allQueries = [];

                    assert(is_iterable($simpleSelectQueries), '$simpleSelectQueries must be iterable');

                    foreach ($simpleSelectQueries as $simpleSelectQuery) {
                        $allQueries = array_merge($allQueries, $simpleSelectQuery);
                    }

                    $tables    = array_keys($simpleSelectQueries);
                    $tableList = count($tables) > 1
                        ? implode(', ', $tables)
                        : $tables[0];

                    $suggestion = $this->suggestionFactory->createGetReference(
                        entity: 'entities',
                        occurrences: $totalCount,
                    );

                    $issueData = new IssueData(
                        type: 'get_reference',
                        title: sprintf('Inefficient Entity Loading: %d find() queries detected', $totalCount),
                        description: DescriptionHighlighter::highlight(
                            'Detected {count} simple SELECT by ID queries across {tableCount} table(s): {tables}. ' .
                            'Consider using {getReference} instead of {find} when you only need the entity reference for associations (threshold: {threshold})',
                            [
                                'count' => $totalCount,
                                'tableCount' => count($tables),
                                'tables' => $tableList,
                                'getReference' => 'getReference()',
                                'find' => 'find()',
                                'threshold' => $this->threshold,
                            ],
                        ),
                        severity: $suggestion->getMetadata()->severity,
                        suggestion: $suggestion,
                        queries: $allQueries,
                        backtrace: $allQueries[0]->backtrace ?? [],
                    );

                    yield $this->issueFactory->create($issueData);
                } else {
                    $this->logger?->info('[GetReferenceAnalyzer] Below threshold', [
                        'count' => $totalCount,
                        'threshold' => $this->threshold,
                    ]);
                }
            },
        );
    }

    private function isSimpleSelectById(string $sql): bool
    {
        // Exclude queries with JOINs first (those are more complex, not simple find())
        if (1 === preg_match('/\bJOIN\b/i', $sql)) {
            return false;
        }

        // Match patterns for simple SELECT by ID:
        // 1. SELECT ... FROM table t0_ WHERE t0_.id = ?
        // 2. SELECT ... FROM table WHERE id = ?
        // 3. SELECT ... FROM table WHERE id = 123 (literal)
        // 4. SELECT ... FROM table t0_ WHERE t0_.id = 123
        // 5. SELECT ... FROM table alias WHERE alias.id = ?
        // 6. Support for various column names: id, user_id, *_id

        $patterns = [
            // Pattern 1: WITH alias, parameterized (?), standard 'id' column
            // Example: SELECT ... FROM users t0_ WHERE t0_.id = ?
            '/SELECT\s+.*\s+FROM\s+\w+\s+(\w+)\s+WHERE\s+\1\.id\s*=\s*\?/i',

            // Pattern 2: NO alias, parameterized (?), standard 'id' column
            // Example: SELECT ... FROM users WHERE id = ?
            '/SELECT\s+.*\s+FROM\s+\w+\s+WHERE\s+id\s*=\s*\?/i',

            // Pattern 3: WITH alias, LITERAL number, standard 'id' column
            // Example: SELECT ... FROM users t0_ WHERE t0_.id = 123
            '/SELECT\s+.*\s+FROM\s+\w+\s+(\w+)\s+WHERE\s+\1\.id\s*=\s*\d+/i',

            // Pattern 4: NO alias, LITERAL number, standard 'id' column
            // Example: SELECT ... FROM users WHERE id = 123
            '/SELECT\s+.*\s+FROM\s+\w+\s+WHERE\s+id\s*=\s*\d+/i',

            // Pattern 5: WITH alias, parameterized (?), ANY *_id column
            // Example: SELECT ... FROM users t0_ WHERE t0_.user_id = ?
            '/SELECT\s+.*\s+FROM\s+\w+\s+(\w+)\s+WHERE\s+\1\.\w*_?id\s*=\s*\?/i',

            // Pattern 6: NO alias, parameterized (?), ANY *_id column
            // Example: SELECT ... FROM users WHERE user_id = ?
            '/SELECT\s+.*\s+FROM\s+\w+\s+WHERE\s+\w*_?id\s*=\s*\?/i',
        ];

        assert(is_iterable($patterns), '$patterns must be iterable');

        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $sql)) {
                return true;
            }
        }

        return false;
    }

    private function extractTableName(string $sql): ?string
    {
        // Extract table name from FROM clause
        if (1 === preg_match('/FROM\s+([^\s]+)/i', $sql, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
