<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Collection;

use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Webmozart\Assert\Assert;

/**
 * Provides filtering capabilities for IssueCollection.
 * Follows Single Responsibility Principle.
 */
final class IssueFilter
{
    /**
     * Severity levels in order of importance (3-level system).
     */
    private const SEVERITY_ORDER = [
        'critical' => 0,
        'warning'  => 1,
        'info'     => 2,
    ];

    public function __construct(
        /**
         * @readonly
         */
        private IssueCollection $issueCollection,
    ) {
    }

    /**
     * Filter issues by severity (type-safe enum version).
     */
    public function bySeverityEnum(Severity $severity): IssueCollection
    {
        return $this->issueCollection->filter(fn (IssueInterface $issue): bool => $issue->getSeverity() === $severity);
    }

    /**
     * Filter issues by severity (backward compatible string version).
     *
     * @deprecated Use bySeverityEnum() with Severity enum instead
     */
    public function bySeverity(string $severity): IssueCollection
    {
        Assert::stringNotEmpty($severity, 'Severity cannot be empty');
        Assert::keyExists(self::SEVERITY_ORDER, $severity, 'Invalid severity "%s". Must be one of: ' . implode(', ', array_keys(self::SEVERITY_ORDER)));

        return $this->bySeverityEnum(Severity::from($severity));
    }

    /**
     * Get only critical issues.
     */
    public function onlyCritical(): IssueCollection
    {
        return $this->issueCollection->filter(fn (IssueInterface $issue): bool => $issue->getSeverity()->isCritical());
    }

    /**
     * Get only warning issues.
     */
    public function onlyWarnings(): IssueCollection
    {
        return $this->issueCollection->filter(fn (IssueInterface $issue): bool => $issue->getSeverity()->isWarning());
    }

    /**
     * Get only info issues.
     */
    public function onlyInfo(): IssueCollection
    {
        return $this->issueCollection->filter(fn (IssueInterface $issue): bool => $issue->getSeverity()->isInfo());
    }

    /**
     * Filter issues by type.
     */
    public function byType(string $type): IssueCollection
    {
        Assert::stringNotEmpty($type, 'Issue type cannot be empty');

        return $this->issueCollection->filter(fn (IssueInterface $issue): bool => $issue->getType() === $type);
    }

    /**
     * Filter issues with suggestions.
     */
    public function withSuggestions(): IssueCollection
    {
        return $this->issueCollection->filter(fn (IssueInterface $issue): bool => $issue->getSuggestion() instanceof SuggestionInterface);
    }

    /**
     * Filter issues without suggestions.
     */
    public function withoutSuggestions(): IssueCollection
    {
        return $this->issueCollection->filter(fn (IssueInterface $issue): bool => !$issue->getSuggestion() instanceof SuggestionInterface);
    }

    /**
     * Filter issues with backtrace.
     */
    public function withBacktrace(): IssueCollection
    {
        return $this->issueCollection->filter(fn (IssueInterface $issue): bool => null !== $issue->getBacktrace());
    }

    /**
     * Filter issues without backtrace.
     */
    public function withoutBacktrace(): IssueCollection
    {
        return $this->issueCollection->filter(fn (IssueInterface $issue): bool => null === $issue->getBacktrace());
    }

    /**
     * Filter issues with queries.
     */
    public function withQueries(): IssueCollection
    {
        return $this->issueCollection->filter(fn (IssueInterface $issue): bool => [] !== $issue->getQueries());
    }
}
