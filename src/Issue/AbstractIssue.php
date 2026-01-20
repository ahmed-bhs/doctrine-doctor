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
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Webmozart\Assert\Assert;

abstract class AbstractIssue implements IssueInterface
{
    protected string $type;

    protected string $title;

    protected string $description;

    protected Severity $severity;

    protected ?SuggestionInterface $suggestion;

    protected ?array $backtrace;

    /** @var array<mixed> */
    protected array $queries;

    /** @var array<mixed> */
    protected array $data;

    /** @var IssueInterface[] Issues that were deduplicated and hidden because they resemble this one */
    protected array $duplicatedIssues = [];

    public function __construct(array $data)
    {
        Assert::keyExists($data, 'type', 'Issue data must contain a "type" key');
        Assert::keyExists($data, 'title', 'Issue data must contain a "title" key');
        Assert::keyExists($data, 'description', 'Issue data must contain a "description" key');
        Assert::keyExists($data, 'severity', 'Issue data must contain a "severity" key');

        // Convert IssueType enum to string if necessary
        $this->type        = $data['type'] instanceof IssueType ? $data['type']->value : $data['type'];
        $this->title       = $data['title'];
        $this->description = $data['description'];
        // Convert severity to Severity enum
        $this->severity = $this->convertToSeverity($data['severity']);

        Assert::stringNotEmpty($this->type, 'Issue type cannot be empty');
        Assert::stringNotEmpty($this->title, 'Issue title cannot be empty');
        Assert::stringNotEmpty($this->description, 'Issue description cannot be empty');

        $this->suggestion = $data['suggestion'] ?? null;
        $this->backtrace  = $data['backtrace'] ?? null;
        $this->queries    = $data['queries'] ?? [];

        if ($this->suggestion instanceof SuggestionInterface) {
            Assert::isInstanceOf($this->suggestion, SuggestionInterface::class);
        }

        if (null !== $this->backtrace) {
            Assert::isArray($this->backtrace, 'Backtrace must be an array or null');
        }

        Assert::isArray($this->queries, 'Queries must be an array');

        // Store all data for analyzers that need access to extra fields
        $this->data = $data;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getSeverity(): Severity
    {
        return $this->severity;
    }

    public function setSeverity(string|Severity $severity): void
    {
        $this->severity = $this->convertToSeverity($severity);
        // Also update $data array so getData() returns correct severity
        $this->data['severity'] = $this->severity->value;
    }

    public function setTitle(string $title): void
    {
        Assert::stringNotEmpty($title, 'Issue title cannot be empty');
        $this->title = $title;
        // Also update $data array so getData() returns correct title
        $this->data['title'] = $title;
    }

    public function setMessage(string $description): void
    {
        Assert::stringNotEmpty($description, 'Issue description cannot be empty');
        $this->description = $description;
        // Also update $data array so getData() returns correct description
        $this->data['description'] = $description;
    }

    public function setSuggestion(?SuggestionInterface $suggestion): void
    {
        if ($suggestion instanceof SuggestionInterface) {
            Assert::isInstanceOf($suggestion, SuggestionInterface::class);
        }

        $this->suggestion = $suggestion;
    }

    public function getSuggestion(): ?SuggestionInterface
    {
        return $this->suggestion;
    }

    public function getBacktrace(): ?array
    {
        return $this->backtrace;
    }

    /**
     * @return array<mixed>
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /**
     * @return array<mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get issues that were deduplicated and hidden because they resemble this one.
     * @return IssueInterface[]
     */
    public function getDuplicatedIssues(): array
    {
        return $this->duplicatedIssues;
    }

    /**
     * Add an issue that was deduplicated and hidden.
     */
    public function addDuplicatedIssue(IssueInterface $issue): void
    {
        $this->duplicatedIssues[] = $issue;
    }

    /**
     * Set all duplicated issues at once.
     * @param IssueInterface[] $issues
     */
    public function setDuplicatedIssues(array $issues): void
    {
        $this->duplicatedIssues = $issues;
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'class'             => static::class,
            'type'              => $this->type,
            'title'             => $this->title,
            'description'       => $this->description,
            'severity'          => $this->severity->value,
            'suggestion'        => $this->suggestion instanceof SuggestionInterface ? $this->suggestion->toArray() : null,
            'backtrace'         => $this->backtrace,
            'queries'           => $this->queries,
            'duplicatedIssues'  => array_map(fn (IssueInterface $issue) => [
                'title'       => $issue->getTitle(),
                'type'        => $issue->getType(),
                'severity'    => $issue->getSeverity()->value,
                'description' => substr(html_entity_decode(strip_tags($issue->getDescription()), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 200), // First 200 chars, strip HTML then decode entities
            ], $this->duplicatedIssues),
        ];
    }

    /**
     * Convert string or Severity to Severity enum.
     */
    private function convertToSeverity(string|Severity $severity): Severity
    {
        if ($severity instanceof Severity) {
            return $severity;
        }

        // Normalize legacy severity values to standard ones (5-level system)
        $normalized = match ($severity) {
            'warning' => 'warning',  // Legacy: warning → medium
            'error'   => 'warning',    // Legacy: error → high
            'notice'  => 'info',    // Legacy: notice → info
            default   => $severity, // Keep new levels as-is: info, low, medium, high, critical
        };

        return Severity::from($normalized);
    }
}
