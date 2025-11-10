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
class IneffectiveLikeAnalyzer implements AnalyzerInterface
{
    /**
     * Pattern to detect LIKE with leading wildcard.
     * Matches: LIKE '%...', LIKE "-%...", etc.
     */
    private const LIKE_LEADING_WILDCARD_PATTERN = '/\bLIKE\s+([\'"])(%[^\'\"]+)\1/i';

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

                    // Detect LIKE with leading wildcard
                    if (preg_match_all(self::LIKE_LEADING_WILDCARD_PATTERN, $sql, $matches, PREG_SET_ORDER) >= 1) {
                        assert(is_iterable($matches), '$matches must be iterable');

                        foreach ($matches as $match) {
                            $pattern = $match[2]; // The LIKE pattern (e.g., '%search%')

                            // Only flag if wildcard is at the beginning
                            if (!str_starts_with($pattern, '%')) {
                                continue;
                            }

                            // Deduplicate
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
     * Create issue for ineffective LIKE pattern.
     */
    private function createIneffectiveLikeIssue(
        string $pattern,
        string $sql,
        float $executionTime,
        array|object $query,
    ): PerformanceIssue {
        $backtrace = $this->extractBacktrace($query);

        // Determine severity based on execution time and pattern
        $severity = match (true) {
            $executionTime > 200 => Severity::critical(),
            $executionTime > 50 => Severity::warning(),
            default => Severity::info(),
        };

        $likeType = $this->getLikeType($pattern);

        $issueData = new IssueData(
            type: 'ineffective_like_pattern',
            title: 'Ineffective LIKE Pattern Detected',
            description: sprintf(
                "Query uses LIKE with leading wildcard (%s), forcing full table scan. " .
                "This query took %.2fms. Consider using full-text search or moving wildcard to the end if possible. " .
                "Pattern: LIKE '%s'",
                $likeType,
                $executionTime,
                $pattern,
            ),
            severity: $severity,
            suggestion: $this->createIneffectiveLikeSuggestion($pattern, $sql, $likeType),
            queries: [],
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
     * Create suggestion for ineffective LIKE pattern.
     */
    private function createIneffectiveLikeSuggestion(
        string $pattern,
        string $sql,
        string $likeType,
    ): mixed {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'ineffective_like',
            context: [
                'pattern' => $pattern,
                'like_type' => $likeType,
                'original_query' => $sql,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: 'Replace LIKE with full-text search or optimize pattern',
                tags: ['performance', 'index', 'like', 'full-text-search'],
            ),
        );
    }
}
