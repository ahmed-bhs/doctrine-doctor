<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\SetMaxResultsWithCollectionJoinAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for SetMaxResultsWithCollectionJoinAnalyzer.
 *
 * This analyzer detects a CRITICAL anti-pattern:
 * - Using LIMIT (setMaxResults) with collection joins causes partial hydration
 * - LIMIT applies to SQL rows, not entities
 * - Results in silent data loss (missing related entities)
 *
 * Example: Pet has 4 pictures, but with setMaxResults(1) only 1 picture loads.
 *
 * Solution: Use Doctrine's Paginator which executes 2 queries to properly handle
 * collection joins.
 */
final class SetMaxResultsWithCollectionJoinAnalyzerTest extends TestCase
{
    private SetMaxResultsWithCollectionJoinAnalyzer $analyzer;

    protected function setUp(): void
    {
        $renderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($renderer);
        $issueFactory = new IssueFactory();
        $sqlExtractor = new \AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor();
        $this->analyzer = new SetMaxResultsWithCollectionJoinAnalyzer($issueFactory, $suggestionFactory, $sqlExtractor);
    }

    #[Test]
    public function it_returns_empty_collection_when_no_issues(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users')
            ->addQuery('SELECT * FROM orders LIMIT 10')
            ->addQuery('SELECT * FROM products WHERE status = "active"')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_limit_with_fetch_join(): void
    {
        // Doctrine pattern: SELECT t0_.id, t1_.id FROM table1 t0 JOIN table2 t1 LIMIT 1
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t0_.name, t1_.id, t1_.content FROM blog_posts t0_ LEFT JOIN t1_ comments ON t0_.id = t1_.post_id LIMIT 1')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        self::assertEquals('setMaxResults_with_collection_join', $data['type']);
        self::assertEquals('setMaxResults() with Collection Join Detected', $issue->getTitle());
        self::assertStringContainsString('LIMIT', $issue->getDescription());
        self::assertStringContainsString('fetch-joined collection', $issue->getDescription());
        self::assertEquals('critical', $data['severity']);
    }

    #[Test]
    public function it_detects_limit_with_inner_join_and_multiple_aliases(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t0_.title, t1_.id, t1_.comment FROM posts t0_ INNER JOIN comments t1_ ON t0_.id = t1_.post_id LIMIT 5')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_ignores_limit_without_join(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t0_.name FROM users t0_ LIMIT 10')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // No JOIN = no issue
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_join_without_limit(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t0_.name, t1_.id FROM blog_posts t0_ LEFT JOIN comments t1_ ON t0_.id = t1_.post_id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // JOIN without LIMIT is OK
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_limit_with_non_fetch_join(): void
    {
        // Non-fetch join: only selecting from t0_, not t1_
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t0_.name FROM blog_posts t0_ LEFT JOIN comments t1_ ON t0_.id = t1_.post_id WHERE t1_.id IS NOT NULL LIMIT 10')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Not a fetch join (only SELECT t0_.*) = no issue
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_left_join_with_limit_and_fetch(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t1_.id FROM orders t0_ LEFT JOIN order_items t1_ ON t0_.id = t1_.order_id LIMIT 20')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_handles_case_insensitive_keywords(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('select t0_.id, t1_.id from orders t0_ left join order_items t1_ on t0_.id = t1_.order_id limit 10')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_ignores_non_select_queries(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('INSERT INTO logs (message) VALUES ("test")')
            ->addQuery('UPDATE users SET status = "active"')
            ->addQuery('DELETE FROM temp_data')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_provides_suggestion(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t1_.id FROM blog_posts t0_ LEFT JOIN comments t1_ LIMIT 1')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
        self::assertIsArray($suggestion->toArray());
    }

    #[Test]
    public function it_includes_paginator_solution_in_description(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t1_.id FROM pets t0_ LEFT JOIN pictures t1_ LIMIT 1')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];

        self::assertStringContainsString('Paginator', $issue->getDescription());
        self::assertStringContainsString('2 queries', $issue->getDescription());
    }

    #[Test]
    public function it_has_correct_name_and_description(): void
    {
        self::assertEquals('setMaxResults with Collection Join Analyzer', $this->analyzer->getName());
        self::assertEquals('Detects queries using setMaxResults() with collection joins, which causes partial collection hydration', $this->analyzer->getDescription());
    }

    #[Test]
    public function it_detects_multiple_joined_tables(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t1_.id, t2_.id FROM posts t0_ LEFT JOIN comments t1_ ON t0_.id = t1_.post_id LEFT JOIN tags t2_ ON t0_.id = t2_.post_id LIMIT 10')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Should detect fetch join with multiple aliases
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_handles_complex_select_with_multiple_columns(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t0_.title, t0_.created_at, t1_.id, t1_.content, t1_.author FROM blog_posts t0_ INNER JOIN comments t1_ ON t0_.id = t1_.post_id LIMIT 25')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_explains_data_loss_scenario(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t1_.id FROM pets t0_ LEFT JOIN pictures t1_ LIMIT 1')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];

        self::assertStringContainsString('partially hydrated', $issue->getDescription());
        self::assertStringContainsString('data loss', $issue->getDescription());
    }

    #[Test]
    public function it_provides_concrete_example_in_description(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t1_.id FROM entities t0_ JOIN related t1_ LIMIT 1')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];

        // Should include Pet/pictures example
        self::assertStringContainsString('Pet', $issue->getDescription());
        self::assertStringContainsString('pictures', $issue->getDescription());
    }

    #[Test]
    public function it_handles_whitespace_variations(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT\n  t0_.id,\n  t1_.id\nFROM\n  posts t0_\nLEFT JOIN\n  comments t1_\nLIMIT 10")
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_detects_join_keyword_without_left_or_inner(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t1_.id FROM posts t0_ JOIN comments t1_ ON t0_.id = t1_.post_id LIMIT 5')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_handles_limit_with_large_values(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t1_.id FROM orders t0_ LEFT JOIN items t1_ LIMIT 1000')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Still an issue even with large LIMIT
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_includes_backtrace_when_available(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id, t1_.id FROM blog_posts t0_ LEFT JOIN comments t1_ LIMIT 1',
                [['file' => 'BlogRepository.php', 'line' => 42, 'function' => 'findPostsWithComments']],
            )
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $backtrace = $issue->getBacktrace();

        self::assertNotNull($backtrace);
        self::assertCount(1, $backtrace);
        self::assertEquals('BlogRepository.php', $backtrace[0]['file']);
    }

    #[Test]
    public function it_suggests_critical_severity(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.id, t1_.id FROM entities t0_ JOIN related t1_ LIMIT 1')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];
        $data = $issue->getData();

        // This is a CRITICAL issue due to silent data loss
        self::assertEquals('critical', $data['severity']);
    }
}
