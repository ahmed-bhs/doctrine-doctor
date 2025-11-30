<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Webmozart\Assert\Assert;

/**
 * Detects inconsistencies in JOIN type usage that can cause bugs or performance issues.
 * This analyzer takes a nuanced approach and detects:
 * 1. LEFT JOIN followed by IS NOT NULL check (should use INNER JOIN)
 * 2. COUNT/SUM with INNER JOIN on *-to-many (causes row duplication bugs)
 * 3. Suspicious patterns that may indicate join type misuse
 * We DO NOT blindly enforce "INNER for *-to-one, LEFT for *-to-many" as this
 * oversimplifies and can cause data loss bugs.
 */
class JoinTypeConsistencyAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Pattern to detect Doctrine Paginator COUNT queries.
     * These queries wrap the original query in subqueries with DISTINCT to handle row duplication.
     * Example: SELECT COUNT(*) FROM (SELECT DISTINCT id FROM (...) dctrn_result) dctrn_table
     *
     * This specific pattern is kept as regex because it's a very specific Doctrine internal pattern.
     */
    private const DOCTRINE_PAGINATOR_PATTERN = '/SELECT\s+COUNT\(\*\).*?FROM\s*\(\s*SELECT\s+DISTINCT.*?dctrn_(result|table)/is';

    private SqlStructureExtractor $sqlExtractor;

    /**
     * @var array<string, ClassMetadata>|null Cached metadata map
     */
    private ?array $metadataMapCache = null;

    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
        ?SqlStructureExtractor $sqlExtractor = null,
    ) {
        $this->sqlExtractor = $sqlExtractor ?? new SqlStructureExtractor();
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                $seenIssues = [];

                Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $query) {
                    $sql = $this->extractSQL($query);
                    if ('' === $sql) {
                        continue;
                    }

                    if ('0' === $sql) {
                        continue;
                    }

                    // Pattern 1: LEFT JOIN with IS NOT NULL check
                    // Migration from regex to SQL Parser:
                    // - Use extractJoins() to find LEFT JOINs
                    // - Use hasIsNotNullOnAlias() to check for IS NOT NULL on joined table
                    $joins = $this->sqlExtractor->extractJoins($sql);

                    foreach ($joins as $join) {
                        // Only check LEFT JOINs
                        if ('LEFT' !== $join['type']) {
                            continue;
                        }

                        $tableName = $join['table'];
                        $alias = $join['alias'] ?? $tableName;

                        // Check if this LEFT JOIN has IS NOT NULL condition
                        $fieldName = $this->sqlExtractor->findIsNotNullFieldOnAlias($sql, $alias);
                        if (null !== $fieldName) {
                            $key = 'left_join_not_null_' . md5($tableName . $alias);
                            if (isset($seenIssues[$key])) {
                                continue;
                            }

                            $seenIssues[$key] = true;

                            yield $this->createLeftJoinWithNotNullIssue(
                                $tableName,
                                $alias,
                                $fieldName, // Field name extracted from IS NOT NULL condition
                                $sql,
                                $query,
                            );
                        }
                    }

                    // Pattern 2: COUNT/SUM/AVG with INNER JOIN on collection (potential duplication bug)
                    // IMPROVED: Now only alerts if INNER JOIN is on a collection (OneToMany/ManyToMany)
                    // INNER JOIN on ManyToOne doesn't cause row duplication, so no false positive
                    $aggregations = $this->sqlExtractor->extractAggregationFunctions($sql);

                    if (!empty($aggregations)) {
                        // Extract INNER JOINs specifically
                        $joins = $this->sqlExtractor->extractJoins($sql);
                        $innerJoins = array_filter($joins, fn ($join) => 'INNER' === $join['type']);

                        if (!empty($innerJoins)) {
                            // Check if any INNER JOIN is on a collection
                            $metadataMap = $this->getMetadataMap();
                            $fromTable = $this->extractFromTable($sql, $metadataMap);

                            if (null !== $fromTable) {
                                $hasCollectionInnerJoin = false;

                                foreach ($innerJoins as $join) {
                                    if ($this->isCollectionJoin($join, $metadataMap, $sql, $fromTable)) {
                                        $hasCollectionInnerJoin = true;
                                        break;
                                    }
                                }

                                if ($hasCollectionInnerJoin) {
                                    $aggregation = $aggregations[0];

                                    $key = 'aggregation_inner_join_' . md5($sql);
                                    if (isset($seenIssues[$key])) {
                                        continue;
                                    }

                                    $seenIssues[$key] = true;

                                    // Check if the query is protected against row duplication
                                    $isProtected = $this->isQueryProtectedAgainstDuplication($sql);

                                    yield $this->createAggregationWithInnerJoinIssue(
                                        $aggregation,
                                        $sql,
                                        $query,
                                        $isProtected,
                                    );
                                }
                            }
                        }
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'JOIN Type Consistency Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects inconsistencies in JOIN usage that can cause bugs or performance issues';
    }

    /**
     * Extract SQL from query data.
     */
    private function extractSQL(array|object $query): string
    {
        if (is_array($query)) {
            return $query['sql'] ?? '';
        }

        return is_object($query) && property_exists($query, 'sql') ? ($query->sql ?? '') : '';
    }

    /**
     * Check if a query is protected against row duplication.
     * Returns true if:
     * - Query uses Doctrine Paginator pattern (COUNT with DISTINCT in subquery)
     * - Query explicitly uses DISTINCT or COUNT(DISTINCT)
     */
    private function isQueryProtectedAgainstDuplication(string $sql): bool
    {
        // Check for Doctrine Paginator pattern (kept as regex - specific Doctrine pattern)
        if (1 === preg_match(self::DOCTRINE_PAGINATOR_PATTERN, $sql)) {
            return true;
        }

        // Check for explicit DISTINCT usage
        // Migration from regex to SQL Parser: use hasDistinct() method
        if ($this->sqlExtractor->hasDistinct($sql)) {
            return true;
        }

        return false;
    }

    /**
     * Create issue for LEFT JOIN with IS NOT NULL check.
     */
    private function createLeftJoinWithNotNullIssue(
        string $tableName,
        string $alias,
        string $field,
        string $sql,
        array|object $query,
    ): IntegrityIssue {
        $backtrace = $this->extractBacktrace($query);

        $issueData = new IssueData(
            type: 'left_join_with_not_null',
            title: 'LEFT JOIN with IS NOT NULL Check',
            description: sprintf(
                "Query uses LEFT JOIN on '%s' but then checks '%s.%s IS NOT NULL'. " .
                "This is redundant - if you know the field is NOT NULL, use INNER JOIN instead for clarity and potential performance gain. " .
                "LEFT JOIN is for optional relationships where NULL is expected.",
                $tableName,
                $alias,
                $field,
            ),
            severity: Severity::info(),
            suggestion: $this->createLeftJoinWithNotNullSuggestion($tableName, $alias, $field, $sql),
            queries: [],
            backtrace: $backtrace,
        );

        return new IntegrityIssue($issueData->toArray());
    }

    /**
     * Create issue for aggregation with INNER JOIN.
     */
    private function createAggregationWithInnerJoinIssue(
        string $aggregation,
        string $sql,
        array|object $query,
        bool $isProtected,
    ): PerformanceIssue {
        $backtrace = $this->extractBacktrace($query);

        // Adapt message and severity based on whether the query is protected
        if ($isProtected) {
            // Query is protected (e.g., Doctrine Paginator with DISTINCT)
            // Results are correct, but performance is suboptimal
            $title = sprintf('%s with INNER JOIN - Performance Impact', $aggregation);
            $description = sprintf(
                "Query uses %s() with INNER JOIN and row duplication is handled by DISTINCT (likely via Doctrine Paginator). " .
                "While the results are **correct**, the query generates duplicate rows before applying DISTINCT, which impacts performance. " .
                "Consider optimizing the underlying query to reduce the number of JOINs or using subqueries to avoid generating duplicates in the first place.",
                $aggregation,
            );
            $severity = Severity::info();
        } else {
            // Query is NOT protected - potential correctness issue
            $title = sprintf('%s with INNER JOIN May Cause Incorrect Results', $aggregation);
            $description = sprintf(
                "Query uses %s() with INNER JOIN, which may cause row duplication and incorrect aggregate results. " .
                "When using INNER JOIN on a *-to-many relationship, each parent row is duplicated for each child, " .
                "causing COUNT/SUM/AVG to return inflated values. Consider using LEFT JOIN with DISTINCT, " .
                "or a subquery to avoid duplication.",
                $aggregation,
            );
            $severity = Severity::warning();
        }

        $issueData = new IssueData(
            type: 'aggregation_with_inner_join',
            title: $title,
            description: $description,
            severity: $severity,
            suggestion: $this->createAggregationWithInnerJoinSuggestion($aggregation, $sql, $isProtected),
            queries: [],
            backtrace: $backtrace,
        );

        return new PerformanceIssue($issueData->toArray());
    }

    /**
     * Extract backtrace from query data.
     * @return array<int, array<string, mixed>>|null
     */
    private function extractBacktrace(array|object $query): ?array
    {
        if (is_array($query)) {
            return $query['backtrace'] ?? null;
        }

        return is_object($query) && property_exists($query, 'backtrace') ? ($query->backtrace ?? null) : null;
    }

    /**
     * Create suggestion for LEFT JOIN with NOT NULL.
     */
    private function createLeftJoinWithNotNullSuggestion(
        string $tableName,
        string $alias,
        string $field,
        string $sql,
    ): mixed {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/left_join_with_not_null',
            context: [
                'table_name' => $tableName,
                'alias' => $alias,
                'field' => $field,
                'original_query' => $sql,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::info(),
                title: 'Replace LEFT JOIN with INNER JOIN',
                tags: ['code-quality', 'join', 'optimization'],
            ),
        );
    }

    /**
     * Create suggestion for aggregation with INNER JOIN.
     */
    private function createAggregationWithInnerJoinSuggestion(
        string $aggregation,
        string $sql,
        bool $isProtected,
    ): mixed {
        $tags = $isProtected
            ? ['performance', 'join', 'aggregation', 'optimization']
            : ['bug', 'join', 'aggregation', 'count'];

        $severity = $isProtected ? Severity::info() : Severity::warning();

        $title = $isProtected
            ? sprintf('Optimize %s query to avoid row duplication', $aggregation)
            : sprintf('Fix %s with INNER JOIN row duplication', $aggregation);

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/aggregation_with_inner_join',
            context: [
                'aggregation' => $aggregation,
                'original_query' => $sql,
                'is_protected' => $isProtected,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $severity,
                title: $title,
                tags: $tags,
            ),
        );
    }

    /**
     * Build metadata map (cached for performance).
     * @return array<string, ClassMetadata>
     */
    private function getMetadataMap(): array
    {
        if (null !== $this->metadataMapCache) {
            return $this->metadataMapCache;
        }

        $map = [];
        $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        foreach ($allMetadata as $metadata) {
            $tableName = $metadata->getTableName();
            $map[$tableName] = $metadata;
        }

        $this->metadataMapCache = $map;

        return $map;
    }

    /**
     * Extract FROM table from SQL.
     * @param array<string, ClassMetadata> $metadataMap
     */
    private function extractFromTable(string $sql, array $metadataMap): ?string
    {
        $mainTable = $this->sqlExtractor->extractMainTable($sql);

        if (null === $mainTable) {
            return null;
        }

        $tableName = $mainTable['table'];

        if (!isset($metadataMap[$tableName])) {
            return null;
        }

        return $tableName;
    }

    /**
     * Determine if a JOIN is on a collection (OneToMany/ManyToMany).
     * @param array<string, mixed> $join
     * @param array<string, ClassMetadata> $metadataMap
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
     * Determine if FK is in joined table (making it a collection).
     * @param array<string, ClassMetadata> $metadataMap
     */
    private function isForeignKeyInJoinedTable(string $sql, string $fromTable, string $joinTable, array $metadataMap): bool
    {
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

        $condition = $conditions[0];

        $leftParts = explode('.', $condition['left']);
        $rightParts = explode('.', $condition['right']);

        $leftCol = end($leftParts);
        $rightCol = end($rightParts);

        // from.PK = join.nonPK → Collection
        if (in_array($leftCol, $fromPKs, true) && !in_array($rightCol, $joinPKs, true)) {
            return true;
        }

        // from.nonPK = join.PK → NOT collection
        if (!in_array($leftCol, $fromPKs, true) && in_array($rightCol, $joinPKs, true)) {
            return false;
        }

        return $this->canBeCollection($joinTable, $metadataMap);
    }

    /**
     * Fallback: Check if table CAN be a collection based on metadata.
     * @param array<string, ClassMetadata> $metadataMap
     */
    private function canBeCollection(string $tableName, array $metadataMap): bool
    {
        foreach ($metadataMap as $metadata) {
            foreach ($metadata->getAssociationMappings() as $mapping) {
                $targetEntity = $mapping['targetEntity'] ?? null;

                if (null === $targetEntity) {
                    continue;
                }

                try {
                    $targetMetadata = $this->entityManager->getClassMetadata($targetEntity);

                    if ($targetMetadata->getTableName() === $tableName) {
                        if (
                            ClassMetadata::ONE_TO_MANY === $mapping['type']
                            || ClassMetadata::MANY_TO_MANY === $mapping['type']
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
}
