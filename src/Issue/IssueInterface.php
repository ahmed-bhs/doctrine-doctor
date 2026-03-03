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
use AhmedBhs\DoctrineDoctor\ValueObject\IssueCategory;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;

interface IssueInterface extends DeduplicatableIssueInterface
{
    public function getType(): string;

    public function getTitle(): string;

    public function getDescription(): string;

    public function getSeverity(): Severity;

    /**
     * Get the category of this issue for profiler organization.
     */
    public function getCategory(): IssueCategory;

    public function getSuggestion(): ?SuggestionInterface;

    public function getBacktrace(): ?array;

    public function getQueries(): array;

    /**
     * Get the raw data array for this issue.
     * @return array<string, mixed>
     */
    public function getData(): array;

    public function toArray(): array;
}
