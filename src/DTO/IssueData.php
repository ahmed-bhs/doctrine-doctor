<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\DTO;

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
        /** @var array<mixed>
         * @readonly */
        public array $queries = [],
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
        Assert::allIsInstanceOf($queries, QueryData::class, 'All queries must be instances of QueryData');

        if (null !== $backtrace) {
            Assert::isArray($backtrace, 'Backtrace must be an array or null');
        }
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
}
