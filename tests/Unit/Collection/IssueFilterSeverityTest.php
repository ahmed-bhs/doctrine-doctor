<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Collection;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\IssueFilter;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IssueFilter Severity enum refactoring.
 * Ensures backward compatibility and type-safe filtering.
 */
final class IssueFilterSeverityTest extends TestCase
{
    private IssueCollection $collection;

    protected function setUp(): void
    {
        $this->collection = IssueCollection::fromArray([
            new PerformanceIssue([
                'title' => 'Critical Issue 1',
                'description' => 'Test',
                'severity' => Severity::critical(),
                'queries' => [],
            ]),
            new PerformanceIssue([
                'title' => 'Critical Issue 2',
                'description' => 'Test',
                'severity' => Severity::critical(),
                'queries' => [],
            ]),
            new PerformanceIssue([
                'title' => 'Warning Issue 1',
                'description' => 'Test',
                'severity' => Severity::warning(),
                'queries' => [],
            ]),
            new PerformanceIssue([
                'title' => 'Info Issue 1',
                'description' => 'Test',
                'severity' => Severity::info(),
                'queries' => [],
            ]),
            new PerformanceIssue([
                'title' => 'Info Issue 2',
                'description' => 'Test',
                'severity' => Severity::info(),
                'queries' => [],
            ]),
        ]);
    }

    #[Test]
    public function it_filters_by_severity_enum_critical(): void
    {
        $filter = new IssueFilter($this->collection);
        $critical = $filter->bySeverityEnum(Severity::critical());

        self::assertCount(2, $critical);
        foreach ($critical as $issue) {
            self::assertTrue($issue->getSeverity()->isCritical());
        }
    }

    #[Test]
    public function it_filters_by_severity_enum_warning(): void
    {
        $filter = new IssueFilter($this->collection);
        $warnings = $filter->bySeverityEnum(Severity::warning());

        self::assertCount(1, $warnings);
        foreach ($warnings as $issue) {
            self::assertTrue($issue->getSeverity()->isWarning());
        }
    }

    #[Test]
    public function it_filters_by_severity_enum_info(): void
    {
        $filter = new IssueFilter($this->collection);
        $info = $filter->bySeverityEnum(Severity::info());

        self::assertCount(2, $info);
        foreach ($info as $issue) {
            self::assertTrue($issue->getSeverity()->isInfo());
        }
    }

    /**
     * Test backward compatibility with string severity values.
     */
    #[Test]
    public function it_maintains_backward_compatibility_with_string_severity(): void
    {
        $filter = new IssueFilter($this->collection);

        // Old API still works
        $critical = $filter->bySeverity('critical');
        $warnings = $filter->bySeverity('warning');
        $info = $filter->bySeverity('info');

        self::assertCount(2, $critical);
        self::assertCount(1, $warnings);
        self::assertCount(2, $info);
    }

    #[Test]
    public function it_uses_enum_methods_in_only_critical(): void
    {
        $filter = new IssueFilter($this->collection);
        $critical = $filter->onlyCritical();

        self::assertCount(2, $critical);

        // Verify all issues are actually critical using enum method
        foreach ($critical as $issue) {
            self::assertTrue($issue->getSeverity()->isCritical());
            self::assertFalse($issue->getSeverity()->isWarning());
            self::assertFalse($issue->getSeverity()->isInfo());
        }
    }

    #[Test]
    public function it_uses_enum_methods_in_only_warnings(): void
    {
        $filter = new IssueFilter($this->collection);
        $warnings = $filter->onlyWarnings();

        self::assertCount(1, $warnings);

        foreach ($warnings as $issue) {
            self::assertTrue($issue->getSeverity()->isWarning());
            self::assertFalse($issue->getSeverity()->isCritical());
            self::assertFalse($issue->getSeverity()->isInfo());
        }
    }

    #[Test]
    public function it_uses_enum_methods_in_only_info(): void
    {
        $filter = new IssueFilter($this->collection);
        $info = $filter->onlyInfo();

        self::assertCount(2, $info);

        foreach ($info as $issue) {
            self::assertTrue($issue->getSeverity()->isInfo());
            self::assertFalse($issue->getSeverity()->isCritical());
            self::assertFalse($issue->getSeverity()->isWarning());
        }
    }

    /**
     * Test that filtering with enum and string returns same results.
     */
    #[Test]
    public function it_returns_same_results_for_enum_and_string_filtering(): void
    {
        $filter = new IssueFilter($this->collection);

        $enumCritical = $filter->bySeverityEnum(Severity::critical());
        $stringCritical = $filter->bySeverity('critical');

        self::assertEquals($enumCritical->count(), $stringCritical->count());
        self::assertEquals(
            $enumCritical->toArray(),
            $stringCritical->toArray(),
            'Enum and string filtering should return identical results',
        );
    }

    /**
     * Test that Issues created with string severity work with enum filtering.
     */
    #[Test]
    public function it_filters_legacy_string_severity_issues_with_enum(): void
    {
        // Create collection with OLD API (string severity)
        $legacyCollection = IssueCollection::fromArray([
            new PerformanceIssue([
                'title' => 'Legacy Critical',
                'description' => 'Test',
                'severity' => 'critical', // String, not enum
                'queries' => [],
            ]),
            new PerformanceIssue([
                'title' => 'Legacy Warning',
                'description' => 'Test',
                'severity' => 'warning', // String, not enum
                'queries' => [],
            ]),
        ]);

        $filter = new IssueFilter($legacyCollection);

        // NEW API should work with legacy data
        $critical = $filter->bySeverityEnum(Severity::critical());
        self::assertCount(1, $critical);

        // OLD API should still work
        $warnings = $filter->bySeverity('warning');
        self::assertCount(1, $warnings);
    }

    /**
     * Test that converting legacy 'error' severity to 'warning' works.
     */
    #[Test]
    public function it_converts_legacy_error_severity_to_warning(): void
    {
        $legacyCollection = IssueCollection::fromArray([
            new PerformanceIssue([
                'title' => 'Legacy Error',
                'description' => 'Test',
                'severity' => 'error', // Legacy value
                'queries' => [],
            ]),
        ]);

        $filter = new IssueFilter($legacyCollection);

        // Legacy 'error' should be converted to 'warning'
        $warnings = $filter->onlyWarnings();
        self::assertCount(1, $warnings);

        $issue = $warnings->first();
        self::assertNotNull($issue);
        self::assertTrue($issue->getSeverity()->isWarning());
    }

    /**
     * Test that invalid severity string throws exception.
     */
    #[Test]
    public function it_throws_exception_for_invalid_severity_string(): void
    {
        $filter = new IssueFilter($this->collection);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid severity');

        $filter->bySeverity('invalid_severity');
    }
}
