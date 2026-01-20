<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

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
 * Detects LIKE patterns with leading wildcards that prevent index usage.
 * Using LIKE '%search%' or LIKE '%search' forces a full table scan because
 * the database cannot use indexes when the wildcard is at the beginning.
 * This is a guaranteed performance killer on large tables.
 * Example:
 * BAD:
 *   WHERE name LIKE '%John%'     -- Full table scan!
 *   WHERE email LIKE '%@example.com'  -- Cannot use index!
 *  ACCEPTABLE:
 *   WHERE name LIKE 'John%'      -- Can use index
 * BEST:
 *   WHERE MATCH(name) AGAINST('John')  -- Full-text search
 */
class IneffectiveLikeAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Pattern to detect LIKE with leading wildcard.
     * Matches: LIKE '%...', LIKE "-%...", etc.
     */
    private const LIKE_LEADING_WILDCARD_PATTERN = '/\bLIKE\s+([\'"])(%[^\'\"]+)\1/i';

    /**
     * Minimum execution time (ms) to report an issue.
     * Queries faster than this are likely on small tables where LIKE performance is negligible.
     */
    private const MIN_EXECUTION_TIME_THRESHOLD = 5.0;

    public function __construct(
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
        /**
         * @readonly
         * Minimum execution time in ms to report. Set to 0 to always report.
         */
        private float $minExecutionTimeThreshold = self::MIN_EXECUTION_TIME_THRESHOLD,
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

                Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $query) {
                    $sql = $this->extractSQL($query);
                    $executionTime = $this->extractExecutionTime($query);
                    $params = $this->extractParams($query);

                    if ('' === $sql) {
                        continue;
                    }

                    if ('0' === $sql) {
                        continue;
                    }

                    if ($executionTime < $this->minExecutionTimeThreshold) {
                        continue;
                    }

                    if (preg_match_all(self::LIKE_LEADING_WILDCARD_PATTERN, $sql, $matches, PREG_SET_ORDER) >= 1) {
                        Assert::isIterable($matches, '$matches must be iterable');

                        foreach ($matches as $match) {
                            $pattern = $match[2]; // The LIKE pattern (e.g., '%search%')

                            if (!str_starts_with($pattern, '%')) {
                                continue;
                            }

                            $key = md5($pattern);
                            if (isset($seenIssues[$key])) {
                                continue;
                            }

                            $seenIssues[$key] = true;

                            yield $this->createIneffectiveLikeIssue(
                                $pattern,
                                $sql,
                                $executionTime,
                                $query,
                            );
                        }
                    }

                    if (str_contains(strtoupper($sql), 'LIKE') && !empty($params)) {
                        foreach ($params as $param) {
                            if (is_string($param) && str_starts_with($param, '%')) {
                                $key = md5($param);
                                if (isset($seenIssues[$key])) {
                                    continue;
                                }

                                $seenIssues[$key] = true;

                                yield $this->createIneffectiveLikeIssue(
                                    $param,
                                    $sql,
                                    $executionTime,
                                    $query,
                                );
                            }
                        }
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Ineffective LIKE Pattern Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects LIKE patterns with leading wildcards that prevent index usage and cause full table scans';
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
     * Extract parameters from query data.
     * @return array<mixed>
     */
    private function extractParams(array|object $query): array
    {
        if (is_array($query)) {
            $params = $query['params'] ?? [];
            return is_array($params) ? $params : [];
        }

        if (is_object($query) && property_exists($query, 'params')) {
            $params = $query->params ?? [];
            return is_array($params) ? $params : [];
        }

        return [];
    }

    /**
     * Create issue for ineffective LIKE pattern.
     */
    private function createIneffectiveLikeIssue(
        string $pattern,
        string $sql,
        float $executionTime,
        array|object $query,
    ): PerformanceIssue {
        $backtrace = $this->extractBacktrace($query);

        $severity = match (true) {
            $executionTime >= 100 => Severity::critical(),
            default => Severity::warning(), // Always warn - pattern prevents index usage
        };

        $likeType = $this->getLikeType($pattern);

        [$title, $description] = $this->buildTitleAndDescription($pattern, $likeType, $executionTime, $severity);

        $issueData = new IssueData(
            type: 'ineffective_like_pattern',
            title: $title,
            description: $description,
            severity: $severity,
            suggestion: $this->createIneffectiveLikeSuggestion($pattern, $sql, $likeType, $executionTime, $severity),
            queries: [$query], // @phpstan-ignore argument.type (query is QueryData from collection)
            backtrace: $backtrace,
        );

        return new PerformanceIssue($issueData->toArray());
    }

    /**
     * Determine LIKE type based on wildcard position.
     */
    private function getLikeType(string $pattern): string
    {
        $startsWithWildcard = str_starts_with($pattern, '%');
        $endsWithWildcard = str_ends_with($pattern, '%');

        return match (true) {
            $startsWithWildcard && $endsWithWildcard => 'contains search',
            $startsWithWildcard => 'ends-with search',
            default => 'prefix search',
        };
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
     * Build title and description based on execution time and severity.
     * @return array{string, string}
     */
    private function buildTitleAndDescription(
        string $pattern,
        string $likeType,
        float $executionTime,
        Severity $severity,
    ): array {
        $executionTimeStr = sprintf('%.2fms', $executionTime);

        if ($severity->isCritical()) {
            $title = sprintf('LIKE Pattern Causing Slow Query (%s)', $executionTimeStr);
            $description = sprintf(
                "Query uses LIKE with leading wildcard (%s), forcing full table scan. " .
                "**This query is already slow (%s)** and will get worse as data grows. " .
                "Immediate action required: Consider using full-text search (MATCH AGAINST) or redesigning the search functionality. " .
                "Pattern: LIKE '%s'",
                $likeType,
                $executionTimeStr,
                $pattern,
            );
        } else {
            $title = sprintf('LIKE Pattern Prevents Index Usage (%s)', $executionTimeStr);
            $description = sprintf(
                "Query uses LIKE with leading wildcard (%s), which **prevents index usage and forces full table scan**. " .
                "Current performance is %s, but this pattern will degrade significantly as your dataset grows (common in production). " .
                "**Action recommended**: Use full-text search (MATCH AGAINST) for better scalability, or redesign to avoid leading wildcards. " .
                "Pattern: LIKE '%s'",
                $likeType,
                $executionTimeStr,
                $pattern,
            );
        }

        return [$title, $description];
    }

    /**
     * Create suggestion for ineffective LIKE pattern.
     */
    private function createIneffectiveLikeSuggestion(
        string $pattern,
        string $sql,
        string $likeType,
        float $executionTime,
        Severity $severity,
    ): mixed {
        $suggestionSeverity = $severity;
        $suggestionTitle = $severity->isCritical()
            ? 'Replace LIKE with full-text search (urgent performance issue)'
            : 'Replace LIKE with full-text search (prevents index usage)';

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/ineffective_like',
            context: [
                'pattern' => $pattern,
                'like_type' => $likeType,
                'original_query' => $sql,
                'execution_time' => $executionTime,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $suggestionSeverity,
                title: $suggestionTitle,
                tags: ['performance', 'index', 'like', 'full-text-search'],
            ),
        );
    }
}
