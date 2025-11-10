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
class OrderByWithoutLimitAnalyzer implements AnalyzerInterface
{
    /**
     * Pattern to detect ORDER BY without LIMIT.
     * Matches queries with ORDER BY but no LIMIT/OFFSET clause.
     */
    private const ORDER_BY_PATTERN = '/\bORDER\s+BY\s+([^;]+?)(?:\s+(?:LIMIT|OFFSET)|;|\s*$)/is';

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
                    $executionTime = $this->extractExecutionTime($query);
                    if ('' === $sql) {
                        continue;
                    }

                    if ('0' === $sql) {
                        continue;
                    }

                    // Skip if query already has LIMIT or OFFSET
                    if (1 === preg_match('/\b(?:LIMIT|OFFSET)\b/i', $sql)) {
                        continue;
                    }

                    // Detect ORDER BY
                    if (1 === preg_match(self::ORDER_BY_PATTERN, $sql, $matches)) {
                        $orderByClause = trim($matches[1]);

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
    ): PerformanceIssue {
        $backtrace = $this->extractBacktrace($query);

        // Determine severity based on execution time
        $severity = match (true) {
            $executionTime > 500 => Severity::critical(),
            $executionTime > 100 => Severity::warning(),
            default => Severity::info(),
        };

        $issueData = new IssueData(
            type: 'order_by_without_limit',
            title: 'ORDER BY Without LIMIT Detected',
            description: sprintf(
                "Query uses ORDER BY without LIMIT, potentially sorting entire table. " .
                "This query took %.2fms. Consider adding LIMIT for pagination or if you only need top N results. " .
                "ORDER BY clause: %s",
                $executionTime,
                $orderByClause,
            ),
            severity: $severity,
            suggestion: $this->createOrderByWithoutLimitSuggestion($orderByClause, $sql),
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
     * Create suggestion for ORDER BY without LIMIT.
     */
    private function createOrderByWithoutLimitSuggestion(
        string $orderByClause,
        string $sql,
    ): mixed {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'order_by_without_limit',
            context: [
                'order_by_clause' => $orderByClause,
                'original_query' => $sql,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: 'Add LIMIT to ORDER BY query',
                tags: ['performance', 'pagination', 'order-by', 'optimization'],
            ),
        );
    }
}
