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
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Webmozart\Assert\Assert;

/**
 * Detects suboptimal JOIN usage that impacts performance.
 * Common issues:
 * 1. LEFT JOIN on NOT NULL relations (should use INNER JOIN)
 * 2. Too many JOINs in a single query (>6)
 * 3. JOINs without using the joined alias
 * 4. Multiple LEFT JOINs on collections (O(n^m) hydration - use multi-step)
 * 5. Missing indexes on JOIN columns
 * Example:
 * BAD:
 *   SELECT o FROM Order o
 *   LEFT JOIN o.customer c  -- customer is NOT NULL → should be INNER JOIN
 *  GOOD:
 *   SELECT o FROM Order o
 *   INNER JOIN o.customer c  -- 20-30% faster
 *
 * BAD (O(n^m) hydration):
 *   SELECT u, a, s FROM User u
 *   LEFT JOIN u.socialAccounts a  -- collection
 *   LEFT JOIN u.sessions s         -- collection
 *   // 3 accounts × 2 sessions = 6 rows per user!
 *
 * GOOD (multi-step hydration):
 *   // Step 1: SELECT u, a FROM User u LEFT JOIN u.socialAccounts a
 *   // Step 2: SELECT u, s FROM User u LEFT JOIN u.sessions s
 *   // 3 + 2 = 5 rows total
 */
class JoinOptimizationAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
        /**
         * @readonly
         */
        private SqlStructureExtractor $sqlExtractor,
        /**
         * @readonly
         */
        private int $maxJoinsRecommended = 5,
        /**
         * @readonly
         */
        private int $maxJoinsCritical = 8,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                // Note: MIN_QUERY_COUNT check removed to allow analysis of all queries with JOINs
                // Previously this prevented detection when total query count was low,
                // even if individual queries had many JOINs

                $metadataMap = $this->buildMetadataMap();
                $seenIssues  = [];

                Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $query) {
                    $context = $this->extractQueryContext($query);

                    if (!$this->hasJoin($context['sql'])) {
                        continue;
                    }

                    $joins = $this->extractJoins($context['sql']);

                    if ([] === $joins) {
                        continue;
                    }

                    yield from $this->analyzeQueryJoins($context, $joins, $metadataMap, $seenIssues, $query);
                }
            },
        );
    }

    public function getName(): string
    {
        return 'JOIN Optimization Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects suboptimal JOIN usage: LEFT JOIN on NOT NULL, too many JOINs, unused JOINs, multiple collection JOINs';
    }

    /**
     * Extract SQL and execution time from query data.
     * @return array{sql: string, executionTime: float}
     */
    private function extractQueryContext(array|object $query): array
    {
        $sql = is_array($query) ? ($query['sql'] ?? '') : (is_object($query) && property_exists($query, 'sql') ? $query->sql : '');

        $executionTime = 0.0;
        if (is_array($query)) {
            $executionTime = $query['executionMS'] ?? 0;
        } elseif (is_object($query) && property_exists($query, 'executionTime') && null !== $query->executionTime) {
            $executionTime = $query->executionTime->inMilliseconds();
        }

        return [
            'sql'           => $sql,
            'executionTime' => $executionTime,
        ];
    }

    /**
     * Analyze all joins in a query and yield issues.
     * @param array{sql: string, executionTime: float} $context
     * @param array<mixed> $joins
     * @param array<mixed> $metadataMap
     * @param array<string, bool> $seenIssues
     * @return \Generator<PerformanceIssue>
     */
    private function analyzeQueryJoins(array $context, array $joins, array $metadataMap, array &$seenIssues, array|object $query): \Generator
    {
        // Check 1: Too many JOINs
        yield from $this->checkAndYieldTooManyJoins($context, $joins, $seenIssues, $query);

        // Check 2: Multiple collection JOINs (O(n^m) hydration)
        yield from $this->checkAndYieldMultipleCollectionJoins($context, $joins, $metadataMap, $seenIssues, $query);

        // Check 3 & 4: Suboptimal and unused JOINs
        yield from $this->checkAndYieldJoinIssues($context, $joins, $metadataMap, $seenIssues, $query);
    }

    /**
     * Check and yield too many joins issue.
     * @param array{sql: string, executionTime: float} $context
     * @param array<mixed> $joins
     * @param array<string, bool> $seenIssues
     * @return \Generator<PerformanceIssue>
     */
    private function checkAndYieldTooManyJoins(array $context, array $joins, array &$seenIssues, array|object $query): \Generator
    {
        $tooManyJoins = $this->checkTooManyJoins($context['sql'], $joins, $context['executionTime'], $query);

        if ($tooManyJoins instanceof PerformanceIssue) {
            $key = $this->buildIssueKey($tooManyJoins);

            if (!isset($seenIssues[$key])) {
                $seenIssues[$key] = true;
                yield $tooManyJoins;
            }
        }
    }

    /**
     * Check and yield suboptimal and unused join issues.
     * @param array{sql: string, executionTime: float} $context
     * @param array<mixed> $joins
     * @param array<mixed> $metadataMap
     * @param array<string, bool> $seenIssues
     * @return \Generator<PerformanceIssue>
     */
    private function checkAndYieldJoinIssues(array $context, array $joins, array $metadataMap, array &$seenIssues, array|object $query): \Generator
    {
        Assert::isIterable($joins, '$joins must be iterable');

        foreach ($joins as $join) {
            yield from $this->checkAndYieldSuboptimalJoin($join, $metadataMap, $context, $seenIssues, $query);
            yield from $this->checkAndYieldUnusedJoin($join, $context['sql'], $seenIssues, $query);
        }
    }

    /**
     * Check and yield suboptimal join type issue.
     * @param array<mixed> $join
     * @param array<mixed> $metadataMap
     * @param array{sql: string, executionTime: float} $context
     * @param array<string, bool> $seenIssues
     * @return \Generator<PerformanceIssue>
     */
    private function checkAndYieldSuboptimalJoin(array $join, array $metadataMap, array $context, array &$seenIssues, array|object $query): \Generator
    {
        $suboptimalJoin = $this->checkSuboptimalJoinType($join, $metadataMap, $context['sql'], $context['executionTime'], $query);

        if ($suboptimalJoin instanceof PerformanceIssue) {
            $key = $this->buildIssueKey($suboptimalJoin);

            if (!isset($seenIssues[$key])) {
                $seenIssues[$key] = true;
                yield $suboptimalJoin;
            }
        }
    }

    /**
     * Check and yield unused join issue.
     * @param array<mixed> $join
     * @param array<string, bool> $seenIssues
     * @return \Generator<PerformanceIssue>
     */
    private function checkAndYieldUnusedJoin(array $join, string $sql, array &$seenIssues, array|object $query): \Generator
    {
        $unusedJoin = $this->checkUnusedJoin($join, $sql, $query);

        if ($unusedJoin instanceof PerformanceIssue) {
            $key = $this->buildIssueKey($unusedJoin);

            if (!isset($seenIssues[$key])) {
                $seenIssues[$key] = true;
                yield $unusedJoin;
            }
        }
    }

    /**
     * Check and yield multiple collection joins issue.
     * @param array{sql: string, executionTime: float} $context
     * @param array<mixed> $joins
     * @param array<mixed> $metadataMap
     * @param array<string, bool> $seenIssues
     * @return \Generator<PerformanceIssue>
     */
    private function checkAndYieldMultipleCollectionJoins(array $context, array $joins, array $metadataMap, array &$seenIssues, array|object $query): \Generator
    {
        $sql = $context['sql'];

        // DEBUG: Log this call

        // Filter only LEFT JOINs
        $leftJoins = array_filter($joins, fn (array $join) => 'LEFT' === $join['type']);


        if (count($leftJoins) < 2) {
            return; // No issue if less than 2 LEFT JOINs
        }

        // Extract the main FROM table
        $fromTable = $this->extractFromTable($sql, $metadataMap);

        if (null === $fromTable) {
            return; // Can't analyze without knowing the main table
        }


        // Identify which JOINs are on collection-valued associations
        $collectionJoins = [];

        foreach ($leftJoins as $join) {
            Assert::isArray($join, 'Join must be an array');
            $isCollection = $this->isCollectionJoin($join, $metadataMap, $sql, $fromTable);

            if ($isCollection) {
                $collectionJoins[] = $join;
            }
        }


        // Issue only if 2+ collection JOINs
        if (count($collectionJoins) < 2) {
            return;
        }


        $issue = $this->createMultiStepHydrationIssue($context, $collectionJoins, $query);

        if (null !== $issue) {
            $key = $this->buildMultiStepIssueKey($issue);

            if (!isset($seenIssues[$key])) {
                $seenIssues[$key] = true;
                yield $issue;
            } else {
            }
        }
    }

    /**
     * Extract the main FROM table from SQL query.
     *
     * @param string $sql Full SQL query
     * @param array<string, ClassMetadata> $metadataMap
     * @return string|null Table name or null if not found
     */
    private function extractFromTable(string $sql, array $metadataMap): ?string
    {
        $mainTable = $this->sqlExtractor->extractMainTable($sql);

        if (null === $mainTable) {
            return null;
        }

        $tableName = $mainTable['table'];

        // Verify table exists in metadata
        if (!isset($metadataMap[$tableName])) {
            return null;
        }

        return $tableName;
    }

    /**
     * Build unique key for issue deduplication.
     */
    private function buildIssueKey(PerformanceIssue $performanceIssue): string
    {
        $data = $performanceIssue->getData();
        $table = $data['table'] ?? '';
        Assert::string($table, 'Table must be a string');

        return $performanceIssue->getTitle() . '|' . $table;
    }

    /**
     * Build unique key for multi-step hydration issue deduplication.
     */
    private function buildMultiStepIssueKey(PerformanceIssue $performanceIssue): string
    {
        $data = $performanceIssue->getData();
        $tables = $data['tables'] ?? [];
        Assert::isArray($tables, 'Tables must be an array');

        return $performanceIssue->getTitle() . '|' . implode(',', $tables);
    }

    /**
     * Build map of table names to ClassMetadata.
     *
     * @return array<string, ClassMetadata>
     */
    private function buildMetadataMap(): array
    {
        /** @var array<string, ClassMetadata> $map */
        $map                  = [];
        $classMetadataFactory = $this->entityManager->getMetadataFactory();
        $allMetadata          = $classMetadataFactory->getAllMetadata();

        // Metadata is automatically filtered by EntityManagerMetadataDecorator
        foreach ($allMetadata as $classMetadatum) {
            $tableName       = $classMetadatum->getTableName();
            $map[$tableName] = $classMetadatum;
        }

        return $map;
    }

    private function hasJoin(string $sql): bool
    {
        return $this->sqlExtractor->hasJoin($sql);
    }

    /**
     * Extract JOIN information from SQL query using SQL parser.
     *
     * This replaces the previous 46-line regex implementation with a clean,
     * parser-based approach that automatically handles:
     * - JOIN type normalization (LEFT OUTER → LEFT)
     * - Alias extraction (never captures 'ON' as alias)
     * - Table name extraction
     */
    private function extractJoins(string $sql): array
    {
        $parsedJoins = $this->sqlExtractor->extractJoins($sql);

        $joins = [];

        foreach ($parsedJoins as $join) {
            $tableName = $join['table'];
            $alias = $join['alias'];

            // Handle tables without aliases: if table is used directly in query, use table name as alias
            // Example: INNER JOIN sylius_channel_locales ON ... WHERE sylius_channel_locales.channel_id = ?
            if (null === $alias) {
                // Check if table name is used directly in the query (without alias)
                if (1 === preg_match('/\b' . preg_quote($tableName, '/') . '\.\w+/i', $sql)) {
                    // Table is used without alias (e.g., sylius_channel_locales.channel_id)
                    $alias = $tableName;
                }
                // Note: We don't skip joins without alias anymore - they count towards "too many joins"
                // The unused join check will handle them separately
            }

            $joins[] = [
                'type'       => $join['type'],
                'table'      => $tableName,
                'alias'      => $alias,  // Can be null
                'full_match' => $join['type'] . ' JOIN ' . $tableName . (null !== $join['alias'] ? ' ' . $join['alias'] : ''),
            ];
        }

        return $joins;
    }

    /**
     * Check if there are too many JOINs.
     */
    private function checkTooManyJoins(string $sql, array $joins, float $executionTime, array|object $query): ?PerformanceIssue
    {
        $joinCount = count($joins);

        if ($joinCount <= $this->maxJoinsRecommended) {
            return null;
        }

        $severity = $joinCount > $this->maxJoinsCritical ? 'critical' : 'warning';

        // Extract backtrace from query
        $backtrace = $this->extractBacktrace($query);

        $performanceIssue = new PerformanceIssue([
            'query'           => $this->truncateQuery($sql),
            'join_count'      => $joinCount,
            'max_recommended' => $this->maxJoinsRecommended,
            'execution_time'  => $executionTime,
            'queries'         => [$query],
            'backtrace'       => $backtrace,
        ]);

        $performanceIssue->setSeverity($severity);
        $performanceIssue->setTitle(sprintf('Too Many JOINs in Single Query (%d tables)', $joinCount));
        $performanceIssue->setMessage(
            sprintf('Query contains %d JOINs (recommended: ', $joinCount) . $this->maxJoinsRecommended . ' max). ' .
            'This can severely impact performance. Consider splitting into multiple queries or using subqueries.',
        );
        $performanceIssue->setSuggestion($this->createTooManyJoinsSuggestion($joinCount, $sql));

        return $performanceIssue;
    }

    /**
     * Check if JOIN type is suboptimal based on relation nullability.
     *
     * Only flag LEFT JOINs as suboptimal when:
     * 1. The FK is NOT NULL
     * 2. AND it's a ManyToOne join (FK in source table, joining from many to one)
     *
     * Do NOT flag OneToMany/ManyToMany joins (collections) even if FK is NOT NULL,
     * because LEFT JOIN is correct when a parent entity can have 0 children.
     *
     * Example:
     * - User hasMany SocialAccounts (OneToMany)
     * - Query: FROM users LEFT JOIN social_accounts → Correct! User can have 0 accounts
     * - Even though social_accounts.user_id is NOT NULL, LEFT JOIN is the right choice
     */
    private function checkSuboptimalJoinType(
        array $join,
        array $metadataMap,
        string $sql,
        float $executionTime,
        array|object $query,
    ): ?PerformanceIssue {
        $tableName = $join['table'];
        $joinType  = $join['type'];

        // Get metadata for this table
        $metadata = $metadataMap[$tableName] ?? null;

        if (null === $metadata) {
            return null;
        }

        // Check if this is a LEFT JOIN on a NOT NULL relation
        if ('LEFT' === $joinType) {
            // Extract the main FROM table to determine join direction
            $fromTable = $this->extractFromTable($sql, $metadataMap);

            if (null === $fromTable) {
                // Can't determine join direction - skip analysis
                return null;
            }

            // IMPORTANT: Check if this is a collection join (OneToMany/ManyToMany)
            // Collection joins should use LEFT JOIN even if FK is NOT NULL
            $isCollection = $this->isCollectionJoin($join, $metadataMap, $sql, $fromTable);

            if ($isCollection) {
                // This is a collection join → LEFT JOIN is correct
                // Don't flag as suboptimal even if FK is NOT NULL
                return null;
            }

            // Not a collection - check if FK is nullable
            $isNullable = $this->isJoinNullable($join, $sql, $metadata);

            if (false === $isNullable) {
                // LEFT JOIN on ManyToOne with NOT NULL FK → should be INNER JOIN
                return $this->createLeftJoinOnNotNullIssue($join, $metadata, $sql, $executionTime, $query);
            }
        }

        return null;
    }

    /**
     * Determine if a JOIN relation is nullable using SQL parser.
     *
     * Improvements:
     * - Handles multiple associations to the same target entity
     * - Supports composite keys (multiple joinColumns)
     * - More precise matching to avoid false positives
     *
     * Strategy:
     * 1. Extract all column names from ON clause
     * 2. For each association, check if ALL its joinColumns appear in ON clause
     * 3. Return nullable status only if we have a complete match
     *
     * Edge cases handled:
     * - Entity with billingAddress and shippingAddress (both Address entities)
     * - Composite keys (tenant_id + entity_id)
     * - Similar column names (user_id vs parent_user_id)
     */
    private function isJoinNullable(array $join, string $sql, ClassMetadata $classMetadata): ?bool
    {
        // Use SQL parser to extract ON clause
        $onClause = $this->sqlExtractor->extractJoinOnClause($sql, (string) $join['full_match']);

        if (null === $onClause) {
            return null;
        }

        // Extract all column names from ON clause (both sides of conditions)
        // Pattern: table.column or just column
        preg_match_all('/(?:\w+\.)?(\w+)\s*=/', $onClause, $matches);
        $columnsInOnClause = array_map('strtolower', $matches[1] ?? []);

        if ([] === $columnsInOnClause) {
            return null;
        }

        // Look for the best matching association
        $bestMatch = null;
        $bestMatchCount = 0;

        foreach ($classMetadata->getAssociationMappings() as $associationMapping) {
            if (!isset($associationMapping['joinColumns'])) {
                continue;
            }

            Assert::isIterable($associationMapping['joinColumns'], 'joinColumns must be iterable');

            // Check if ALL joinColumns for this association appear in ON clause
            $joinColumns = $associationMapping['joinColumns'];
            $matchedColumns = 0;
            $allNullable = true;

            foreach ($joinColumns as $joinColumn) {
                $columnName = $joinColumn['name'] ?? null;

                if (null === $columnName) {
                    continue;
                }

                $columnNameLower = strtolower($columnName);

                // Check if this column appears in ON clause
                if (in_array($columnNameLower, $columnsInOnClause, true)) {
                    ++$matchedColumns;

                    // Track nullable status - if ANY column is NOT NULL, association is NOT NULL
                    $isNullable = $joinColumn['nullable'] ?? true;
                    if (!$isNullable) {
                        $allNullable = false;
                    }
                }
            }

            // If ALL joinColumns matched, this is our association
            if ($matchedColumns === count($joinColumns) && $matchedColumns > $bestMatchCount) {
                $bestMatchCount = $matchedColumns;
                $bestMatch = $allNullable;
            }
        }

        return $bestMatch;
    }

    private function createLeftJoinOnNotNullIssue(
        array $join,
        ClassMetadata $classMetadata,
        string $sql,
        float $executionTime,
        array|object $query,
    ): PerformanceIssue {
        $entityClass = $classMetadata->getName();
        $tableName   = $join['table'];

        $performanceIssue = new PerformanceIssue([
            'query'          => $this->truncateQuery($sql),
            'join_type'      => 'LEFT',
            'table'          => $tableName,
            'entity'         => $entityClass,
            'execution_time' => $executionTime,
            'queries'        => [$query],
            'backtrace'      => $this->createEntityBacktrace($classMetadata),
        ]);

        $performanceIssue->setSeverity('critical');
        $performanceIssue->setTitle('Suboptimal LEFT JOIN on NOT NULL Relation');
        $performanceIssue->setMessage(
            sprintf("Query uses LEFT JOIN on table '%s' which appears to have a NOT NULL foreign key. ", $tableName) .
            'Using INNER JOIN instead would be 20-30% faster.',
        );
        $performanceIssue->setSuggestion($this->createLeftJoinSuggestion($join, $entityClass, $tableName));

        return $performanceIssue;
    }

    /**
     * Check if JOIN alias is actually used in the query using SQL parser.
     *
     * Migration from regex to SQL Parser:
     * - Replaced regex-based alias usage detection with SqlStructureExtractor::isAliasUsedInQuery()
     * - More robust: checks all query clauses (SELECT, WHERE, GROUP BY, ORDER BY, HAVING, other JOINs)
     * - Properly handles complex queries with subqueries, multiple JOINs
     */
    private function checkUnusedJoin(array $join, string $sql, array|object $query): ?PerformanceIssue
    {
        $alias = $join['alias'];

        // Skip joins without aliases - can't check if they're unused
        // (These still count towards "too many joins" detection)
        if (null === $alias) {
            return null;
        }

        // Use SQL parser to check if alias is used in the query
        // (excluding the JOIN definition itself)
        if (!$this->sqlExtractor->isAliasUsedInQuery($sql, $alias, (string) $join['full_match'])) {
            // Alias not used anywhere
            // Extract backtrace from query object
            $backtrace = $this->extractBacktrace($query);

            $performanceIssue = new PerformanceIssue([
                'query'     => $this->truncateQuery($sql),
                'join_type' => $join['type'],
                'table'     => $join['table'],
                'alias'     => $alias,
                'queries'   => [$query],
                'backtrace' => $backtrace,
            ]);

            $performanceIssue->setSeverity('warning');
            $performanceIssue->setTitle('Unused JOIN Detected');
            $performanceIssue->setMessage(
                sprintf("Query performs %s JOIN on table '%s' (alias '%s') but never uses it. ", $join['type'], $join['table'], $alias) .
                'Remove this JOIN to improve performance.',
            );
            $performanceIssue->setSuggestion($this->createUnusedJoinSuggestion($join));

            return $performanceIssue;
        }

        return null;
    }

    /**
     * Determine if a JOIN is on a collection-valued association (one-to-many or many-to-many).
     *
     * Strategy:
     * 1. Analyze the ON clause to find FK direction
     * 2. If FK is in the RIGHT table → OneToMany (collection)
     * 3. If FK is in the LEFT table → ManyToOne (not a collection)
     *
     * Examples:
     * - "user u LEFT JOIN order o ON u.id = o.user_id" → FK in 'order' → Collection ✅
     * - "order o LEFT JOIN user u ON o.user_id = u.id" → FK in 'order' → NOT collection ❌
     *
     * @param array<string, mixed> $join The JOIN information (type, table, alias)
     * @param array<string, ClassMetadata> $metadataMap Map of table names to metadata
     * @param string $sql The full SQL query (needed to extract ON clause)
     * @param string $fromTable The main table being selected from
     */
    private function isCollectionJoin(array $join, array $metadataMap, string $sql, string $fromTable): bool
    {
        $joinTable = $join['table'];

        if (!is_string($joinTable)) {
            return false;
        }

        $metadata = $metadataMap[$joinTable] ?? null;

        if (null === $metadata) {
            return false;
        }

        return $this->isForeignKeyInJoinedTable($sql, $fromTable, $joinTable, $metadataMap);
    }

    /**
     * Extract ON clause for a specific JOIN from SQL.
     *
     * @param string $sql Full SQL query
     * @param array<string, mixed> $join JOIN info
     */
    private function extractOnClauseForJoin(string $sql, array $join): ?string
    {
        $joinMatch = $join['full_match'] ?? null;

        if (!is_string($joinMatch)) {
            return null;
        }

        return $this->sqlExtractor->extractJoinOnClause($sql, $joinMatch);
    }

    /**
     * Determine if the FK is in the joined table (making it a collection from main table's perspective).
     *
     * Improvements:
     * - Handles composite keys (multiple conditions with AND)
     * - Gracefully handles expressions with functions (LOWER, COALESCE, etc.)
     * - Supports self-referencing relations
     *
     * Logic:
     * - "main.id = joined.fk_id" → FK in joined table → Collection ✅
     * - "main.fk_id = joined.id" → FK in main table → NOT collection ❌
     * - Composite: "main.id1 = joined.fk1 AND main.id2 = joined.fk2" → Collection ✅
     *
     * @param string $onClause The ON clause (e.g., "u0_.id = o1_.user_id")
     * @param string $fromTable Main table name
     * @param string $joinTable Joined table name
     * @param array<string, ClassMetadata> $metadataMap
     */
    private function isForeignKeyInJoinedTable(
        string $sql,
        string $fromTable,
        string $joinTable,
        array $metadataMap,
    ): bool {
        $fromMetadata = $metadataMap[$fromTable] ?? null;
        $joinMetadata = $metadataMap[$joinTable] ?? null;

        if (null === $fromMetadata || null === $joinMetadata) {
            return false;
        }

        $fromPKs = $fromMetadata->getIdentifierFieldNames();
        $joinPKs = $joinMetadata->getIdentifierFieldNames();

        $conditions = $this->sqlExtractor->extractJoinOnConditions($sql, $joinTable);

        if ([] === $conditions) {
            return $this->canBeCollection($joinTable, $metadataMap);
        }

        $collectionVotes = 0;
        $notCollectionVotes = 0;
        $totalConditions = 0;

        foreach ($conditions as $condition) {
            $leftParts = explode('.', $condition['left']);
            $rightParts = explode('.', $condition['right']);

            $leftCol = end($leftParts);
            $rightCol = end($rightParts);

            ++$totalConditions;

            // Determine FK direction for this condition
            $leftIsPK = in_array($leftCol, $fromPKs, true);
            $rightIsPK = in_array($rightCol, $joinPKs, true);

            // Case 1: from.PK = join.nonPK → FK in join table → Collection
            if ($leftIsPK && !$rightIsPK) {
                ++$collectionVotes;
            }

            // Case 2: from.nonPK = join.PK → FK in from table → NOT collection
            elseif (!$leftIsPK && $rightIsPK) {
                ++$notCollectionVotes;
            }

            // Case 3: Both PKs (ManyToMany join table) or both non-PKs (unusual)
            // Don't vote - need more context
        }

        // No valid conditions parsed - fallback to metadata check
        if (0 === $totalConditions) {
            return $this->canBeCollection($joinTable, $metadataMap);
        }

        // Majority vote: if most conditions indicate collection, it's a collection
        // For composite keys, ALL conditions should agree
        if ($collectionVotes > 0 && $notCollectionVotes === 0) {
            return true; // Collection
        }

        if ($notCollectionVotes > 0 && $collectionVotes === 0) {
            return false; // NOT collection
        }

        // Mixed or uncertain - fallback
        return $this->canBeCollection($joinTable, $metadataMap);
    }

    /**
     * Fallback: Check if a table CAN be a collection target based on metadata.
     * This is less precise but works when ON clause analysis fails.
     */
    private function canBeCollection(string $tableName, array $metadataMap): bool
    {
        foreach ($metadataMap as $sourceMetadata) {
            foreach ($sourceMetadata->getAssociationMappings() as $associationMapping) {
                $targetEntity = $associationMapping['targetEntity'] ?? null;

                if (null === $targetEntity) {
                    continue;
                }

                try {
                    $targetMetadata = $this->entityManager->getClassMetadata($targetEntity);

                    if ($targetMetadata->getTableName() === $tableName) {
                        if (
                            ClassMetadata::ONE_TO_MANY === $associationMapping['type']
                            || ClassMetadata::MANY_TO_MANY === $associationMapping['type']
                        ) {
                            return true;
                        }
                    }
                } catch (\Exception) {
                    continue;
                }
            }
        }

        return false;
    }

    /**
     * Create issue for multi-step hydration opportunity.
     *
     * @param array{sql: string, executionTime: float} $context
     * @param array<mixed>                             $collectionJoins
     */
    private function createMultiStepHydrationIssue(
        array $context,
        array $collectionJoins,
        array|object $query,
    ): ?PerformanceIssue {
        $sql           = $context['sql'];
        $executionTime = $context['executionTime'];

        $tables        = array_column($collectionJoins, 'table');
        $joinCount     = count($collectionJoins);

        // Extract backtrace from query
        $backtrace = $this->extractBacktrace($query);

        // Estimate cartesian product impact
        $estimatedExplosion = sprintf(
            '%d collection JOINs → O(n^%d) hydration complexity',
            $joinCount,
            $joinCount,
        );

        $performanceIssue = new PerformanceIssue([
            'query'               => $this->truncateQuery($sql),
            'join_count'          => $joinCount,
            'tables'              => $tables,
            'execution_time'      => $executionTime,
            'complexity'          => $estimatedExplosion,
            'queries'             => [$query],
            'backtrace'           => $backtrace,
        ]);

        $severity = $joinCount >= 3 ? 'critical' : 'warning';

        $performanceIssue->setSeverity($severity);
        $performanceIssue->setTitle(sprintf('Multiple Collection JOINs Causing O(n^%d) Hydration', $joinCount));
        $performanceIssue->setMessage(
            sprintf(
                'Query performs %d LEFT JOINs on collection-valued associations (%s). ',
                $joinCount,
                implode(', ', $tables),
            ) .
            'This creates a cartesian product that exponentially increases SQL rows and hydration cost. ' .
            'Use multi-step hydration to split into separate queries.',
        );
        $performanceIssue->setSuggestion($this->createMultiStepSuggestion($joinCount, $tables, $sql));

        return $performanceIssue;
    }

    /**
     * Create suggestion for multi-step hydration.
     *
     * @param array<string> $tables
     */
    private function createMultiStepSuggestion(int $joinCount, array $tables, string $sql): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/multi_step_hydration',
            context: [
                'join_count' => $joinCount,
                'tables'     => $tables,
                'sql'        => $this->truncateQuery($sql),
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $joinCount >= 3 ? Severity::critical() : Severity::warning(),
                title: sprintf('Multiple Collection JOINs (O(n^%d) complexity)', $joinCount),
                tags: ['performance', 'hydration', 'join', 'optimization'],
            ),
        );
    }

    private function createTooManyJoinsSuggestion(int $joinCount, string $sql): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/join_too_many',
            context: [
                'join_count' => $joinCount,
                'sql'        => $this->truncateQuery($sql),
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $joinCount > 8 ? Severity::critical() : Severity::warning(),
                title: sprintf('Too Many JOINs in Single Query (%d tables)', $joinCount),
                tags: ['performance', 'join', 'query'],
            ),
        );
    }

    private function createLeftJoinSuggestion(array $join, string $entityClass, string $tableName): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/join_left_on_not_null',
            context: [
                'table'  => $tableName,
                'alias'  => $join['alias'],
                'entity' => $entityClass,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::critical(),
                title: 'Suboptimal LEFT JOIN on NOT NULL Relation',
                tags: ['performance', 'join', 'optimization'],
            ),
        );
    }

    private function createUnusedJoinSuggestion(array $join): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/join_unused',
            context: [
                'type'  => $join['type'],
                'table' => $join['table'],
                'alias' => $join['alias'],
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: 'Unused JOIN Detected',
                tags: ['performance', 'join', 'unused'],
            ),
        );
    }

    private function truncateQuery(string $sql, int $maxLength = 200): string
    {
        if (strlen($sql) <= $maxLength) {
            return $sql;
        }

        return substr($sql, 0, $maxLength) . '...';
    }

    /**
     * Create synthetic backtrace from entity metadata.
     * @return array<int, array<string, mixed>>|null
     */
    private function createEntityBacktrace(ClassMetadata $classMetadata): ?array
    {
        try {
            $reflectionClass = $classMetadata->getReflectionClass();
            $fileName        = $reflectionClass->getFileName();
            $startLine       = $reflectionClass->getStartLine();

            if (false === $fileName || false === $startLine) {
                return null;
            }

            return [
                [
                    'file'     => $fileName,
                    'line'     => $startLine,
                    'class'    => $classMetadata->getName(),
                    'function' => '__construct',
                    'type'     => '::',
                ],
            ];
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Extract backtrace from query object.
     * @return array<int, array<string, mixed>>|null
     */
    private function extractBacktrace(array|object $query): ?array
    {
        if (is_array($query)) {
            return $query['backtrace'] ?? null;
        }

        return is_object($query) && property_exists($query, 'backtrace') ? ($query->backtrace ?? null) : null;
    }
}
