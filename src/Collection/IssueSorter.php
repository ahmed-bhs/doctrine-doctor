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
 * Provides sorting capabilities for IssueCollection.
 * Follows Single Responsibility Principle.
 */
final class IssueSorter
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
     * Sort by severity (most severe first).
     */
    public function bySeverityDescending(): IssueCollection
    {
        $items = $this->issueCollection->toArray();
        usort($items, function (IssueInterface $issueA, IssueInterface $issueB): int {
            $orderA = self::SEVERITY_ORDER[$issueA->getSeverity()->value] ?? 999;
            $orderB = self::SEVERITY_ORDER[$issueB->getSeverity()->value] ?? 999;

            return $orderA <=> $orderB;
        });

        return IssueCollection::fromArray($items);
    }

    /**
     * Sort by severity (least severe first).
     */
    public function bySeverityAscending(): IssueCollection
    {
        $items = $this->issueCollection->toArray();
        usort($items, function (IssueInterface $issueA, IssueInterface $issueB): int {
            $orderA = self::SEVERITY_ORDER[$issueA->getSeverity()->value] ?? 999;
            $orderB = self::SEVERITY_ORDER[$issueB->getSeverity()->value] ?? 999;

            return $orderB <=> $orderA;
        });

        return IssueCollection::fromArray($items);
    }

    /**
     * Sort by type alphabetically (A-Z).
     */
    public function byTypeAscending(): IssueCollection
    {
        $items = $this->issueCollection->toArray();
        usort($items, fn (IssueInterface $issueA, IssueInterface $issueB): int => strcmp($issueA->getType(), $issueB->getType()));

        return IssueCollection::fromArray($items);
    }

    /**
     * Sort by type alphabetically (Z-A).
     */
    public function byTypeDescending(): IssueCollection
    {
        $items = $this->issueCollection->toArray();
        usort($items, fn (IssueInterface $issueA, IssueInterface $issueB): int => strcmp($issueB->getType(), $issueA->getType()));

        return IssueCollection::fromArray($items);
    }
}
