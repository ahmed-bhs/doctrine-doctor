<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\DTO;

use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for IssueData automatic query deduplication.
 */
final class IssueDataTest extends TestCase
{
    #[Test]
    public function it_automatically_deduplicates_identical_queries(): void
    {
        // Arrange: Create 6 identical queries (like N+1 scenario)
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 6; $i++) {
            $builder->addQuery('SELECT * FROM products WHERE id = ?', 0.5);
        }
        $queries = $builder->build()->toArray();

        // Act: Create IssueData with 6 identical queries
        $issueData = new IssueData(
            type: 'test',
            title: 'Test Issue',
            description: 'Found 6 queries',
            severity: Severity::info(),
            queries: $queries,
        );

        // Assert: Should deduplicate to 1 representative query
        self::assertCount(1, $issueData->queries, 'Should deduplicate 6 identical queries to 1 representative');
    }

    #[Test]
    public function it_keeps_all_unique_queries(): void
    {
        // Arrange: Create 3 different queries
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?', 0.5)
            ->addQuery('SELECT * FROM products WHERE id = ?', 0.5)
            ->addQuery('SELECT * FROM orders WHERE id = ?', 0.5)
            ->build()
            ->toArray();

        // Act: Create IssueData with 3 unique queries
        $issueData = new IssueData(
            type: 'test',
            title: 'Test Issue',
            description: 'Found 3 different queries',
            severity: Severity::info(),
            queries: $queries,
        );

        // Assert: Should keep all 3 unique queries
        self::assertCount(3, $issueData->queries, 'Should keep all 3 unique query patterns');
    }

    #[Test]
    public function it_deduplicates_queries_with_different_parameters(): void
    {
        // Arrange: Create 3 queries with same pattern but different parameters
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM products WHERE id = 1', 0.5)
            ->addQuery('SELECT * FROM products WHERE id = 2', 0.5)
            ->addQuery('SELECT * FROM products WHERE id = 3', 0.5)
            ->build()
            ->toArray();

        // Act: Create IssueData
        $issueData = new IssueData(
            type: 'test',
            title: 'Test Issue',
            description: 'Found 3 queries',
            severity: Severity::info(),
            queries: $queries,
        );

        // Assert: Should deduplicate to 1 (same pattern, different parameters)
        self::assertCount(1, $issueData->queries, 'Should deduplicate queries with same pattern but different parameter values');
    }
}
