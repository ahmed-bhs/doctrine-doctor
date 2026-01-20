<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\GetReferenceAnalyzer;
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

    #[Test]
    public function it_does_not_flag_queries_with_additional_where_conditions(): void
    {
        // Real-world example from Sylius: queries with business logic filters
        // These CANNOT be replaced by getReference() as it only handles id = ?
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT s0_.id FROM sylius_order s0_ WHERE s0_.id = ? AND s0_.state = ?')
            ->addQuery('SELECT s0_.id FROM sylius_order s0_ WHERE s0_.id = ? AND s0_.state = ? AND s0_.channel_id = ?')
            ->addQuery('SELECT u0_.id FROM users u0_ WHERE u0_.id = ? OR u0_.email = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        // Should NOT detect these as candidates for getReference()
        // because getReference() cannot handle additional WHERE conditions
        self::assertCount(0, $issues, 'Should not flag queries with additional WHERE conditions (AND/OR)');
    }

    #[Test]
    public function it_detects_simple_select_by_id_without_additional_conditions(): void
    {
        // These are valid candidates for getReference()
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM users u0_ WHERE u0_.id = ?')
            ->addQuery('SELECT * FROM products WHERE id = 123')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues, 'Should detect simple SELECT by ID as candidates for getReference()');
    }

    #[Test]
    public function it_groups_identical_queries_in_profiler_output(): void
    {
        // Simulate 31 identical queries (like in real Sylius scenario)
        $builder = QueryDataBuilder::create();

        // Add same query 31 times
        for ($i = 0; $i < 31; $i++) {
            $builder->addQuery('SELECT * FROM sylius_product_variant WHERE id = ?');
        }

        $queries = $builder->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];

        // Should report 31 queries detected
        self::assertStringContainsString('31 find() queries detected', $issue->getTitle());

        // But queries array should contain only 1 unique example (not 31 duplicates)
        $issueData = $issue->getData();
        self::assertArrayHasKey('queries', $issueData);
        self::assertCount(1, $issueData['queries'], 'Should only show one unique query example, not all 31 duplicates');
    }

    #[Test]
    public function it_shows_all_unique_query_patterns_when_different(): void
    {
        // Different queries should all be shown
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM users WHERE id = ?')  // duplicate
            ->addQuery('SELECT * FROM products WHERE id = ?')  // different
            ->addQuery('SELECT * FROM orders WHERE id = ?')    // different
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];

        // Should report 4 total queries
        self::assertStringContainsString('4 find() queries detected', $issue->getTitle());

        // But should show 3 unique patterns (users appears twice but counted once)
        $issueData = $issue->getData();
        self::assertCount(3, $issueData['queries'], 'Should show 3 unique query patterns');
    }

    #[Test]
    public function it_detects_lazy_loading_from_backtrace(): void
    {
        $backtrace = [
            ['class' => 'Doctrine\ORM\Persisters\Entity\BasicEntityPersister', 'function' => 'executeQuery'],
            ['class' => 'Doctrine\ORM\Persisters\Entity\BasicEntityPersister', 'function' => 'loadOneToManyCollection'],
            ['class' => 'Doctrine\ORM\UnitOfWork', 'function' => 'loadCollection'],
            ['class' => 'Doctrine\ORM\PersistentCollection', 'function' => 'initialize'],
        ];

        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace('SELECT * FROM sylius_product_image WHERE id = ?', $backtrace)
            ->addQueryWithBacktrace('SELECT * FROM sylius_channel_pricing WHERE id = ?', $backtrace)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];

        // Should detect lazy loading
        $data = $issue->getData();
        self::assertEquals('lazy_loading', $data['type']);
        self::assertStringContainsString('Lazy Loading Detected', $issue->getTitle());
        self::assertStringContainsString('eager loading', $issue->getDescription());
        self::assertStringNotContainsString('getReference()', $issue->getDescription());
    }

    #[Test]
    public function it_detects_explicit_find_without_lazy_loading_backtrace(): void
    {
        $backtrace = [
            ['class' => 'App\Repository\UserRepository', 'function' => 'find'],
            ['class' => 'App\Controller\UserController', 'function' => 'showAction'],
        ];

        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace('SELECT * FROM users WHERE id = ?', $backtrace)
            ->addQueryWithBacktrace('SELECT * FROM users WHERE id = ?', $backtrace)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];

        // Should detect explicit find()
        self::assertEquals('get_reference', $issue->getType());
        self::assertStringContainsString('find() queries detected', $issue->getTitle());
        self::assertStringContainsString('getReference()', $issue->getDescription());
        self::assertStringNotContainsString('eager loading', $issue->getDescription());
    }

    #[Test]
    public function it_handles_queries_without_backtrace_as_explicit_find(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = ?')
            ->addQuery('SELECT * FROM products WHERE id = ?')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];

        // Without backtrace, should default to explicit find()
        self::assertEquals('get_reference', $issue->getType());
    }

    #[Test]
    public function it_detects_lazy_loading_with_persistent_collection(): void
    {
        $backtrace = [
            ['class' => 'Doctrine\ORM\PersistentCollection', 'function' => 'initialize'],
            ['class' => 'Doctrine\Common\Collections\AbstractLazyCollection', 'function' => 'containsKey'],
        ];

        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace('SELECT * FROM product_images WHERE id = ?', $backtrace)
            ->addQueryWithBacktrace('SELECT * FROM product_variants WHERE id = ?', $backtrace)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];

        $data = $issue->getData();
        self::assertEquals('lazy_loading', $data['type']);
        self::assertStringContainsString('Lazy Loading Detected', $issue->getTitle());
    }

    #[Test]
    public function it_provides_appropriate_severity_for_lazy_loading(): void
    {
        $backtrace = [
            ['class' => 'Doctrine\ORM\UnitOfWork', 'function' => 'loadCollection'],
        ];

        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace('SELECT * FROM images WHERE id = ?', $backtrace)
            ->addQueryWithBacktrace('SELECT * FROM images WHERE id = ?', $backtrace)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];

        // Lazy loading should be INFO severity (less critical than explicit misuse)
        self::assertEquals('info', $issue->getData()['severity']);
    }
}
