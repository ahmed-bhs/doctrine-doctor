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
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

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
class JoinOptimizationAnalyzer implements AnalyzerInterface
{
    /**
     * Minimum query count to trigger analysis.
     */
    private const MIN_QUERY_COUNT = 3;

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
                if ($queryDataCollection->count() < self::MIN_QUERY_COUNT) {
                    return;
                }

                $metadataMap = $this->buildMetadataMap();
                $seenIssues  = [];

                assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $query) {
                    $context = $this->extractQueryContext($query);

                    if (!$this->hasJoin($context['sql'])) {
                        continue;
                    }

                    $joins = $this->extractJoins($context['sql']);

                    if ([] === $joins) {
                        continue;
                    }

                    yield from $this->analyzeQueryJoins($context, $joins, $metadataMap, $seenIssues);
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
    private function analyzeQueryJoins(array $context, array $joins, array $metadataMap, array &$seenIssues): \Generator
    {
        // Check 1: Too many JOINs
        yield from $this->checkAndYieldTooManyJoins($context, $joins, $seenIssues);

        // Check 2 & 3: Suboptimal and unused JOINs
        yield from $this->checkAndYieldJoinIssues($context, $joins, $metadataMap, $seenIssues);
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
    private function checkAndYieldJoinIssues(array $context, array $joins, array $metadataMap, array &$seenIssues): \Generator
    {
        assert(is_iterable($joins), '$joins must be iterable');

        foreach ($joins as $join) {
            yield from $this->checkAndYieldSuboptimalJoin($join, $metadataMap, $context, $seenIssues);
            yield from $this->checkAndYieldUnusedJoin($join, $context['sql'], $seenIssues);
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
    private function checkAndYieldUnusedJoin(array $join, string $sql, array &$seenIssues): \Generator
    {
        $unusedJoin = $this->checkUnusedJoin($join, $sql);

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

        foreach ($classMetadataFactory->getAllMetadata() as $classMetadatum) {
            $tableName       = $classMetadatum->getTableName();
            $map[$tableName] = $classMetadatum;
        }

        return $map;
    }

    private function hasJoin(string $sql): bool
    {
        return 1 === preg_match('/\b(LEFT|INNER|RIGHT|OUTER)?\s*JOIN\b/i', $sql);
    }

    /**
     * Extract JOIN information from SQL query.
     */
    private function extractJoins(string $sql): array
    {

        $joins = [];

        // Pattern to match JOINs
        // Captures: JOIN type, table name, alias
        $pattern = '/\b(LEFT\s+OUTER|LEFT|INNER|RIGHT|RIGHT\s+OUTER)?\s*JOIN\s+(\w+)\s+(?:AS\s+)?(\w+)/i';

        if (preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER) >= 1) {
            assert(is_iterable($matches), '$matches must be iterable');

            foreach ($matches as $match) {
                $joinType  = strtoupper(trim($match[1] ?: 'INNER'));
                $tableName = $match[2];
                $alias     = $match[3];

                // Normalize JOIN type
                if ('LEFT OUTER' === $joinType) {
                    $joinType = 'LEFT';
                } elseif ('RIGHT OUTER' === $joinType) {
                    $joinType = 'RIGHT';
                } elseif ('' === $joinType) {
                    $joinType = 'INNER';
                }

                $joins[] = [
                    'type'       => $joinType,
                    'table'      => $tableName,
                    'alias'      => $alias,
                    'full_match' => $match[0],
                ];
            }
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
     * Determine if a JOIN relation is nullable.
     */
    private function isJoinNullable(array $join, string $sql, ClassMetadata $classMetadata): ?bool
    {
        $onPattern = '/' . preg_quote((string) $join['full_match'], '/') . '\s+ON\s+([^)]+?)(?:WHERE|GROUP|ORDER|LIMIT|$)/is';

        if (1 === preg_match($onPattern, $sql, $onMatch)) {
            $onClause = $onMatch[1];

            // Look for foreign key columns
            foreach ($classMetadata->getAssociationMappings() as $associationMapping) {
                if (isset($associationMapping['joinColumns'])) {
                    assert(is_iterable($associationMapping['joinColumns']), 'joinColumns must be iterable');

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
     * Check if JOIN alias is actually used in the query.
     */
    private function checkUnusedJoin(array $join, string $sql): ?PerformanceIssue
    {
        $alias = $join['alias'];

        // Remove the JOIN clause itself from search
        $sqlWithoutJoin = preg_replace('/' . preg_quote((string) $join['full_match'], '/') . '.*?(?=(?:LEFT|INNER|RIGHT|WHERE|GROUP|ORDER|LIMIT|$))/is', '', $sql);

        // Check if alias is used in SELECT, WHERE, GROUP BY, ORDER BY, HAVING
        $aliasPattern = '/\b' . preg_quote($alias, '/') . '\b\./';

        if (1 !== preg_match($aliasPattern, (string) $sqlWithoutJoin)) {
            // Alias not used anywhere
            // Create synthetic backtrace pointing to SQL query location
            $backtrace = $this->createSqlBacktrace();

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
            templateName: 'join_too_many',
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
            templateName: 'join_left_on_not_null',
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
            templateName: 'join_unused',
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
     * Create synthetic backtrace for SQL query issues.
     * @return array<int, array<string, mixed>>|null
     */
    private function createSqlBacktrace(): ?array
    {
        // Since we don't have the actual code location for generated SQL,
        // we return null to indicate no backtrace is available
        return null;
    }
}
