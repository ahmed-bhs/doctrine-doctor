<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collection;

use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;

/**
 * Type-safe collection for IssueInterface objects.
 * Provides issue-specific filtering, sorting and grouping.
 * @extends AbstractCollection<IssueInterface>
 */
final class IssueCollection extends AbstractCollection
{
    /**
     * Get filter helper for this collection.
     */
    public function filtering(): IssueFilter
    {
        return new IssueFilter($this);
    }

    /**
     * Get sorter helper for this collection.
     */
    public function sorting(): IssueSorter
    {
        return new IssueSorter($this);
    }

    /**
     * Get statistics helper for this collection.
     */
    public function statistics(): IssueStatistics
    {
        return new IssueStatistics($this);
    }

    /**
     * Convert all issues to array format.
     * @return array<int, array<string, mixed>>
     */
    public function toArrayOfArrays(): array
    {
        return $this->map(fn (IssueInterface $issue): array => $issue->toArray());
    }

    /**
     * Merge with another issue collection.
     */
    public function merge(self $other): self
    {
        return self::fromArray(array_merge($this->toArray(), $other->toArray()));
    }

    // Convenience methods that delegate to helper classes

    /**
     * Filter issues by severity.
     */
    public function filterBySeverity(string $severity): self
    {
        return $this->filtering()->bySeverity($severity);
    }

    /**
     * Get only critical issues.
     */
    public function onlyCritical(): self
    {
        return $this->filtering()->onlyCritical();
    }

    /**
     * Get only warning issues.
     */
    public function onlyWarnings(): self
    {
        return $this->filtering()->onlyWarnings();
    }

    /**
     * Filter issues by type.
     */
    public function filterByType(string $type): self
    {
        return $this->filtering()->byType($type);
    }

    /**
     * Group issues by severity.
     * @return array<string, IssueCollection>
     */
    public function groupBySeverity(): array
    {
        return $this->statistics()->groupBySeverity();
    }

    /**
     * Group issues by type.
     * @return array<string, IssueCollection>
     */
    public function groupByType(): array
    {
        return $this->statistics()->groupByType();
    }

    /**
     * Count issues by severity.
     * @return array<string, int>
     */
    public function countBySeverity(): array
    {
        return $this->statistics()->countBySeverity();
    }

    /**
     * Count issues by type.
     * @return array<string, int>
     */
    public function countByType(): array
    {
        return $this->statistics()->countByType();
    }

    /**
     * Check if collection has critical issues.
     */
    public function hasCritical(): bool
    {
        return $this->statistics()->hasCritical();
    }

    /**
     * Check if collection has warning issues.
     */
    public function hasWarnings(): bool
    {
        return $this->statistics()->hasWarnings();
    }

    /**
     * Sort issues by severity (most severe first).
     * For ascending order, use sorting()->bySeverityAscending().
     */
    public function sortBySeverity(): self
    {
        return $this->sorting()->bySeverityDescending();
    }

    /**
     * Get the most severe issue.
     */
    public function mostSevere(): ?IssueInterface
    {
        return $this->statistics()->mostSevere();
    }

    /**
     * Get unique issue types in this collection.
     * @return array<string>
     */
    public function getUniqueTypes(): array
    {
        return $this->statistics()->uniqueTypes();
    }

    /**
     * @param iterable<int, IssueInterface> $items
     */
    protected static function createInstance(iterable $items): static
    {
        return new self($items);
    }
}
