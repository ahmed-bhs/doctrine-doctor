<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\GetReferenceAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for GetReferenceAnalyzer.
 *
 * This analyzer detects inefficient entity loading patterns where find() is used
 * repeatedly when getReference() would be more appropriate. It identifies:
 * - Simple SELECT by ID queries (without JOINs)
 * - Queries that could use lazy-loaded references instead of full entity fetch
 * - Patterns that trigger unnecessary database hits for entity data
 */
final class GetReferenceAnalyzerTest extends TestCase
{
    private GetReferenceAnalyzer $analyzer;

    protected function setUp(): void
    {
        $renderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($renderer);
        $issueFactory = new \AhmedBhs\DoctrineDoctor\Factory\IssueFactory();
        $this->analyzer = new GetReferenceAnalyzer($issueFactory, $suggestionFactory, threshold: 2);
    }

    #[Test]
    public function it_returns_empty_collection_when_no_issues(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users')
            ->addQuery('SELECT * FROM orders WHERE status = "pending"')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_simple_select_by_id_with_alias(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u.* FROM users u WHERE u.id = ?')
            ->addQuery('SELECT u.* FROM users u WHERE u.id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];

        self::assertEquals('get_reference', $issue->getType());
        self::assertStringContainsString('Inefficient Entity Loading', $issue->getTitle());
        self::assertStringContainsString('find() queries detected', $issue->getTitle());
    }

    #[Test]
    public function it_detects_simple_select_by_id_without_alias(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM products WHERE id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
        $issue = $issues->toArray()[0];

        self::assertStringContainsString('SELECT by ID queries', $issue->getDescription());
    }

    #[Test]
    public function it_detects_select_by_id_with_literal_value(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u.* FROM users u WHERE u.id = 123')
            ->addQuery('SELECT u.* FROM users u WHERE u.id = 456')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_detects_select_by_custom_id_column(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE user_id = ?')
            ->addQuery('SELECT * FROM products WHERE product_id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertGreaterThan(0, count($issues));
    }

    #[Test]
    public function it_ignores_queries_with_joins(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u.* FROM users u JOIN orders o ON u.id = o.user_id WHERE u.id = ?')
            ->addQuery('SELECT u.* FROM users u LEFT JOIN posts p ON u.id = p.author_id WHERE u.id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_respects_threshold_parameter(): void
    {
        $analyzer = new GetReferenceAnalyzer(
            new \AhmedBhs\DoctrineDoctor\Factory\IssueFactory(),
            new SuggestionFactory(new InMemoryTemplateRenderer()),
            threshold: 5,
        );

        // Below threshold (4 queries)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->build();

        $issues = $analyzer->analyze($queries);
        self::assertCount(0, $issues);

        // At threshold (5 queries)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->build();

        $issues = $analyzer->analyze($queries);
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_aggregates_queries_from_multiple_tables(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM products WHERE id = ?')
            ->addQuery('SELECT * FROM orders WHERE id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];

        $description = $issue->getDescription();
        self::assertStringContainsString('3', $description);
        self::assertStringContainsString('table', $description);
        self::assertStringContainsString('users', $description);
        self::assertStringContainsString('products', $description);
        self::assertStringContainsString('orders', $description);
    }

    #[Test]
    public function it_provides_correct_count_in_title(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];

        self::assertStringContainsString('3 find() queries detected', $issue->getTitle());
    }

    #[Test]
    public function it_mentions_getreference_in_description(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];

        self::assertStringContainsString('getReference()', $issue->getDescription());
        self::assertStringContainsString('find()', $issue->getDescription());
    }

    #[Test]
    public function it_handles_empty_query_collection(): void
    {
        $queries = QueryDataBuilder::create()->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_uses_generator_pattern(): void
    {
        $queries = QueryDataBuilder::create()->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertInstanceOf(\Generator::class, $issues->getIterator());
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        self::assertInstanceOf(\AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface::class, $this->analyzer);
    }

    #[Test]
    public function it_provides_suggestion(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
        self::assertIsArray($suggestion->toArray());
    }

    #[Test]
    public function it_includes_threshold_in_description(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];

        self::assertStringContainsString('threshold', $issue->getDescription());
        self::assertStringContainsString('2', $issue->getDescription());
    }

    #[Test]
    public function it_attaches_query_data_to_issue(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM products WHERE id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $queries = $issue->getQueries();

        self::assertIsArray($queries);
        self::assertCount(2, $queries);
    }

    #[Test]
    public function it_handles_backtrace_from_first_query(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT * FROM users WHERE id = ?',
                [['file' => 'UserRepository.php', 'line' => 42]],
                10.0,
            )
            ->addQuery('SELECT * FROM products WHERE id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $backtrace = $issue->getBacktrace();

        self::assertNotNull($backtrace);
        self::assertIsArray($backtrace);
    }

    #[Test]
    public function it_detects_case_insensitive_select(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('select * from users where id = ?')
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_handles_mixed_query_types(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('INSERT INTO logs (message) VALUES ("test")')
            ->addQuery('SELECT * FROM products WHERE id = ?')
            ->addQuery('UPDATE users SET status = "active" WHERE id = 5')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];

        // Should only count the 2 SELECT queries
        self::assertStringContainsString('2 find() queries', $issue->getTitle());
    }

    #[Test]
    public function it_detects_select_with_different_id_patterns(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT t0_.* FROM users t0_ WHERE t0_.id = ?')
            ->addQuery('SELECT a.* FROM products a WHERE a.product_id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Should detect both patterns
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_handles_table_name_extraction(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM app_users WHERE id = ?')
            ->addQuery('SELECT * FROM app_users WHERE id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];

        self::assertStringContainsString('app_users', $issue->getDescription());
    }

    #[Test]
    public function it_provides_correct_severity(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $severity = $issue->getSeverity();

        self::assertNotNull($severity);
        self::assertContains($severity->value, ['info', 'warning', 'critical']);
    }
}
