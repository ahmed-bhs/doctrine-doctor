<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\BulkOperationAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for BulkOperationAnalyzer.
 *
 * This analyzer detects when many individual UPDATE/DELETE queries
 * are executed when batch operations would be more efficient.
 */
final class BulkOperationAnalyzerTest extends TestCase
{
    private BulkOperationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new BulkOperationAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            20, // threshold: 20 operations to trigger detection
        );
    }

    #[Test]
    public function it_detects_bulk_updates_above_threshold(): void
    {
        // Arrange: 25 individual UPDATE queries (above threshold of 20)
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 25; $i++) {
            $builder->addQuery("UPDATE users SET status = 'active' WHERE id = {$i}", 5.0);
        }
        $queries = $builder->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should detect bulk UPDATE operations');

        $issue = $issuesArray[0];
        self::assertStringContainsString('Inefficient Bulk Operations', $issue->getTitle());
        self::assertStringContainsString('25', $issue->getTitle());
        self::assertStringContainsString('UPDATE', $issue->getTitle());
        self::assertStringContainsString('users', $issue->getTitle());
    }

    #[Test]
    public function it_detects_bulk_deletes_above_threshold(): void
    {
        // Arrange: 30 individual DELETE queries
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 30; $i++) {
            $builder->addQuery("DELETE FROM posts WHERE id = {$i}", 3.0);
        }
        $queries = $builder->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $issue = $issuesArray[0];
        self::assertStringContainsString('30', $issue->getTitle());
        self::assertStringContainsString('DELETE', $issue->getTitle());
        self::assertStringContainsString('posts', $issue->getTitle());
    }

    #[Test]
    public function it_ignores_operations_below_threshold(): void
    {
        // Arrange: Only 15 UPDATE queries (below threshold of 20)
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 15; $i++) {
            $builder->addQuery("UPDATE users SET status = 'active' WHERE id = {$i}", 5.0);
        }
        $queries = $builder->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues->toArray(), 'Should not detect operations below threshold');
    }

    #[Test]
    public function it_groups_operations_by_table(): void
    {
        // Arrange: 25 UPDATEs on 'users' and 22 UPDATEs on 'posts'
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 25; $i++) {
            $builder->addQuery("UPDATE users SET status = 'active' WHERE id = {$i}", 5.0);
        }
        for ($i = 1; $i <= 22; $i++) {
            $builder->addQuery("UPDATE posts SET published = 1 WHERE id = {$i}", 4.0);
        }
        $queries = $builder->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertCount(2, $issuesArray, 'Should detect bulk operations on both tables');

        $titles = array_map(fn ($issue) => $issue->getTitle(), $issuesArray);
        self::assertTrue(
            in_array(true, array_map(fn ($title) => str_contains($title, 'users'), $titles), true),
            'Should detect bulk UPDATE on users table',
        );
        self::assertTrue(
            in_array(true, array_map(fn ($title) => str_contains($title, 'posts'), $titles), true),
            'Should detect bulk UPDATE on posts table',
        );
    }

    #[Test]
    public function it_separates_update_and_delete_on_same_table(): void
    {
        // Arrange: 22 UPDATEs and 21 DELETEs on same table
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 22; $i++) {
            $builder->addQuery("UPDATE users SET status = 'active' WHERE id = {$i}", 5.0);
        }
        for ($i = 51; $i <= 71; $i++) {
            $builder->addQuery("DELETE FROM users WHERE id = {$i}", 3.0);
        }
        $queries = $builder->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertCount(2, $issuesArray, 'Should detect both UPDATE and DELETE as separate bulk operations');
    }

    #[Test]
    public function it_provides_suggestion_for_batch_operations(): void
    {
        // Arrange
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 25; $i++) {
            $builder->addQuery("UPDATE users SET status = 'active' WHERE id = {$i}", 5.0);
        }
        $queries = $builder->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion, 'Should provide suggestion');
        self::assertStringContainsString('batch', strtolower($issue->getDescription()));
    }

    #[Test]
    public function it_handles_empty_query_collection(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues->toArray());
    }

    #[Test]
    public function it_ignores_select_queries(): void
    {
        // Arrange: Many SELECT queries (should be ignored)
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 30; $i++) {
            $builder->addQuery("SELECT * FROM users WHERE id = {$i}", 2.0);
        }
        $queries = $builder->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues->toArray(), 'Should ignore SELECT queries');
    }

    #[Test]
    public function it_uses_generator_pattern(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: IssueCollection uses generator pattern
        self::assertInstanceOf(\Generator::class, $issues->getIterator());
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        // Assert
        self::assertInstanceOf(\AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface::class, $this->analyzer);
    }
}
