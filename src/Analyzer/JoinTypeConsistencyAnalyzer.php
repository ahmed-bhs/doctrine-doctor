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
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\CodeQualityIssue;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

/**
 * Detects inconsistencies in JOIN type usage that can cause bugs or performance issues.
 * This analyzer takes a nuanced approach and detects:
 * 1. LEFT JOIN followed by IS NOT NULL check (should use INNER JOIN)
 * 2. COUNT/SUM with INNER JOIN on *-to-many (causes row duplication bugs)
 * 3. Suspicious patterns that may indicate join type misuse
 * We DO NOT blindly enforce "INNER for *-to-one, LEFT for *-to-many" as this
 * oversimplifies and can cause data loss bugs.
 */
class JoinTypeConsistencyAnalyzer implements AnalyzerInterface
{
    /**
     * Pattern to detect LEFT JOIN followed by IS NOT NULL on the joined table.
     * This is redundant - if you check IS NOT NULL, use INNER JOIN instead.
     */
    private const LEFT_JOIN_WITH_NOT_NULL_PATTERN = '/LEFT\s+(?:OUTER\s+)?JOIN\s+(\w+)(?:\s+AS)?\s+(\w+).*?WHERE.*?\b\2\.(\w+)\s+IS\s+NOT\s+NULL/is';

    /**
     * Pattern to detect COUNT/SUM with INNER JOIN (potential row duplication).
     * Example: SELECT COUNT(o.id) FROM orders o INNER JOIN order_items oi
     */
    private const AGGREGATION_WITH_INNER_JOIN_PATTERN = '/SELECT.*?\b(COUNT|SUM|AVG)\s*\([^)]+\).*?FROM.*?INNER\s+JOIN/is';

    public function __construct(
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                $seenIssues = [];

                assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $query) {
                    $sql = $this->extractSQL($query);
                    if ('' === $sql) {
                        continue;
                    }

                    if ('0' === $sql) {
                        continue;
                    }

                    // Pattern 1: LEFT JOIN with IS NOT NULL check
                    if (preg_match_all(self::LEFT_JOIN_WITH_NOT_NULL_PATTERN, $sql, $matches, PREG_SET_ORDER) >= 1) {
                        assert(is_iterable($matches), '$matches must be iterable');

                        foreach ($matches as $match) {
                            $tableName = $match[1];
                            $alias = $match[2];
                            $field = $match[3];

                            $key = 'left_join_not_null_' . md5($tableName . $alias);
                            if (isset($seenIssues[$key])) {
                                continue;
                            }

                            $seenIssues[$key] = true;

                            yield $this->createLeftJoinWithNotNullIssue(
                                $tableName,
                                $alias,
                                $field,
                                $sql,
                                $query,
                            );
                        }
                    }

                    // Pattern 2: COUNT/SUM/AVG with INNER JOIN (potential duplication bug)
                    if (1 === preg_match(self::AGGREGATION_WITH_INNER_JOIN_PATTERN, $sql, $match)) {
                        $aggregation = strtoupper($match[1]);

                        $key = 'aggregation_inner_join_' . md5($sql);
                        if (isset($seenIssues[$key])) {
                            continue;
                        }

                        $seenIssues[$key] = true;

                        yield $this->createAggregationWithInnerJoinIssue(
                            $aggregation,
                            $sql,
                            $query,
                        );
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
     * Create issue for LEFT JOIN with IS NOT NULL check.
     */
    private function createLeftJoinWithNotNullIssue(
        string $tableName,
        string $alias,
        string $field,
        string $sql,
        array|object $query,
    ): CodeQualityIssue {
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

        return new CodeQualityIssue($issueData->toArray());
    }

    /**
     * Create issue for aggregation with INNER JOIN.
     */
    private function createAggregationWithInnerJoinIssue(
        string $aggregation,
        string $sql,
        array|object $query,
    ): PerformanceIssue {
        $backtrace = $this->extractBacktrace($query);

        $issueData = new IssueData(
            type: 'aggregation_with_inner_join',
            title: sprintf('%s with INNER JOIN May Cause Incorrect Results', $aggregation),
            description: sprintf(
                "Query uses %s() with INNER JOIN, which may cause row duplication and incorrect aggregate results. " .
                "When using INNER JOIN on a *-to-many relationship, each parent row is duplicated for each child, " .
                "causing COUNT/SUM/AVG to return inflated values. Consider using LEFT JOIN with DISTINCT, " .
                "or a subquery to avoid duplication.",
                $aggregation,
            ),
            severity: Severity::warning(),
            suggestion: $this->createAggregationWithInnerJoinSuggestion($aggregation, $sql),
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
            templateName: 'left_join_with_not_null',
            context: [
                'table_name' => $tableName,
                'alias' => $alias,
                'field' => $field,
                'original_query' => $sql,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::codeQuality(),
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
    ): mixed {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'aggregation_with_inner_join',
            context: [
                'aggregation' => $aggregation,
                'original_query' => $sql,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: sprintf('Fix %s with INNER JOIN row duplication', $aggregation),
                tags: ['bug', 'join', 'aggregation', 'count'],
            ),
        );
    }
}
