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
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

/**
 * Detects ORDER BY clauses without LIMIT, which can cause massive table scans.
 * Sorting an entire table without limiting results is a common performance issue,
 * especially when pagination is forgotten. This forces the database to sort
 * potentially millions of rows that will never be displayed.
 * Example:
 * BAD:
 *   SELECT * FROM orders ORDER BY created_at DESC
 *   -- Sorts 1M rows but displays only 20 → waste!
 * GOOD:
 *   SELECT * FROM orders ORDER BY created_at DESC LIMIT 20
 *   -- Only sorts what's needed
 */
class OrderByWithoutLimitAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    public function __construct(
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly SqlStructureExtractor $sqlExtractor = new SqlStructureExtractor(),
        private readonly float $minExecutionTimeMs = 10.0,
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

                foreach ($queryDataCollection as $query) {
                    $sql = $this->extractSQL($query);
                    $executionTime = $this->extractExecutionTime($query);
                    if ('' === $sql) {
                        continue;
                    }

                    if ('0' === $sql) {
                        continue;
                    }

                    // Use SQL Parser instead of regex for robust detection
                    // This properly handles complex SQL queries and edge cases
                    if (null === $this->sqlExtractor) {
                        continue;
                    }

                    $hasLimit = $this->sqlExtractor->hasLimit($sql) || $this->sqlExtractor->hasOffset($sql);
                    $hasOrderBy = $this->sqlExtractor->hasOrderBy($sql);

                    // Skip if query already has LIMIT or OFFSET
                    if ($hasLimit) {
                        continue;
                    }

                    // Check if query has ORDER BY
                    if ($hasOrderBy) {
                        $orderByClause = $this->sqlExtractor->extractOrderBy($sql);

                        // If extraction failed, skip
                        if (null === $orderByClause) {
                            continue;
                        }

                        // Skip queries with WHERE ... IN (...) — bounded result set
                        if ($this->hasBoundedWhereInClause($sql)) {
                            continue;
                        }

                        // Detect query context from backtrace
                        $context = $this->detectQueryContext($query);

                        if ('single_result' === $context && $executionTime < $this->minExecutionTimeMs) {
                            continue;
                        }

                        // Deduplicate based on ORDER BY clause
                        $key = md5($orderByClause);
                        if (isset($seenIssues[$key])) {
                            continue;
                        }

                        $seenIssues[$key] = true;

                        yield $this->createOrderByWithoutLimitIssue(
                            $orderByClause,
                            $sql,
                            $executionTime,
                            $query,
                            $context,
                        );
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'ORDER BY Without LIMIT Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects ORDER BY clauses without LIMIT that can cause unnecessary sorting of large datasets';
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
     * Extract execution time from query data.
     */
    private function extractExecutionTime(array|object $query): float
    {
        if (is_array($query)) {
            return (float) ($query['executionMS'] ?? 0);
        }

        return (is_object($query) && property_exists($query, 'executionTime')) ? ($query->executionTime?->inMilliseconds() ?? 0.0) : 0.0;
    }

    /**
     * Create issue for ORDER BY without LIMIT.
     */
    private function createOrderByWithoutLimitIssue(
        string $orderByClause,
        string $sql,
        float $executionTime,
        array|object $query,
        string $context,
    ): PerformanceIssue {
        $backtrace = $this->extractBacktrace($query);

        // Adapt severity and message based on context
        [$severity, $title, $description] = $this->buildIssueDetails(
            $orderByClause,
            $executionTime,
            $context,
        );

        $issueData = new IssueData(
            type: 'order_by_without_limit',
            title: $title,
            description: $description,
            severity: $severity,
            suggestion: $this->createOrderByWithoutLimitSuggestion($orderByClause, $sql, $context),
            queries: [$query], // @phpstan-ignore argument.type (query is QueryData from collection)
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
     * Detect if the query has a WHERE ... IN (...) clause, indicating a bounded result set.
     */
    private function hasBoundedWhereInClause(string $sql): bool
    {
        return 1 === preg_match('/\bWHERE\b.*\bIN\s*\(/i', $sql);
    }

    /**
     * Detect query context from backtrace to identify single-result vs array-result queries.
     * Returns 'single_result', 'array_result', or 'unknown'.
     */
    private function detectQueryContext(array|object $query): string
    {
        $backtrace = $this->extractBacktrace($query);

        if (empty($backtrace)) {
            return 'unknown';
        }

        // Single-result method indicators in backtrace
        $singleResultIndicators = [
            'getOneOrNullResult',
            'getSingleResult',
            'fetchOne',
            'fetchAssociative', // DBAL single row
            'findOneBy',
            'findOne',
        ];

        // Array-result method indicators
        $arrayResultIndicators = [
            'getResult',
            'getArrayResult',
            'fetchAllAssociative',
            'fetchAll',
            'findBy',
            'findAll',
        ];

        foreach ($backtrace as $frame) {
            $function = $frame['function'] ?? '';

            // Check for single-result methods
            foreach ($singleResultIndicators as $indicator) {
                if (false !== stripos((string) $function, $indicator)) {
                    return 'single_result';
                }
            }

            // Check for array-result methods
            foreach ($arrayResultIndicators as $indicator) {
                if (false !== stripos((string) $function, $indicator)) {
                    return 'array_result';
                }
            }
        }

        return 'unknown';
    }

    /**
     * Build issue details (severity, title, description) based on context.
     * @return array{Severity, string, string}
     */
    private function buildIssueDetails(
        string $orderByClause,
        float $executionTime,
        string $context,
    ): array {
        $executionTimeStr = sprintf('%.2fms', $executionTime);

        // Single result context: less severe, but still worth noting for slow queries
        if ('single_result' === $context) {
            // If we reach here, execution time is >= 10ms (fast queries are skipped)
            $severity = $executionTime > 100 ? Severity::warning() : Severity::info();
            $title = sprintf('ORDER BY in Single-Result Query (%s)', $executionTimeStr);
            $description = sprintf(
                "Query uses ORDER BY without LIMIT in a single-result context (getOneOrNullResult/getSingleResult). " .
                "While the method returns only one result, the database still processes ORDER BY on all matching rows. " .
                "Execution time: %s. Consider adding setMaxResults(1) before calling getOneOrNullResult() " .
                "to limit database processing. ORDER BY clause: %s",
                $executionTimeStr,
                $orderByClause,
            );

            return [$severity, $title, $description];
        }

        // Array result context: more severe, likely pagination missing
        if ('array_result' === $context) {
            $severity = match (true) {
                $executionTime > 500 => Severity::critical(),
                $executionTime > 100 => Severity::warning(),
                $executionTime >= $this->minExecutionTimeMs => Severity::warning(),
                default => Severity::info(),
            };
            $title = sprintf('ORDER BY Without LIMIT in Array Query (%s)', $executionTimeStr);
            $description = sprintf(
                "Query uses ORDER BY without LIMIT and returns an array of results (getResult/findBy). " .
                "This sorts the entire result set without limiting rows. " .
                "Current execution time is %s, but as data volume grows in production this query will become significantly slower. " .
                "Add LIMIT for pagination or to restrict the number of returned rows. " .
                "ORDER BY clause: %s",
                $executionTimeStr,
                $orderByClause,
            );

            return [$severity, $title, $description];
        }

        // Unknown context: default behavior
        $severity = match (true) {
            $executionTime > 500 => Severity::critical(),
            $executionTime > 100 => Severity::warning(),
            default => Severity::info(),
        };
        $title = 'ORDER BY Without LIMIT Detected';
        $description = sprintf(
            "Query uses ORDER BY without LIMIT, potentially sorting the entire table. " .
            "Execution time: %s. Consider adding LIMIT for pagination or if you only need the top N results. " .
            "ORDER BY clause: %s",
            $executionTimeStr,
            $orderByClause,
        );

        return [$severity, $title, $description];
    }

    /**
     * Create suggestion for ORDER BY without LIMIT.
     */
    private function createOrderByWithoutLimitSuggestion(
        string $orderByClause,
        string $sql,
        string $context,
    ): mixed {
        // Adapt suggestion based on context
        $suggestionTitle = match ($context) {
            'single_result' => 'Add setMaxResults(1) before getOneOrNullResult()',
            'array_result' => 'Add LIMIT to paginate results',
            default => 'Add LIMIT to ORDER BY query',
        };

        $suggestionSeverity = match ($context) {
            'single_result' => Severity::info(),
            'array_result' => Severity::warning(),
            default => Severity::warning(),
        };

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/order_by_without_limit',
            context: [
                'order_by_clause' => $orderByClause,
                'original_query' => $sql,
                'query_context' => $context,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $suggestionSeverity,
                title: $suggestionTitle,
                tags: ['performance', 'pagination', 'order-by', 'optimization'],
            ),
        );
    }
}
