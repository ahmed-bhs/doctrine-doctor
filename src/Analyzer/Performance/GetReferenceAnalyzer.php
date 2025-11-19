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
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

class GetReferenceAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
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
        private int $threshold = 2,
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
                Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

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

                    Assert::isIterable($simpleSelectQueries, '$simpleSelectQueries must be iterable');

                    foreach ($simpleSelectQueries as $simpleSelectQuery) {
                        $allQueries = array_merge($allQueries, $simpleSelectQuery);
                    }

                    // Group identical queries to avoid showing duplicates in profiler
                    $groupedQueries = $this->groupIdenticalQueries($allQueries);

                    // Create representative sample: take first query from each unique pattern
                    $representativeQueries = [];
                    foreach ($groupedQueries as $queries) {
                        $representativeQueries[] = $queries[0]; // Only show one example per pattern
                    }

                    $tables    = array_keys($simpleSelectQueries);
                    $tableList = count($tables) > 1
                        ? implode(', ', $tables)
                        : $tables[0];

                    // Detect the context: lazy loading or explicit find()
                    $context = $this->detectContext($allQueries);

                    if ('lazy_loading' === $context) {
                        // Lazy loading detected - recommend eager loading
                        $suggestion = $this->suggestionFactory->createFromTemplate(
                            templateName: 'Performance/eager_loading',
                            context: [
                                'entity' => 'Entity',
                                'relation' => 'relation',
                                'query_count' => $totalCount,
                            ],
                            suggestionMetadata: new SuggestionMetadata(
                                type: SuggestionType::performance(),
                                severity: Severity::info(),
                                title: 'Use eager loading to avoid lazy loading queries',
                                tags: ['performance', 'lazy-loading', 'eager-loading', 'n+1'],
                            ),
                        );

                        $issueData = new IssueData(
                            type: 'lazy_loading',
                            title: sprintf('Lazy Loading Detected: %d queries triggered', $totalCount),
                            description: DescriptionHighlighter::highlight(
                                'Detected {count} lazy loading queries across {tableCount} table(s): {tables}. ' .
                                'These queries are triggered automatically when accessing collections in templates or code. ' .
                                'Consider using {eagerLoading} in your repository queries to load related entities upfront and avoid N+1 problems (threshold: {threshold})',
                                [
                                    'count' => $totalCount,
                                    'tableCount' => count($tables),
                                    'tables' => $tableList,
                                    'eagerLoading' => 'eager loading (JOIN + addSelect)',
                                    'threshold' => $this->threshold,
                                ],
                            ),
                            severity: $suggestion->getMetadata()->severity,
                            suggestion: $suggestion,
                            queries: $representativeQueries,
                            backtrace: $allQueries[0]->backtrace ?? [],
                        );
                    } else {
                        // Explicit find() - recommend getReference()
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
                            queries: $representativeQueries,
                            backtrace: $allQueries[0]->backtrace ?? [],
                        );
                    }

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
        // Use SQL parser for robust JOIN detection
        if ($this->sqlExtractor->hasJoins($sql)) {
            return false;
        }

        // Exclude queries with additional WHERE conditions (business logic filters)
        // Example: WHERE id = ? AND state = ? AND channel_id = ?
        // getReference() cannot handle these additional conditions
        // Use SQL parser to detect complex WHERE (multiple conditions with AND/OR)
        if ($this->sqlExtractor->hasComplexWhereConditions($sql)) {
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

        Assert::isIterable($patterns, '$patterns must be iterable');

        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $sql)) {
                return true;
            }
        }

        return false;
    }

    private function extractTableName(string $sql): ?string
    {
        // Extract table name from FROM clause using SQL parser
        $mainTable = $this->sqlExtractor->extractMainTable($sql);

        return $mainTable['table'] ?? null;
    }

    /**
     * Group identical queries together to avoid showing duplicates in profiler.
     * Returns array where key is normalized query pattern, value is array of QueryData.
     *
     * @param array<QueryData> $queries
     * @return array<string, array<QueryData>>
     */
    private function groupIdenticalQueries(array $queries): array
    {
        $groups = [];

        Assert::isIterable($queries, '$queries must be iterable');

        foreach ($queries as $query) {
            // Normalize query to group identical patterns
            $normalized = $this->normalizeQueryForGrouping($query->sql);

            if (!isset($groups[$normalized])) {
                $groups[$normalized] = [];
            }

            $groups[$normalized][] = $query;
        }

        return $groups;
    }

    /**
     * Normalize query for grouping identical patterns.
     * Removes parameter differences to group similar queries.
     */
    /**
     * Normalizes query using universal SQL parser method for grouping.
     *
     * Migration from regex to SQL Parser:
     * - Replaced regex whitespace normalization with SqlStructureExtractor::normalizeQuery()
     * - More robust: properly parses SQL structure
     * - Handles complex queries, subqueries, joins
     * - Fallback to regex if parser fails
     */
    private function normalizeQueryForGrouping(string $sql): string
    {
        // Use universal normalization
        $normalized = SqlNormalizationCache::normalize($sql);

        // Replace all ? placeholders with standard placeholder for grouping
        $normalized = str_replace('?', 'PARAM', $normalized);

        // Uppercase for case-insensitive comparison
        return strtoupper($normalized);
    }

    /**
     * Detect the context of queries from backtrace.
     * Returns 'lazy_loading' if queries are triggered by lazy loading,
     * 'explicit_find' if they are explicit find() calls.
     *
     * @param array<QueryData> $queries
     * @return string 'lazy_loading' or 'explicit_find'
     */
    private function detectContext(array $queries): string
    {
        // Analyze backtrace of first query (representative)
        $backtrace = $queries[0]->backtrace ?? [];

        if (empty($backtrace)) {
            return 'explicit_find'; // Default if no backtrace
        }

        // FIRST: Check for explicit find() calls
        // These are HIGHER priority than lazy loading indicators
        $explicitFindIndicators = [
            'EntityManager::find',
            'EntityRepository::find',
            'ObjectRepository::find',
        ];

        foreach ($backtrace as $frame) {
            $frameSignature = ($frame['class'] ?? '') . '::' . ($frame['function'] ?? '');

            foreach ($explicitFindIndicators as $indicator) {
                if (false !== stripos($frameSignature, $indicator)) {
                    $this->logger?->info('[GetReferenceAnalyzer] Explicit find() detected', [
                        'frame' => $frameSignature,
                        'indicator' => $indicator,
                    ]);

                    return 'explicit_find';
                }
            }
        }

        // SECOND: Check for lazy loading (only if no explicit find() found)
        $lazyLoadingIndicators = [
            // Collections (OneToMany, ManyToMany)
            'loadOneToManyCollection',
            'loadManyToManyCollection',
            'PersistentCollection::initialize',
            'AbstractLazyCollection::',
            'UnitOfWork::loadCollection',
            'BasicEntityPersister::loadOneToManyCollection',
            'BasicEntityPersister::loadManyToManyCollection',

            // Proxies (ManyToOne, OneToOne)
            'Proxy::__load',
            '__CG__::',  // Doctrine proxy class prefix
        ];

        // Check each frame in backtrace
        foreach ($backtrace as $frame) {
            $frameSignature = ($frame['class'] ?? '') . '::' . ($frame['function'] ?? '');

            foreach ($lazyLoadingIndicators as $indicator) {
                if (false !== stripos($frameSignature, $indicator)) {
                    $this->logger?->info('[GetReferenceAnalyzer] Lazy loading detected', [
                        'frame' => $frameSignature,
                        'indicator' => $indicator,
                    ]);

                    return 'lazy_loading';
                }
            }
        }

        $this->logger?->info('[GetReferenceAnalyzer] Explicit find() detected (default)');

        return 'explicit_find';
    }
}
