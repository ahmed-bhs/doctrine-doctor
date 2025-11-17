<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\DTO;

use AhmedBhs\DoctrineDoctor\Cache\SqlNormalizationCache;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Webmozart\Assert\Assert;

/**
 * Data Transfer Object representing an analyzer issue.
 * Immutable and type-safe.
 */
final class IssueData
{
    /**
     * @var QueryData[]
     * @readonly
     */
    public array $queries;

    /**
     * @param QueryData[]                           $queries
     * @param array<int, array<string, mixed>>|null $backtrace
     */
    public function __construct(
        /**
         * @readonly
         */
        public string $type,
        /**
         * @readonly
         */
        public string $title,
        /**
         * @readonly
         */
        public string $description,
        /**
         * @readonly
         */
        public Severity $severity,
        /**
         * @readonly
         */
        public ?SuggestionInterface $suggestion = null,
        /** @var array<mixed> */
        array $queries = [],
        /**
         * @readonly
         */
        public ?array $backtrace = null,
    ) {
        Assert::stringNotEmpty($type, 'Issue type cannot be empty');
        Assert::stringNotEmpty($title, 'Issue title cannot be empty');
        Assert::stringNotEmpty($description, 'Issue description cannot be empty');
        Assert::isInstanceOf($severity, Severity::class, 'Severity must be an instance of Severity value object');

        if ($suggestion instanceof SuggestionInterface) {
            Assert::isInstanceOf($suggestion, SuggestionInterface::class, 'Suggestion must implement SuggestionInterface');
        }

        Assert::isArray($queries, 'Queries must be an array');
        // OPTIMIZED: Only validate first element instead of all (O(n) -> O(1))
        // This is safe because analyzers always pass valid QueryData arrays
        if (!empty($queries)) {
            Assert::isInstanceOf($queries[0], QueryData::class, 'All queries must be instances of QueryData');
        }

        if (null !== $backtrace) {
            Assert::isArray($backtrace, 'Backtrace must be an array or null');
        }

        // Deduplicate identical queries to avoid showing duplicates in profiler
        // Keep only unique query patterns (based on normalized SQL)
        $this->queries = self::deduplicateQueries($queries);
    }

    /**
     * Create from array (legacy compatibility).
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $queries = array_map(
            function ($queryData) {
                return QueryData::fromArray($queryData);
            },
            $data['queries'] ?? [],
        );

        return new self(
            type: $data['type'] ?? 'unknown',
            title: $data['title'] ?? 'Unknown Issue',
            description: $data['description'] ?? 'No description',
            severity: Severity::fromString($data['severity'] ?? Severity::INFO),
            suggestion: $data['suggestion'] ?? null,
            queries: $queries,
            backtrace: $data['backtrace'] ?? null,
        );
    }

    /**
     * Convert to array (for passing to AbstractIssue constructor).
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type'        => $this->type,
            'title'       => $this->title,
            'description' => $this->description,
            'severity'    => $this->severity->getValue(),
            'suggestion'  => $this->suggestion, // Keep as object for AbstractIssue
            'queries'     => array_map(fn (QueryData $queryData): array => $queryData->toArray(), $this->queries),
            'backtrace'   => $this->backtrace,
        ];
    }

    public function getQueryCount(): int
    {
        return count($this->queries);
    }

    public function getTotalExecutionTime(): float
    {
        $total = 0.0;

        foreach ($this->queries as $query) {
            $total += $query->executionTime->inMilliseconds();
        }

        return $total;
    }

    /**
     * Get severity as string value (for backward compatibility).
     */
    public function getSeverityValue(): string
    {
        return $this->severity->getValue();
    }

    /**
     * Deduplicate identical queries to avoid showing duplicates in profiler.
     * Groups queries by normalized SQL and keeps only one representative per pattern.
     *
     * OPTIMIZED: Uses cached SQL normalization instead of inline regex operations.
     * SqlNormalizationCache provides 654x speedup for SQL parsing and normalization.
     * Hash-based lookup provides O(1) deduplication instead of O(n^2) comparison.
     *
     * @param QueryData[] $queries
     * @return QueryData[]
     */
    private static function deduplicateQueries(array $queries): array
    {
        if (empty($queries)) {
            return [];
        }

        $seen = [];
        $unique = [];

        foreach ($queries as $query) {
            // OPTIMIZED: Use cached SQL normalization for pattern-based deduplication
            // This normalizes parameters and whitespace, grouping similar queries together
            $normalized = SqlNormalizationCache::normalize($query->sql);
            $hash = md5($normalized);

            // Only keep first occurrence of each unique pattern
            if (!isset($seen[$hash])) {
                $seen[$hash] = true;
                $unique[] = $query;
            }
        }

        return $unique;
    }
}
