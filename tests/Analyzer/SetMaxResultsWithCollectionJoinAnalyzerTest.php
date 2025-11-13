<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Analyzer\SetMaxResultsWithCollectionJoinAnalyzer;
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
        $sqlExtractor = new SqlStructureExtractor();
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

    #[Test]
    public function it_ignores_translation_join_with_locale_filter_in_on_clause(): void
    {
        // Sylius pattern: Translation join with locale filter
        // This is SAFE because locale filter ensures at most 1 translation per product
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT s0_.code, s0_.id, s1_.name, s1_.slug, s1_.id ' .
                'FROM sylius_product s0_ ' .
                'INNER JOIN sylius_product_translation s1_ ON s0_.id = s1_.translatable_id AND (s1_.locale = ?) ' .
                'WHERE s0_.enabled = ? ' .
                'LIMIT 4'
            )
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Should NOT flag this as an issue - locale filter ensures single row per product
        self::assertCount(0, $issues, 'Translation joins with locale filter should be safe');
    }

    #[Test]
    public function it_ignores_translation_join_with_locale_in_and_clause(): void
    {
        // Alternative pattern: locale filter as separate AND condition
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT t0_.id, t0_.name, t1_.id, t1_.content ' .
                'FROM blog_post t0_ ' .
                'INNER JOIN blog_post_translation t1_ ON t0_.id = t1_.post_id AND t1_.locale = ? ' .
                'LIMIT 10'
            )
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues, 'Translation joins with AND locale = ? should be safe');
    }

    #[Test]
    public function it_detects_true_collection_join_without_locale_filter(): void
    {
        // This is a REAL problem: joining multiple images without any constraint
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT p0_.id, p0_.name, i1_.id, i1_.url ' .
                'FROM product p0_ ' .
                'LEFT JOIN product_images i1_ ON p0_.id = i1_.product_id ' .
                'LIMIT 10'
            )
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Should flag this - no constraint on images, could load partial collection
        self::assertCount(1, $issues, 'Unconstrained collection joins should be flagged');
    }

    #[Test]
    public function it_handles_real_sylius_query_from_repository(): void
    {
        // Exact query from Sylius that was a false positive
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT s0_.code AS code_0, s0_.created_at AS created_at_1, s0_.updated_at AS updated_at_2, ' .
                's0_.enabled AS enabled_3, s0_.id AS id_4, s0_.variant_selection_method AS variant_selection_method_5, ' .
                's0_.average_rating AS average_rating_6, s1_.name AS name_7, s1_.slug AS slug_8, ' .
                's1_.description AS description_9, s1_.meta_keywords AS meta_keywords_10, ' .
                's1_.meta_description AS meta_description_11, s1_.id AS id_12, s1_.short_description AS short_description_13, ' .
                's1_.locale AS locale_14, s0_.main_taxon_id AS main_taxon_id_15, s0_.product_type_id AS product_type_id_16, ' .
                's1_.translatable_id AS translatable_id_17 ' .
                'FROM sylius_product s0_ ' .
                'INNER JOIN sylius_product_translation s1_ ON s0_.id = s1_.translatable_id AND (s1_.locale = ?) ' .
                'WHERE EXISTS (SELECT 1 FROM sylius_product_channels s2_ WHERE s2_.product_id = s0_.id AND s2_.channel_id IN (?)) ' .
                'AND s0_.enabled = ? ' .
                'ORDER BY s0_.created_at DESC, s0_.id ASC ' .
                'LIMIT 4'
            )
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // This is SAFE - locale filter ensures single translation per product
        self::assertCount(0, $issues, 'Sylius product query with translation should not trigger false positive');
    }

    #[Test]
    public function it_detects_multiple_unfiltered_collections(): void
    {
        // Real problem: joining multiple collections without constraints
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT p0_.id, c1_.id, t2_.id ' .
                'FROM posts p0_ ' .
                'LEFT JOIN comments c1_ ON p0_.id = c1_.post_id ' .
                'LEFT JOIN tags t2_ ON p0_.id = t2_.post_id ' .
                'LIMIT 10'
            )
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Should flag - no constraints on collections
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_handles_case_insensitive_locale_keyword(): void
    {
        // Test with lowercase 'locale' keyword
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'select t0_.id, t1_.id from product t0_ ' .
                'inner join product_translation t1_ on t0_.id = t1_.product_id and (t1_.locale = ?) ' .
                'limit 5'
            )
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Should recognize locale filter even in lowercase
        self::assertCount(0, $issues);
    }
}
