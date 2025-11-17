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
 * 4. Missing indexes on JOIN columns
 * Example:
 * BAD:
 *   SELECT o FROM Order o
 *   LEFT JOIN o.customer c  -- customer is NOT NULL → should be INNER JOIN
 *  GOOD:
 *   SELECT o FROM Order o
 *   INNER JOIN o.customer c  -- 20-30% faster
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
        return 'Detects suboptimal JOIN usage: LEFT JOIN on NOT NULL, too many JOINs, unused JOINs';
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
        yield from $this->checkAndYieldTooManyJoins($context, $joins, $seenIssues);

        // Check 2 & 3: Suboptimal and unused JOINs
        yield from $this->checkAndYieldJoinIssues($context, $joins, $metadataMap, $seenIssues, $query);
    }

    /**
     * Check and yield too many joins issue.
     * @param array{sql: string, executionTime: float} $context
     * @param array<mixed> $joins
     * @param array<string, bool> $seenIssues
     * @return \Generator<PerformanceIssue>
     */
    private function checkAndYieldTooManyJoins(array $context, array $joins, array &$seenIssues): \Generator
    {
        $tooManyJoins = $this->checkTooManyJoins($context['sql'], $joins, $context['executionTime']);

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
            yield from $this->checkAndYieldSuboptimalJoin($join, $metadataMap, $context, $seenIssues);
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
    private function checkAndYieldSuboptimalJoin(array $join, array $metadataMap, array $context, array &$seenIssues): \Generator
    {
        $suboptimalJoin = $this->checkSuboptimalJoinType($join, $metadataMap, $context['sql'], $context['executionTime']);

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
     * Build unique key for issue deduplication.
     */
    private function buildIssueKey(PerformanceIssue $performanceIssue): string
    {
        return $performanceIssue->getTitle() . '|' . ($performanceIssue->getData()['table'] ?? '');
    }

    private function buildMetadataMap(): array
    {

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
    private function checkTooManyJoins(string $sql, array $joins, float $executionTime): ?PerformanceIssue
    {
        $joinCount = count($joins);

        if ($joinCount <= $this->maxJoinsRecommended) {
            return null;
        }

        $severity = $joinCount > $this->maxJoinsCritical ? 'critical' : 'warning';

        $performanceIssue = new PerformanceIssue([
            'query'           => $this->truncateQuery($sql),
            'join_count'      => $joinCount,
            'max_recommended' => $this->maxJoinsRecommended,
            'execution_time'  => $executionTime,
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
     */
    private function checkSuboptimalJoinType(
        array $join,
        array $metadataMap,
        string $sql,
        float $executionTime,
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
            $isNullable = $this->isJoinNullable($join, $sql, $metadata);

            if (false === $isNullable) {
                // LEFT JOIN on NOT NULL relation → should be INNER JOIN
                return $this->createLeftJoinOnNotNullIssue($join, $metadata, $sql, $executionTime);
            }
        }

        return null;
    }

    /**
     * Determine if a JOIN relation is nullable using SQL parser.
     *
     * Migration from regex to SQL Parser:
     * - Replaced regex pattern for ON clause extraction with SqlStructureExtractor::extractJoinOnClause()
     * - More robust: properly parses JOIN structure
     * - Handles complex ON conditions, multiple JOINs
     */
    private function isJoinNullable(array $join, string $sql, ClassMetadata $classMetadata): ?bool
    {
        // Use SQL parser to extract ON clause
        $onClause = $this->sqlExtractor->extractJoinOnClause($sql, (string) $join['full_match']);

        if (null !== $onClause) {
            // Look for foreign key columns
            foreach ($classMetadata->getAssociationMappings() as $associationMapping) {
                if (isset($associationMapping['joinColumns'])) {
                    Assert::isIterable($associationMapping['joinColumns'], 'joinColumns must be iterable');

                    foreach ($associationMapping['joinColumns'] as $joinColumn) {
                        $columnName = $joinColumn['name'] ?? null;

                        // Check if this column appears in the ON clause
                        if (null !== $columnName && false !== stripos($onClause, (string) $columnName)) {
                            // Found the FK - check if it's nullable
                            return $joinColumn['nullable'] ?? true;
                        }
                    }
                }
            }
        }

        // Unknown - return null
        return null;
    }

    private function createLeftJoinOnNotNullIssue(
        array $join,
        ClassMetadata $classMetadata,
        string $sql,
        float $executionTime,
    ): PerformanceIssue {
        $entityClass = $classMetadata->getName();
        $tableName   = $join['table'];

        $performanceIssue = new PerformanceIssue([
            'query'          => $this->truncateQuery($sql),
            'join_type'      => 'LEFT',
            'table'          => $tableName,
            'entity'         => $entityClass,
            'execution_time' => $executionTime,
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
