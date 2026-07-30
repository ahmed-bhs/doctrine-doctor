<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Collection;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\TestCase;

final class IssueCollectionTest extends TestCase
{
    public function test_from_array(): void
    {
        $issues = [
            $this->createMockIssue('n_plus_one', 'critical'),
            $this->createMockIssue('slow_query', 'warning'),
        ];

        $issueCollection = IssueCollection::fromArray($issues);

        self::assertCount(2, $issueCollection);
        self::assertFalse($issueCollection->isEmpty());
    }

    public function test_empty(): void
    {
        $issueCollection = IssueCollection::empty();

        self::assertCount(0, $issueCollection);
        self::assertTrue($issueCollection->isEmpty());
    }

    public function test_filter_by_severity(): void
    {
        $issues = [
            $this->createMockIssue('n_plus_one', 'critical'),
            $this->createMockIssue('slow_query', 'warning'),
            $this->createMockIssue('dql_injection', 'critical'),
        ];

        $issueCollection = IssueCollection::fromArray($issues);
        $criticalIssues = $issueCollection->filterBySeverity('critical');

        self::assertCount(2, $criticalIssues);
    }

    public function test_only_critical(): void
    {
        $issues = [
            $this->createMockIssue('n_plus_one', 'critical'),
            $this->createMockIssue('slow_query', 'warning'),
        ];

        $issueCollection = IssueCollection::fromArray($issues);
        $criticalIssues = $issueCollection->onlyCritical();

        self::assertCount(1, $criticalIssues);
    }

    public function test_only_warnings(): void
    {
        $issues = [
            $this->createMockIssue('n_plus_one', 'critical'),
            $this->createMockIssue('slow_query', 'warning'),
        ];

        $issueCollection = IssueCollection::fromArray($issues);
        $warnings = $issueCollection->onlyWarnings();

        self::assertCount(1, $warnings);
    }

    public function test_filter_by_type(): void
    {
        $issues = [
            $this->createMockIssue('n_plus_one', 'critical'),
            $this->createMockIssue('slow_query', 'warning'),
            $this->createMockIssue('n_plus_one', 'warning'),
        ];

        $issueCollection = IssueCollection::fromArray($issues);
        $nPlusOneIssues = $issueCollection->filterByType('n_plus_one');

        self::assertCount(2, $nPlusOneIssues);
    }

    public function test_group_by_severity(): void
    {
        $issues = [
            $this->createMockIssue('n_plus_one', 'critical'),
            $this->createMockIssue('slow_query', 'warning'),
            $this->createMockIssue('dql_injection', 'critical'),
        ];

        $issueCollection = IssueCollection::fromArray($issues);
        $grouped = $issueCollection->groupBySeverity();

        self::assertArrayHasKey('critical', $grouped);
        self::assertArrayHasKey('warning', $grouped);
        self::assertCount(2, $grouped['critical']);
        self::assertCount(1, $grouped['warning']);
    }

    public function test_group_by_type(): void
    {
        $issues = [
            $this->createMockIssue('n_plus_one', 'critical'),
            $this->createMockIssue('slow_query', 'warning'),
            $this->createMockIssue('n_plus_one', 'warning'),
        ];

        $issueCollection = IssueCollection::fromArray($issues);
        $grouped = $issueCollection->groupByType();

        self::assertArrayHasKey('n_plus_one', $grouped);
        self::assertArrayHasKey('slow_query', $grouped);
        self::assertCount(2, $grouped['n_plus_one']);
        self::assertCount(1, $grouped['slow_query']);
    }

    public function test_count_by_severity(): void
    {
        $issues = [
            $this->createMockIssue('n_plus_one', 'critical'),
            $this->createMockIssue('slow_query', 'warning'),
            $this->createMockIssue('dql_injection', 'critical'),
            $this->createMockIssue('find_all', 'warning'),
        ];

        $issueCollection = IssueCollection::fromArray($issues);
        $counts = $issueCollection->countBySeverity();

        self::assertEquals(2, $counts['critical']);
        self::assertEquals(2, $counts['warning']);
    }

    public function test_count_by_type(): void
    {
        $issues = [
            $this->createMockIssue('n_plus_one', 'critical'),
            $this->createMockIssue('slow_query', 'warning'),
            $this->createMockIssue('n_plus_one', 'warning'),
        ];

        $issueCollection = IssueCollection::fromArray($issues);
        $counts = $issueCollection->countByType();

        self::assertEquals(2, $counts['n_plus_one']);
        self::assertEquals(1, $counts['slow_query']);
    }

    public function test_has_critical(): void
    {
        $issues = [
            $this->createMockIssue('n_plus_one', 'critical'),
            $this->createMockIssue('slow_query', 'warning'),
        ];

        $issueCollection = IssueCollection::fromArray($issues);

        self::assertTrue($issueCollection->hasCritical());
    }

    // test_has_errors removed - 'error' severity no longer exists in Severity enum

    public function test_has_warnings(): void
    {
        $issues = [
            $this->createMockIssue('n_plus_one', 'critical'),
            $this->createMockIssue('slow_query', 'warning'),
        ];

        $issueCollection = IssueCollection::fromArray($issues);

        self::assertTrue($issueCollection->hasWarnings());
    }

    public function test_sort_by_severity(): void
    {
        $issues = [
            $this->createMockIssue('n_plus_one', 'warning'),
            $this->createMockIssue('slow_query', 'critical'),
            $this->createMockIssue('dql_injection', 'info'),
        ];

        $issueCollection = IssueCollection::fromArray($issues);
        $sorted = $issueCollection->sortBySeverity();

        $array = $sorted->toArray();
        self::assertEquals('critical', $array[0]->getSeverity()->value);
        self::assertEquals('warning', $array[1]->getSeverity()->value);
        self::assertEquals('info', $array[2]->getSeverity()->value);
    }

    public function test_most_severe(): void
    {
        $issues = [
            $this->createMockIssue('n_plus_one', 'warning'),
            $this->createMockIssue('slow_query', 'critical'),
            $this->createMockIssue('dql_injection', 'info'),
        ];

        $issueCollection = IssueCollection::fromArray($issues);
        $mostSevere = $issueCollection->mostSevere();

        self::assertInstanceOf(IssueInterface::class, $mostSevere);
        self::assertSame('critical', $mostSevere->getSeverity()->value);
    }

    public function test_get_unique_types(): void
    {
        $issues = [
            $this->createMockIssue('n_plus_one', 'critical'),
            $this->createMockIssue('slow_query', 'warning'),
            $this->createMockIssue('n_plus_one', 'warning'),
        ];

        $issueCollection = IssueCollection::fromArray($issues);
        $uniqueTypes = $issueCollection->getUniqueTypes();

        self::assertCount(2, $uniqueTypes);
        self::assertContains('n_plus_one', $uniqueTypes);
        self::assertContains('slow_query', $uniqueTypes);
    }

    public function test_merge(): void
    {
        $issues1 = [
            $this->createMockIssue('n_plus_one', 'critical'),
        ];
        $issues2 = [
            $this->createMockIssue('slow_query', 'warning'),
        ];

        $issueCollection = IssueCollection::fromArray($issues1);
        $collection2 = IssueCollection::fromArray($issues2);
        $merged = $issueCollection->merge($collection2);

        self::assertCount(2, $merged);
    }

    private function createMockIssue(string $type, string $severity): IssueInterface
    {
        $issue = $this->createMock(IssueInterface::class);
        $issue->method('getType')->willReturn($type);
        $issue->method('getSeverity')->willReturn(Severity::from($severity));
        $issue->method('getTitle')->willReturn('Test Issue');
        $issue->method('getDescription')->willReturn('Test description');
        $issue->method('getSuggestion')->willReturn(null);
        $issue->method('getBacktrace')->willReturn(null);
        $issue->method('getQueries')->willReturn([]);
        $issue->method('toArray')->willReturn([
            'type' => $type,
            'severity' => $severity,
            'title' => 'Test Issue',
        ]);

        return $issue;
    }
}
