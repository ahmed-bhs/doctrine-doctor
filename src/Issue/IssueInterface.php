<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Issue;

use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;

interface IssueInterface
{
    public function getType(): string;

    public function getTitle(): string;

    public function getDescription(): string;

    public function getSeverity(): Severity;

    /**
     * Get the category of this issue for profiler organization.
     * Must return one of the IssueCategory constants:
     * - IssueCategory::PERFORMANCE
     * - IssueCategory::SECURITY
     * - IssueCategory::INTEGRITY
     * - IssueCategory::CONFIGURATION
     * @return string One of the IssueCategory constants
     */
    public function getCategory(): string;

    public function getSuggestion(): ?SuggestionInterface;

    public function getBacktrace(): ?array;

    public function getQueries(): array;

    /**
     * Get the raw data array for this issue.
     * @return array<string, mixed>
     */
    public function getData(): array;

    public function toArray(): array;

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
