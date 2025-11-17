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
use AhmedBhs\DoctrineDoctor\Cache\SqlNormalizationCache;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Webmozart\Assert\Assert;

/**
 * Detects queries with aggregations or GROUP BY that should use DTO hydration.
 * When using aggregations (SUM, COUNT, AVG, etc.) or GROUP BY, you're not loading
 * entities but computed results. Using entity hydration is inefficient and error-prone.
 * DTO hydration (NEW syntax) is 3-5x faster and more type-safe.
 * Example:
 * BAD:
 *   SELECT u.name, SUM(o.total) as revenue
 *   FROM User u JOIN u.orders o GROUP BY u.id
 *   // Returns mixed arrays, not type-safe
 *  GOOD:
 *   SELECT NEW App\DTO\UserRevenue(u.name, SUM(o.total))
 *   FROM User u JOIN u.orders o GROUP BY u.id
 *   // Returns UserRevenue DTOs, type-safe and fast
 */
class DTOHydrationAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Aggregation functions that suggest DTO hydration.
     */
    private const AGGREGATION_FUNCTIONS = [
        'SUM(',
        'COUNT(',
        'AVG(',
        'MAX(',
        'MIN(',
        'GROUP_CONCAT(',
    ];

    /**
     * Minimum occurrences to trigger warning.
     */
    private const MIN_OCCURRENCES = 2;

    private SqlStructureExtractor $sqlExtractor;

    public function __construct(
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
        //  Article pattern: Use generator instead of array
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                // Group queries with aggregations
                $aggregationQueries = $this->findAggregationQueries($queryDataCollection);

                if ([] === $aggregationQueries) {
                    return IssueCollection::empty();
                }

                // Group by pattern
                $patterns = $this->groupQueriesByPattern($aggregationQueries);

                Assert::isIterable($patterns, '$patterns must be iterable');

                foreach ($patterns as $pattern => $queryGroup) {
                    if (count($queryGroup) < self::MIN_OCCURRENCES) {
                        continue;
                    }

                    // Check if already using DTO hydration
                    Assert::string($pattern, 'Pattern key must be string');
                    if ($this->usesDTOHydration($pattern)) {
                        continue;
                    }

                    $issue = $this->createDTOHydrationIssue($queryGroup);
                    yield $issue;
                }
            },
        );
    }

    /**
     * Find queries with aggregations or GROUP BY.
     */
    private function findAggregationQueries(QueryDataCollection $queryDataCollection): array
    {

        $result = [];

        Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

        foreach ($queryDataCollection as $query) {
            $sql      = $query->sql;
            $upperSql = strtoupper($sql);

            // Check for aggregation functions
            $hasAggregation = false;

            foreach (self::AGGREGATION_FUNCTIONS as $func) {
                if (str_contains($upperSql, $func)) {
                    $hasAggregation = true;
                    break;
                }
            }

            // Check for GROUP BY
            $hasGroupBy = str_contains($upperSql, 'GROUP BY');

            if ($hasAggregation || $hasGroupBy) {
                $result[] = $query;
            }
        }

        return $result;
    }

    /**
     * Group queries by normalized pattern.
     */
    private function groupQueriesByPattern(array $queries): array
    {

        $patterns = [];

        Assert::isIterable($queries, '$queries must be iterable');

        foreach ($queries as $query) {
            $sql     = is_array($query) ? ($query['sql'] ?? '') : $query->sql;
            $pattern = $this->normalizeQuery($sql);

            if (!isset($patterns[$pattern])) {
                $patterns[$pattern] = [];
            }

            $patterns[$pattern][] = $query;
        }

        return $patterns;
    }

    /**
     * Normalizes query using universal SQL parser method.
     *
     * Migration from regex to SQL Parser:
     * - Replaced 4 regex patterns with SqlStructureExtractor::normalizeQuery()
     * - More robust: properly parses SQL structure
     * - Handles complex queries, subqueries, joins
     * - Fallback to regex if parser fails
     */
    private function normalizeQuery(string $sql): string
    {
        return SqlNormalizationCache::normalize($sql);
    }

    /**
     * Check if query already uses DTO hydration (NEW syntax).
     */
    private function usesDTOHydration(string $sql): bool
    {
        return str_contains(strtoupper($sql), 'SELECT NEW ');
    }

    /**
     * Create issue for DTO hydration opportunity.
     */
    private function createDTOHydrationIssue(array $queries): PerformanceIssue
    {
        $count      = count($queries);
        $firstQuery = $queries[0];
        $example    = is_array($firstQuery) ? ($firstQuery['sql'] ?? '') : $firstQuery->sql;

        // Detect aggregation types
        $aggregations = $this->detectAggregations($example);
        $hasGroupBy   = str_contains(strtoupper((string) $example), 'GROUP BY');

        // Calculate performance impact
        $avgTime              = $this->calculateAverageTime($queries);
        $estimatedImprovement = $this->estimateImprovement($avgTime, $count);

        $performanceIssue = new PerformanceIssue([
            'query_count'           => $count,
            'example_query'         => $example,
            'aggregations'          => $aggregations,
            'has_group_by'          => $hasGroupBy,
            'avg_time'              => $avgTime,
            'estimated_improvement' => $estimatedImprovement,
        ]);

        $performanceIssue->setSeverity($count > 5 ? 'critical' : 'warning');
        $performanceIssue->setTitle('Aggregation Query Without DTO Hydration');
        $performanceIssue->setMessage(
            sprintf('Detected %d queries with aggregations/GROUP BY that should use DTO hydration. ', $count) .
            'Using the NEW syntax with DTOs is 3-5x faster, type-safe, and more maintainable than array results.',
        );
        $performanceIssue->setSuggestion($this->createDTOSuggestion($aggregations, $hasGroupBy));

        return $performanceIssue;
    }

    /**
     * Detect which aggregation functions are used.
     */
    private function detectAggregations(string $sql): array
    {

        $found    = [];
        $upperSql = strtoupper($sql);

        foreach (self::AGGREGATION_FUNCTIONS as $func) {
            if (str_contains($upperSql, $func)) {
                $found[] = rtrim($func, '(');
            }
        }

        return $found;
    }

    /**
     * Calculate average execution time.
     */
    private function calculateAverageTime(array $queries): float
    {
        $total = 0;
        $count = 0;

        Assert::isIterable($queries, '$queries must be iterable');

        foreach ($queries as $query) {
            if (is_array($query)) {
                if (isset($query['executionMS'])) {
                    $total += $query['executionMS'];
                    ++$count;
                }
            } else {
                $total += $query->executionTime->inMilliseconds();
                ++$count;
            }
        }

        return $count > 0 ? round($total / $count, 2) : 0;
    }

    /**
     * Estimate performance improvement.
     */
    private function estimateImprovement(float $avgTime, int $count): string
    {
        // Conservative: 3x faster, 70% less memory
        $timeSaved   = round($avgTime * 0.66 * $count, 2);
        $memorySaved = 70;

        return sprintf('~%sms faster, %d%% less memory', $timeSaved, $memorySaved);
    }

    /**
     * Create detailed suggestion with DTO example.
     */
    private function createDTOSuggestion(array $aggregations, bool $hasGroupBy): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/dto_hydration',
            context: [
                'query_count'  => [] !== $aggregations ? 1 : 0,
                'aggregations' => $aggregations,
                'has_group_by' => $hasGroupBy,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: 'Aggregation Query Without DTO Hydration',
                tags: ['performance', 'dto', 'aggregation'],
            ),
        );
    }
}
