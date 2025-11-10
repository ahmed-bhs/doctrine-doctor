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
 * Provides statistical analysis and grouping capabilities for IssueCollection.
 * Follows Single Responsibility Principle.
 */
final class IssueStatistics
{
    /**
     * Severity levels in order of importance.
     */
    private const SEVERITY_ORDER = [
        'critical' => 0,
        'error'    => 1,
        'warning'  => 2,
        'info'     => 3,
        'notice'   => 4,
    ];

    public function __construct(
        /**
         * @readonly
         */
        private IssueCollection $issueCollection,
    ) {
    }

    /**
     * Group issues by severity.
     * @return array<string, IssueCollection>
     */
    public function groupBySeverity(): array
    {
        return $this->issueCollection->groupBy(fn (IssueInterface $issue): string => $issue->getSeverity()->value);
    }

    /**
     * Group issues by type.
     * @return array<string, IssueCollection>
     */
    public function groupByType(): array
    {
        return $this->issueCollection->groupBy(fn (IssueInterface $issue): string => $issue->getType());
    }

    /**
     * Count issues by severity.
     * @return array<string, int>
     */
    public function countBySeverity(): array
    {
        $counts = [
            'critical' => 0,
            'error'    => 0,
            'warning'  => 0,
            'info'     => 0,
            'notice'   => 0,
        ];

        foreach ($this->issueCollection as $issue) {
            $severity          = $issue->getSeverity()->value;
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        }

        return array_filter($counts, fn (int $count): bool => $count > 0);
    }

    /**
     * Count issues by type.
     * @return array<string, int>
     */
    public function countByType(): array
    {
        $counts = [];

        foreach ($this->issueCollection as $issue) {
            $type          = $issue->getType();
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Get most severe issue.
     */
    public function mostSevere(): ?IssueInterface
    {
        $mostSevere  = null;
        $lowestOrder = 999;

        foreach ($this->issueCollection as $issue) {
            $order = self::SEVERITY_ORDER[$issue->getSeverity()->value] ?? 999;

            if ($order < $lowestOrder) {
                $lowestOrder = $order;
                $mostSevere  = $issue;
            }
        }

        return $mostSevere;
    }

    /**
     * Check if collection has critical issues.
     */
    public function hasCritical(): bool
    {
        return $this->issueCollection->any(fn (IssueInterface $issue): bool => 'critical' === $issue->getSeverity()->value);
    }

    /**
     * Check if collection has errors.
     */
    public function hasErrors(): bool
    {
        return $this->issueCollection->any(fn (IssueInterface $issue): bool => 'error' === $issue->getSeverity()->value);
    }

    /**
     * Check if collection has warnings.
     */
    public function hasWarnings(): bool
    {
        return $this->issueCollection->any(fn (IssueInterface $issue): bool => 'warning' === $issue->getSeverity()->value);
    }

    /**
     * Get all unique issue types.
     * @return array<int, string>
     */
    public function uniqueTypes(): array
    {
        $types = [];

        foreach ($this->issueCollection as $issue) {
            $types[$issue->getType()] = true;
        }

        return array_keys($types);
    }

    /**
     * Get all unique severities.
     * @return array<int, string>
     */
    public function uniqueSeverities(): array
    {
        $severities = [];

        foreach ($this->issueCollection as $issue) {
            $severities[$issue->getSeverity()->value] = true;
        }

        return array_keys($severities);
    }
}
