<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Service;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Service\IssueDeduplicator;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IssueDeduplicator.
 */
final class IssueDeduplicatorTest extends TestCase
{
    private IssueDeduplicator $deduplicator;

    protected function setUp(): void
    {
        $this->deduplicator = new IssueDeduplicator();
    }

    #[Test]
    public function it_removes_duplicate_n_plus_one_and_lazy_loading_issues(): void
    {
        // Arrange
        $queryData1 = new QueryData(
            sql: 'SELECT * FROM bill_line WHERE id = ?',
            executionTime: QueryExecutionTime::fromMilliseconds(50.0),
        );

        $nPlusOneIssue = $this->createIssue(
            'N+1 Query detected: 212 queries on BillLine',
            'N+1 Query pattern detected',
            Severity::CRITICAL,
            [$queryData1],
        );

        $lazyLoadingIssue = $this->createIssue(
            'Lazy Loading in Loop: 212 queries on BillLine',
            'Lazy loading detected in loop',
            Severity::WARNING,
            [$queryData1],
        );

        $frequentQueryIssue = $this->createIssue(
            'Frequent Query: 212 executions',
            'Query executed frequently',
            Severity::INFO,
            [$queryData1],
        );

        $issues = IssueCollection::fromArray([
            $nPlusOneIssue,
            $lazyLoadingIssue,
            $frequentQueryIssue,
        ]);

        // Act
        $deduplicated = $this->deduplicator->deduplicate($issues);

        // Assert
        self::assertCount(1, $deduplicated, 'Should keep only the N+1 issue');
        self::assertSame('N+1 Query detected: 212 queries on BillLine', $deduplicated->toArray()[0]->getTitle());
    }

    #[Test]
    public function it_keeps_distinct_issues_unchanged(): void
    {
        // Arrange
        $queryData1 = new QueryData(
            sql: 'SELECT * FROM bill_line',
            executionTime: QueryExecutionTime::fromMilliseconds(50.0),
        );

        $queryData2 = new QueryData(
            sql: 'SELECT * FROM time_entry WHERE is_incorrect = 1',
            executionTime: QueryExecutionTime::fromMilliseconds(100.0),
        );

        $nPlusOneIssue = $this->createIssue(
            'N+1 Query detected: 212 queries on BillLine',
            'N+1 on BillLine',
            Severity::CRITICAL,
            [$queryData1],
        );

        $missingIndexIssue = $this->createIssue(
            'Missing Index on time_entry: 138547 rows scanned',
            'Missing index detected',
            Severity::CRITICAL,
            [$queryData2],
        );

        $issues = IssueCollection::fromArray([
            $nPlusOneIssue,
            $missingIndexIssue,
        ]);

        // Act
        $deduplicated = $this->deduplicator->deduplicate($issues);

        // Assert
        self::assertCount(2, $deduplicated, 'Should keep both distinct issues');
    }

    #[Test]
    public function it_prioritizes_n_plus_one_over_frequent_query(): void
    {
        // Arrange
        $queryData = new QueryData(
            sql: 'SELECT * FROM subscription_line',
            executionTime: QueryExecutionTime::fromMilliseconds(30.0),
        );

        $frequentQueryIssue = $this->createIssue(
            'Frequent Query: 31 executions on SubscriptionLine',
            'Frequent query detected',
            Severity::WARNING,
            [$queryData],
        );

        $nPlusOneIssue = $this->createIssue(
            'N+1 Query detected: 31 queries on SubscriptionLine',
            'N+1 detected',
            Severity::CRITICAL,
            [$queryData],
        );

        $issues = IssueCollection::fromArray([
            $frequentQueryIssue, // Intentionally first to test prioritization
            $nPlusOneIssue,
        ]);

        // Act
        $deduplicated = $this->deduplicator->deduplicate($issues);

        // Assert
        self::assertCount(1, $deduplicated);
        $keptIssue = $deduplicated->toArray()[0];
        self::assertStringContainsString('N+1 Query', $keptIssue->getTitle());
    }

    #[Test]
    public function it_keeps_missing_index_and_slow_query_as_distinct_issues(): void
    {
        // Arrange - These are actually different issues (missing index vs slow execution)
        // so they should NOT be deduplicated
        $queryData = new QueryData(
            sql: 'SELECT * FROM time_entry WHERE is_incorrect = 1',
            executionTime: QueryExecutionTime::fromMilliseconds(45.0),
        );

        $slowQueryIssue = $this->createIssue(
            'Slow Query: 45ms',
            'Slow query detected',
            Severity::WARNING,
            [$queryData],
        );

        $missingIndexIssue = $this->createIssue(
            'Missing index on table time_entry: 138547 rows scanned',
            'Missing index detected',
            Severity::CRITICAL,
            [$queryData],
        );

        $issues = IssueCollection::fromArray([
            $slowQueryIssue,
            $missingIndexIssue,
        ]);

        // Act
        $deduplicated = $this->deduplicator->deduplicate($issues);

        // Assert - Both should be kept as they are distinct issue types
        self::assertCount(2, $deduplicated);
    }

    #[Test]
    public function it_handles_empty_collection(): void
    {
        // Arrange
        $issues = IssueCollection::fromArray([]);

        // Act
        $deduplicated = $this->deduplicator->deduplicate($issues);

        // Assert
        self::assertCount(0, $deduplicated);
    }

    #[Test]
    public function it_handles_single_issue(): void
    {
        // Arrange
        $issue = $this->createIssue(
            'N+1 Query detected',
            'Description',
            Severity::CRITICAL,
            [],
        );
        $issues = IssueCollection::fromArray([$issue]);

        // Act
        $deduplicated = $this->deduplicator->deduplicate($issues);

        // Assert
        self::assertCount(1, $deduplicated);
    }

    #[Test]
    public function it_prefers_higher_severity_when_same_priority(): void
    {
        // Arrange - Same issue type but different severity
        $queryData = new QueryData(
            sql: 'SELECT * FROM bill_line',
            executionTime: QueryExecutionTime::fromMilliseconds(50.0),
        );

        $criticalIssue = $this->createIssue(
            'N+1 Query detected: 212 queries',
            'Critical N+1',
            Severity::CRITICAL,
            [$queryData],
        );

        $warningIssue = $this->createIssue(
            'N+1 Query detected: 212 queries',
            'Warning N+1',
            Severity::WARNING,
            [$queryData],
        );

        $issues = IssueCollection::fromArray([
            $warningIssue, // Intentionally first
            $criticalIssue,
        ]);

        // Act
        $deduplicated = $this->deduplicator->deduplicate($issues);

        // Assert
        self::assertCount(1, $deduplicated);
        $keptIssue = $deduplicated->toArray()[0];
        self::assertSame(Severity::CRITICAL, $keptIssue->getSeverity());
    }

    #[Test]
    public function it_handles_query_data_objects_instead_of_arrays(): void
    {
        // Arrange - This tests the actual runtime behavior where getQueries() returns QueryData objects
        $queryData1 = new QueryData(
            sql: 'SELECT * FROM bill_line WHERE id = ?',
            executionTime: QueryExecutionTime::fromMilliseconds(50.0),
        );

        $queryData2 = new QueryData(
            sql: 'SELECT * FROM subscription_line WHERE id = ?',
            executionTime: QueryExecutionTime::fromMilliseconds(30.0),
        );

        $nPlusOneIssue = $this->createIssue(
            'N+1 Query detected: 212 queries on BillLine',
            'N+1 Query pattern detected',
            Severity::CRITICAL,
            [$queryData1], // QueryData object, not array
        );

        $lazyLoadingIssue = $this->createIssue(
            'Lazy Loading in Loop: 212 queries on BillLine',
            'Lazy loading detected in loop',
            Severity::WARNING,
            [$queryData1], // Same QueryData object
        );

        $differentIssue = $this->createIssue(
            'N+1 Query detected: 31 queries on SubscriptionLine',
            'N+1 on different entity',
            Severity::CRITICAL,
            [$queryData2], // Different QueryData object
        );

        $issues = IssueCollection::fromArray([
            $nPlusOneIssue,
            $lazyLoadingIssue,
            $differentIssue,
        ]);

        // Act
        $deduplicated = $this->deduplicator->deduplicate($issues);

        // Assert
        self::assertCount(2, $deduplicated, 'Should keep N+1 on BillLine and N+1 on SubscriptionLine');
        $titles = array_map(fn (IssueInterface $issue) => $issue->getTitle(), $deduplicated->toArray());
        self::assertContains('N+1 Query detected: 212 queries on BillLine', $titles);
        self::assertContains('N+1 Query detected: 31 queries on SubscriptionLine', $titles);
        self::assertNotContains('Lazy Loading in Loop: 212 queries on BillLine', $titles);
    }

    /**
     * Create a mock issue for testing.
     *
     * @param array<int, QueryData> $queries
     */
    private function createIssue(
        string $title,
        string $description,
        Severity $severity,
        array $queries,
    ): IssueInterface {
        return new class($title, $description, $severity, $queries) implements IssueInterface {
            public function __construct(
                /**
                 * @readonly
                 */
                private string $title,
                /**
                 * @readonly
                 */
                private string $description,
                /**
                 * @readonly
                 */
                private Severity $severity,
                /**
                 * @readonly
                 */
                private array $queries,
            ) {
            }

            public function getType(): string
            {
                return 'test_issue';
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

            public function getCategory(): string
            {
                return 'performance';
            }

            public function getSuggestion(): ?SuggestionInterface
            {
                return null;
            }

            public function getBacktrace(): ?array
            {
                return null;
            }

            public function getQueries(): array
            {
                return $this->queries;
            }

            public function getData(): array
            {
                return [];
            }

            public function toArray(): array
            {
                return [
                    'type' => $this->getType(),
                    'title' => $this->title,
                    'description' => $this->description,
                    'severity' => $this->severity->value,
                    'category' => $this->getCategory(),
                    'queries' => $this->queries,
                ];
            }
        };
    }
}
