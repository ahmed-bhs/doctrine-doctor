<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Parser;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Interface\AggregationAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Interface\ConditionAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Interface\JoinExtractorInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Interface\PatternDetectorInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Interface\PerformanceAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\Interface\QueryNormalizerInterface;

/**
 * Façade for SQL structure extraction and analysis.
 *
 * This class provides a unified interface to all SQL parsing capabilities,
 * delegating to specialized analyzers internally. It follows the Façade pattern
 * to simplify usage across analyzers.
 *
 * Architecture:
 * - Single Responsibility: Each internal analyzer has one clear responsibility
 * - Interface Segregation: Internal components use specific interfaces
 * - Dependency Inversion: Depends on abstractions (interfaces), not concretions
 * - Façade Pattern: Provides simple unified API for complex subsystem
 *
 * Usage in Analyzers:
 * This is the recommended approach for analyzers that need SQL parsing.
 * Currently used by 21+ analyzers including NPlusOneAnalyzer, MissingIndexAnalyzer,
 * JoinOptimizationAnalyzer, and others.
 *
 * Advanced Usage:
 * For analyzers needing only specific functionality, you may inject individual
 * interfaces (ConditionAnalyzerInterface, PerformanceAnalyzerInterface, etc.)
 * instead of this façade. However, the façade is preferred for simplicity.
 *
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class SqlStructureExtractor
{
    private JoinExtractorInterface $joinExtractor;

    private QueryNormalizerInterface $queryNormalizer;

    private PatternDetectorInterface $patternDetector;

    private ConditionAnalyzerInterface $conditionAnalyzer;

    private PerformanceAnalyzerInterface $performanceAnalyzer;

    private AggregationAnalyzerInterface $aggregationAnalyzer;

    public function __construct(
        ?JoinExtractorInterface $joinExtractor = null,
        ?QueryNormalizerInterface $queryNormalizer = null,
        ?PatternDetectorInterface $patternDetector = null,
        ?ConditionAnalyzerInterface $conditionAnalyzer = null,
        ?PerformanceAnalyzerInterface $performanceAnalyzer = null,
        ?AggregationAnalyzerInterface $aggregationAnalyzer = null,
    ) {
        $this->joinExtractor = $joinExtractor ?? new SqlJoinExtractor();
        $this->queryNormalizer = $queryNormalizer ?? new SqlQueryNormalizer();
        $this->patternDetector = $patternDetector ?? new SqlPatternDetector($this->joinExtractor instanceof SqlJoinExtractor ? $this->joinExtractor : null);
        $this->conditionAnalyzer = $conditionAnalyzer ?? new SqlConditionAnalyzer();
        $this->performanceAnalyzer = $performanceAnalyzer ?? new SqlPerformanceAnalyzer();
        $this->aggregationAnalyzer = $aggregationAnalyzer ?? new SqlAggregationAnalyzer();
    }

    // ==================== JOIN EXTRACTOR DELEGATION ====================

    /**
     * @return array<int, array{type: string, table: string, alias: ?string, expr: mixed}>
     */
    public function extractJoins(string $sql): array
    {
        return $this->joinExtractor->extractJoins($sql);
    }

    /**
     * @return array{table: string, alias: ?string}|null
     */
    public function extractMainTable(string $sql): ?array
    {
        return $this->joinExtractor->extractMainTable($sql);
    }

    /**
     * @return array<int, array{table: string, alias: ?string, source: string}>
     */
    public function extractAllTables(string $sql): array
    {
        return $this->joinExtractor->extractAllTables($sql);
    }

    /**
     * @return array<string>
     */
    public function getAllTableNames(string $sql): array
    {
        return $this->joinExtractor->getAllTableNames($sql);
    }

    public function hasTable(string $sql, string $tableName): bool
    {
        return $this->joinExtractor->hasTable($sql, $tableName);
    }

    public function hasJoin(string $sql): bool
    {
        return $this->joinExtractor->hasJoin($sql);
    }

    public function hasJoins(string $sql): bool
    {
        return $this->joinExtractor->hasJoins($sql);
    }

    public function countJoins(string $sql): int
    {
        return $this->joinExtractor->countJoins($sql);
    }

    public function extractJoinOnClause(string $sql, string $joinExpression): ?string
    {
        return $this->joinExtractor->extractJoinOnClause($sql, $joinExpression);
    }

    /**
     * @return array{realName: string, display: string, alias: string}|null
     */
    public function extractTableNameWithAlias(string $sql, string $targetAlias): ?array
    {
        return $this->joinExtractor->extractTableNameWithAlias($sql, $targetAlias);
    }

    /**
     * @return array<int, array{left: string, operator: string, right: string}>
     */
    public function extractJoinOnConditions(string $sql, string $tableName): array
    {
        return $this->joinExtractor->extractJoinOnConditions($sql, $tableName);
    }

    // ==================== QUERY NORMALIZER DELEGATION ====================

    public function normalizeQuery(string $sql): string
    {
        return $this->queryNormalizer->normalizeQuery($sql);
    }

    // ==================== PATTERN DETECTOR DELEGATION ====================

    /**
     * @return array{table: string, foreignKey: string}|null
     */
    public function detectNPlusOnePattern(string $sql): ?array
    {
        return $this->patternDetector->detectNPlusOnePattern($sql);
    }

    /**
     * @return array{table: string, foreignKey: string}|null
     */
    public function detectNPlusOneFromJoin(string $sql): ?array
    {
        return $this->patternDetector->detectNPlusOneFromJoin($sql);
    }

    public function detectLazyLoadingPattern(string $sql): ?string
    {
        return $this->patternDetector->detectLazyLoadingPattern($sql);
    }

    public function detectUpdateQuery(string $sql): ?string
    {
        return $this->patternDetector->detectUpdateQuery($sql);
    }

    public function detectDeleteQuery(string $sql): ?string
    {
        return $this->patternDetector->detectDeleteQuery($sql);
    }

    public function detectInsertQuery(string $sql): ?string
    {
        return $this->patternDetector->detectInsertQuery($sql);
    }

    public function isSelectQuery(string $sql): bool
    {
        return $this->patternDetector->isSelectQuery($sql);
    }

    public function detectPartialCollectionLoad(string $sql): bool
    {
        return $this->patternDetector->detectPartialCollectionLoad($sql);
    }

    // ==================== CONDITION ANALYZER DELEGATION ====================

    /**
     * @return array<string>
     */
    public function extractWhereColumns(string $sql): array
    {
        return $this->conditionAnalyzer->extractWhereColumns($sql);
    }

    /**
     * @return array<int, array{column: string, operator: string, alias: ?string}>
     */
    public function extractWhereConditions(string $sql): array
    {
        return $this->conditionAnalyzer->extractWhereConditions($sql);
    }

    /**
     * @return array<string>
     */
    public function extractJoinColumns(string $sql): array
    {
        return $this->conditionAnalyzer->extractJoinColumns($sql);
    }

    /**
     * @return array<int, array{function: string, field: string, operator: string, value: string, raw: string}>
     */
    public function extractFunctionsInWhere(string $sql): array
    {
        return $this->conditionAnalyzer->extractFunctionsInWhere($sql);
    }

    public function findIsNotNullFieldOnAlias(string $sql, string $alias): ?string
    {
        return $this->conditionAnalyzer->findIsNotNullFieldOnAlias($sql, $alias);
    }

    public function hasComplexWhereConditions(string $sql): bool
    {
        return $this->conditionAnalyzer->hasComplexWhereConditions($sql);
    }

    public function hasLocaleConstraintInJoin(string $sql): bool
    {
        return $this->conditionAnalyzer->hasLocaleConstraintInJoin($sql);
    }

    public function hasUniqueJoinConstraint(string $sql): bool
    {
        return $this->conditionAnalyzer->hasUniqueJoinConstraint($sql);
    }

    public function isAliasUsedInQuery(string $sql, string $alias, ?string $joinExpression = null): bool
    {
        return $this->conditionAnalyzer->isAliasUsedInQuery($sql, $alias, $joinExpression);
    }

    // ==================== PERFORMANCE ANALYZER DELEGATION ====================

    public function hasOrderBy(string $sql): bool
    {
        return $this->performanceAnalyzer->hasOrderBy($sql);
    }

    public function hasLimit(string $sql): bool
    {
        return $this->performanceAnalyzer->hasLimit($sql);
    }

    public function hasOffset(string $sql): bool
    {
        return $this->performanceAnalyzer->hasOffset($sql);
    }

    public function hasSubquery(string $sql): bool
    {
        return $this->performanceAnalyzer->hasSubquery($sql);
    }

    public function hasGroupBy(string $sql): bool
    {
        return $this->performanceAnalyzer->hasGroupBy($sql);
    }

    public function hasLeadingWildcardLike(string $sql): bool
    {
        return $this->performanceAnalyzer->hasLeadingWildcardLike($sql);
    }

    public function hasDistinct(string $sql): bool
    {
        return $this->performanceAnalyzer->hasDistinct($sql);
    }

    public function getLimitValue(string $sql): ?int
    {
        return $this->performanceAnalyzer->getLimitValue($sql);
    }

    // ==================== AGGREGATION ANALYZER DELEGATION ====================

    /**
     * @return array<string>
     */
    public function extractAggregationFunctions(string $sql): array
    {
        return $this->aggregationAnalyzer->extractAggregationFunctions($sql);
    }

    /**
     * @return string[]
     */
    public function extractGroupByColumns(string $sql): array
    {
        return $this->aggregationAnalyzer->extractGroupByColumns($sql);
    }

    public function extractOrderBy(string $sql): ?string
    {
        return $this->aggregationAnalyzer->extractOrderBy($sql);
    }

    /**
     * @return array<string>
     */
    public function extractOrderByColumnNames(string $sql): array
    {
        return $this->aggregationAnalyzer->extractOrderByColumnNames($sql);
    }

    public function extractSelectClause(string $sql): ?string
    {
        return $this->aggregationAnalyzer->extractSelectClause($sql);
    }

    /**
     * @return string[]
     */
    public function extractTableAliasesFromSelect(string $sql): array
    {
        return $this->aggregationAnalyzer->extractTableAliasesFromSelect($sql);
    }
}
