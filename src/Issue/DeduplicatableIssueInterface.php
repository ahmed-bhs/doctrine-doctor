<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Issue;

/**
 * Dedicated contract for issues that can hold deduplicated sibling issues.
 * Keeps deduplication concern out of the base IssueInterface.
 */
interface DeduplicatableIssueInterface extends IssueInterface
{
    /**
     * Get issues that were deduplicated and hidden because they resemble this one.
     * @return IssueInterface[]
     */
    public function getDuplicatedIssues(): array;

    /**
     * Add an issue that was deduplicated and hidden.
     */
    public function addDuplicatedIssue(IssueInterface $issue): void;

    /**
     * Set all duplicated issues at once.
     * @param IssueInterface[] $issues
     */
    public function setDuplicatedIssues(array $issues): void;
}
