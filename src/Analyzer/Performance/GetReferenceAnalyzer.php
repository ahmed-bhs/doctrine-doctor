<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\CachedSqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Cache\SqlNormalizationCache;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Psr\Log\LoggerInterface;

class GetReferenceAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    public function __construct(
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly int $threshold = 2,
        private readonly ?LoggerInterface $logger = null,
        private readonly CachedSqlStructureExtractor $sqlExtractor = new CachedSqlStructureExtractor(),
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
                $totalCount = array_sum(array_map(count(...), $simpleSelectQueries));

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
                                'trigger_location' => null,
                            ],
                            suggestionMetadata: new SuggestionMetadata(
                                type: SuggestionType::performance(),
                                severity: Severity::info(),
                                title: 'Use eager loading to avoid lazy loading queries',
                                tags: ['performance', 'lazy-loading', 'eager-loading', 'n+1'],
                            ),
                        );

                        $issueData = new IssueData(
                            type: IssueType::LAZY_LOADING->value,
                            title: sprintf('Lazy Loading Detected: %d queries triggered', $totalCount),
                            description: DescriptionHighlighter::highlight(
                                'Detected {count} lazy loading queries across {tableCount} table(s): {tables}. ' .
                                'These queries are triggered automatically when accessing collections in templates and application code. ' .
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
                        $suggestion = $this->suggestionFactory->createFromTemplate(
                            templateName: 'Performance/get_reference',
                            context: [
                                'entity' => 'entities',
                                'occurrences' => $totalCount,
                            ],
                            suggestionMetadata: new SuggestionMetadata(
                                type: SuggestionType::bestPractice(),
                                severity: Severity::info(),
                                title: sprintf('Use getReference() for %s (%d occurrences)', 'entities', $totalCount),
                                tags: ['best-practice', 'performance', 'doctrine'],
                            ),
                        );

                        $issueData = new IssueData(
                            type: IssueType::GET_REFERENCE->value,
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

        // Only match SELECT by primary key (column named exactly 'id').
        // FK columns like deposit_request_id, eco_organization_id are intentionally excluded —
        // they return collections, not single entities, so getReference() is not applicable.
        $patterns = [
            '/SELECT\s+.*\s+FROM\s+\w+\s+(\w+)\s+WHERE\s+\1\.id\s*=\s*\?/i',
            '/SELECT\s+.*\s+FROM\s+\w+\s+WHERE\s+id\s*=\s*\?/i',
            '/SELECT\s+.*\s+FROM\s+\w+\s+(\w+)\s+WHERE\s+\1\.id\s*=\s*\d+/i',
            '/SELECT\s+.*\s+FROM\s+\w+\s+WHERE\s+id\s*=\s*\d+/i',
        ];

        return array_any($patterns, fn ($pattern) => 1 === preg_match($pattern, $sql));
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
            return 'explicit_find';
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
