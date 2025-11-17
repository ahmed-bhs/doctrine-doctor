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
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Webmozart\Assert\Assert;

/**
 * Detects date/time functions used on indexed columns that prevent index usage.
 * Using functions like YEAR(), MONTH(), DAY() on date columns in WHERE clause
 * prevents the database from using indexes, resulting in full table scans.
 * Example:
 * BAD:
 *   WHERE YEAR(created_at) = 2023        -- Index not used!
 *   WHERE MONTH(created_at) = 12         -- Full table scan!
 *   WHERE DATE(created_at) = '2023-01-01' -- Slow!
 * GOOD:
 *   WHERE created_at BETWEEN '2023-01-01' AND '2023-12-31'  -- Index used!
 *   WHERE created_at >= '2023-12-01' AND created_at < '2024-01-01'
 */
class YearFunctionOptimizationAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    public function __construct(
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
        /**
         * @readonly
         */
        private ?SqlStructureExtractor $sqlExtractor = null,
    ) {
        // Dependency injection with fallback for backwards compatibility
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
                    $sql            = $this->extractSQL($query);
                    $executionTime  = $this->extractExecutionTime($query);
                    if ('' === $sql) {
                        continue;
                    }

                    if ('0' === $sql) {
                        continue;
                    }

                    // Use SQL Parser instead of regex for robust detection
                    // This properly handles complex WHERE clauses and nested conditions
                    if (null === $this->sqlExtractor) {
                        continue;
                    }

                    $functionCalls = $this->sqlExtractor->extractFunctionsInWhere($sql);

                    Assert::isIterable($functionCalls, '$functionCalls must be iterable');

                    foreach ($functionCalls as $call) {
                        $function = $call['function'];
                        $field    = $call['field'];
                        $operator = $call['operator'];
                        $value    = $call['value'];

                        // Deduplicate
                        $key = $function . '(' . $field . ')' . $operator . $value;
                        if (isset($seenIssues[$key])) {
                            continue;
                        }

                        $seenIssues[$key] = true;

                        yield $this->createDateFunctionIssue($function, $field, $operator, $value, $executionTime, $query);
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Date Function Optimization Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects date/time functions on indexed columns that prevent index usage and cause performance issues';
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
     * Create issue for date function usage.
     */
    private function createDateFunctionIssue(
        string $function,
        string $field,
        string $operator,
        string $value,
        float $executionTime,
        array|object $query,
    ): PerformanceIssue {
        $backtrace       = $this->extractBacktrace($query);
        $optimizedClause = $this->generateOptimizedClause($function, $field, $operator, $value);

        $issueData = new IssueData(
            type: 'date_function_prevents_index',
            title: sprintf('%s() Function Prevents Index Usage', $function),
            description: sprintf(
                "Using %s(%s) in WHERE clause prevents index usage, causing full table scan. " .
                "This query took %.2fms. Rewrite using BETWEEN or range operators for better performance.",
                $function,
                $field,
                $executionTime,
            ),
            severity: $executionTime > 100 ? Severity::critical() : Severity::warning(),
            suggestion: $this->createDateFunctionSuggestion($function, $field, $operator, $value, $optimizedClause),
            queries: [],
            backtrace: $backtrace,
        );

        return new PerformanceIssue($issueData->toArray());
    }

    /**
     * Generate optimized WHERE clause.
     */
    private function generateOptimizedClause(string $function, string $field, string $operator, string $value): string
    {
        // Remove quotes from value if present
        $value = trim($value, "'\"");

        return match ($function) {
            'YEAR'  => $this->optimizeYearClause($field, $operator, $value),
            'MONTH' => $this->optimizeMonthClause($field, $value),
            'DATE'  => $this->optimizeDateClause($field, $operator, $value),
            default => sprintf('%s BETWEEN ... AND ...', $field),
        };
    }

    /**
     * Optimize YEAR() function.
     */
    private function optimizeYearClause(string $field, string $operator, string $value): string
    {
        if ('=' === $operator) {
            return sprintf("%s BETWEEN '%s-01-01' AND '%s-12-31'", $field, $value, $value);
        }

        if ('>=' === $operator) {
            return sprintf("%s >= '%s-01-01'", $field, $value);
        }

        if ('>' === $operator) {
            $nextYear = (int) $value + 1;

            return sprintf("%s >= '%s-01-01'", $field, $nextYear);
        }

        return sprintf("%s >= '%s-01-01' AND %s <= '%s-12-31'", $field, $value, $field, $value);
    }

    /**
     * Optimize MONTH() function - simplified version.
     */
    private function optimizeMonthClause(string $field, string $value): string
    {
        // For simplicity, suggest BETWEEN pattern
        return sprintf("%s BETWEEN 'YYYY-%02d-01' AND 'YYYY-%02d-31'", $field, (int) $value, (int) $value);
    }

    /**
     * Optimize DATE() function.
     */
    private function optimizeDateClause(string $field, string $operator, string $value): string
    {
        if ('=' === $operator) {
            return sprintf("%s BETWEEN '%s 00:00:00' AND '%s 23:59:59'", $field, $value, $value);
        }

        return sprintf("%s %s '%s'", $field, $operator, $value);
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
     * Create suggestion for optimizing date function.
     */
    private function createDateFunctionSuggestion(
        string $function,
        string $field,
        string $operator,
        string $value,
        string $optimizedClause,
    ): mixed {
        $originalClause = sprintf('%s(%s) %s %s', $function, $field, $operator, $value);

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/date_function_optimization',
            context: [
                'function'         => $function,
                'field'            => $field,
                'original_clause'  => $originalClause,
                'optimized_clause' => $optimizedClause,
                'operator'         => $operator,
                'value'            => $value,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: sprintf('Replace %s() with range comparison', $function),
                tags: ['performance', 'index', 'date', 'optimization'],
            ),
        );
    }
}
