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
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use Webmozart\Assert\Assert;

/**
 * Provides filtering capabilities for IssueCollection.
 * Follows Single Responsibility Principle.
 */
final class IssueFilter
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
     * Filter issues by severity.
     */
    public function bySeverity(string $severity): IssueCollection
    {
        Assert::stringNotEmpty($severity, 'Severity cannot be empty');
        Assert::keyExists(self::SEVERITY_ORDER, $severity, 'Invalid severity "%s". Must be one of: %2$s');

        return $this->issueCollection->filter(fn (IssueInterface $issue): bool => $issue->getSeverity()->value === $severity);
    }

    /**
     * Get only critical issues.
     */
    public function onlyCritical(): IssueCollection
    {
        return $this->bySeverity('critical');
    }

    /**
     * Get only error issues.
     */
    public function onlyErrors(): IssueCollection
    {
        return $this->bySeverity('error');
    }

    /**
     * Get only warning issues.
     */
    public function onlyWarnings(): IssueCollection
    {
        return $this->bySeverity('warning');
    }

    /**
     * Get only info issues.
     */
    public function onlyInfo(): IssueCollection
    {
        return $this->bySeverity('info');
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
