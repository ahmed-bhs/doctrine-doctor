<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\CollectionJoinDetector;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\CartesianProductAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\InMemoryTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CartesianProductAnalyzerTest extends TestCase
{
    private CartesianProductAnalyzer $analyzer;

    protected function setUp(): void
    {
        $renderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($renderer);
        $issueFactory = new IssueFactory();
        $sqlExtractor = new SqlStructureExtractor();
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager();
        $collectionJoinDetector = new CollectionJoinDetector($entityManager, $sqlExtractor);

        $this->analyzer = new CartesianProductAnalyzer(
            $issueFactory,
            $suggestionFactory,
            $sqlExtractor,
            $collectionJoinDetector,
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_no_issues(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1')
            ->addQuery('SELECT name, email FROM products')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_single_collection_join(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id, o1_.id FROM users u0_ ' .
                'LEFT JOIN orders o1_ ON u0_.id = o1_.user_id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_multiple_collection_joins(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id, o1_.id, bp2_.id FROM users u0_ ' .
                'LEFT JOIN orders o1_ ON u0_.id = o1_.user_id ' .
                'LEFT JOIN blog_posts bp2_ ON u0_.id = bp2_.author_id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $issue = $issuesArray[0];
        self::assertStringContainsString('Cartesian Product', $issue->getTitle());
        self::assertStringContainsString('O(n^2)', $issue->getTitle());
        self::assertStringContainsString('cartesian product', $issue->getDescription());
    }

    #[Test]
    public function it_ignores_non_select_queries(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('INSERT INTO logs (message) VALUES ("test")')
            ->addQuery('UPDATE users SET name = "test"')
            ->addQuery('DELETE FROM temp_data')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_ignores_non_collection_joins(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT o0_.id, u1_.id FROM orders o0_ ' .
                'LEFT JOIN users u1_ ON o0_.user_id = u1_.id ' .
                'LEFT JOIN blog_posts bp2_ ON bp2_.author_id = u1_.id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        $issuesArray = $issues->toArray();
        $cartesianIssues = array_filter($issuesArray, static fn ($issue) => str_contains((string) $issue->getTitle(), 'Cartesian Product'));

        self::assertCount(0, $cartesianIssues);
    }

    #[Test]
    public function it_provides_suggestion_with_multi_step_hydration(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id, o1_.id, bp2_.id FROM users u0_ ' .
                'LEFT JOIN orders o1_ ON u0_.id = o1_.user_id ' .
                'LEFT JOIN blog_posts bp2_ ON u0_.id = bp2_.author_id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];
        self::assertNotNull($issue->getSuggestion());
    }

    #[Test]
    public function it_deduplicates_same_pattern(): void
    {
        $sql = 'SELECT u0_.id, o1_.id, bp2_.id FROM users u0_ ' .
            'LEFT JOIN orders o1_ ON u0_.id = o1_.user_id ' .
            'LEFT JOIN blog_posts bp2_ ON u0_.id = bp2_.author_id';

        $queries = QueryDataBuilder::create()
            ->addQuery($sql)
            ->addQuery($sql)
            ->addQuery($sql)
            ->build();

        $issues = $this->analyzer->analyze($queries);

        $issuesArray = $issues->toArray();
        $cartesianIssues = array_filter($issuesArray, static fn ($issue) => str_contains((string) $issue->getTitle(), 'Cartesian Product'));

        self::assertCount(1, $cartesianIssues);
    }

    #[Test]
    public function it_sets_critical_severity(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id, o1_.id, bp2_.id FROM users u0_ ' .
                'LEFT JOIN orders o1_ ON u0_.id = o1_.user_id ' .
                'LEFT JOIN blog_posts bp2_ ON u0_.id = bp2_.author_id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];
        self::assertEquals('critical', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_has_correct_name_and_description(): void
    {
        self::assertEquals('Cartesian Product Analyzer', $this->analyzer->getName());
        self::assertStringContainsString('cartesian product', strtolower($this->analyzer->getDescription()));
    }

    #[Test]
    public function it_ignores_inner_joins(): void
    {
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id, o1_.id, bp2_.id FROM users u0_ ' .
                'INNER JOIN orders o1_ ON u0_.id = o1_.user_id ' .
                'INNER JOIN blog_posts bp2_ ON u0_.id = bp2_.author_id')
            ->build();

        $issues = $this->analyzer->analyze($queries);

        $issuesArray = $issues->toArray();
        $cartesianIssues = array_filter($issuesArray, static fn ($issue) => str_contains((string) $issue->getTitle(), 'Cartesian Product'));

        self::assertCount(0, $cartesianIssues);
    }

    #[Test]
    public function it_detects_cartesian_product_risk_from_n1_collections(): void
    {
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 5; $i++) {
            $builder->addQuery(
                'SELECT k0_.id, k0_.name FROM keyword k0_ ' .
                'INNER JOIN resource_keyword rk1_ ON k0_.id = rk1_.keyword_id ' .
                'WHERE rk1_.resource_id = ?',
            );
            $builder->addQuery(
                'SELECT t0_.id, t0_.name FROM topic t0_ ' .
                'INNER JOIN resource_topic rt1_ ON t0_.id = rt1_.topic_id ' .
                'WHERE rt1_.resource_id = ?',
            );
            $builder->addQuery(
                'SELECT o0_.id, o0_.name FROM organization o0_ ' .
                'INNER JOIN resource_organization ro1_ ON o0_.id = ro1_.organization_id ' .
                'WHERE ro1_.resource_id = ?',
            );
        }

        $issues = $this->analyzer->analyze($builder->build());

        $issuesArray = $issues->toArray();
        $riskIssues = array_filter(
            $issuesArray,
            static fn ($issue) => str_contains((string) $issue->getTitle(), 'Cartesian Product Risk'),
        );

        self::assertCount(1, $riskIssues);

        $issue = array_values($riskIssues)[0];
        self::assertStringContainsString('3 Collections', $issue->getTitle());
        self::assertStringContainsString('resource', $issue->getTitle());
        self::assertStringContainsString('Multi-Step Hydration', $issue->getTitle());
        self::assertEquals('warning', $issue->getSeverity()->value);
        self::assertNotNull($issue->getSuggestion());
    }

    #[Test]
    public function it_ignores_n1_collections_below_threshold(): void
    {
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 2; $i++) {
            $builder->addQuery(
                'SELECT k0_.id FROM keyword k0_ ' .
                'INNER JOIN resource_keyword rk1_ ON k0_.id = rk1_.keyword_id ' .
                'WHERE rk1_.resource_id = ?',
            );
            $builder->addQuery(
                'SELECT t0_.id FROM topic t0_ ' .
                'INNER JOIN resource_topic rt1_ ON t0_.id = rt1_.topic_id ' .
                'WHERE rt1_.resource_id = ?',
            );
        }

        $issues = $this->analyzer->analyze($builder->build());

        $issuesArray = $issues->toArray();
        $riskIssues = array_filter(
            $issuesArray,
            static fn ($issue) => str_contains((string) $issue->getTitle(), 'Cartesian Product Risk'),
        );

        self::assertCount(0, $riskIssues);
    }

    #[Test]
    public function it_ignores_single_collection_n1_group(): void
    {
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 5; $i++) {
            $builder->addQuery(
                'SELECT k0_.id FROM keyword k0_ ' .
                'INNER JOIN resource_keyword rk1_ ON k0_.id = rk1_.keyword_id ' .
                'WHERE rk1_.resource_id = ?',
            );
        }

        $issues = $this->analyzer->analyze($builder->build());

        $issuesArray = $issues->toArray();
        $riskIssues = array_filter(
            $issuesArray,
            static fn ($issue) => str_contains((string) $issue->getTitle(), 'Cartesian Product Risk'),
        );

        self::assertCount(0, $riskIssues);
    }

    #[Test]
    public function it_detects_risk_from_simple_where_pattern(): void
    {
        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 4; $i++) {
            $builder->addQuery(
                'SELECT t0_.id, t0_.content FROM keyword_translation t0_ WHERE t0_.translatable_id = ?',
            );
            $builder->addQuery(
                'SELECT t0_.id, t0_.content FROM topic_translation t0_ WHERE t0_.translatable_id = ?',
            );
        }

        $issues = $this->analyzer->analyze($builder->build());

        $issuesArray = $issues->toArray();
        $riskIssues = array_filter(
            $issuesArray,
            static fn ($issue) => str_contains((string) $issue->getTitle(), 'Cartesian Product Risk'),
        );

        self::assertCount(1, $riskIssues);

        $issue = array_values($riskIssues)[0];
        self::assertStringContainsString('2 Collections', $issue->getTitle());
        self::assertStringContainsString('translatable', $issue->getTitle());
    }

    #[Test]
    public function it_detects_risk_with_custom_threshold(): void
    {
        $renderer = new InMemoryTemplateRenderer();
        $suggestionFactory = new SuggestionFactory($renderer);
        $issueFactory = new IssueFactory();
        $sqlExtractor = new SqlStructureExtractor();
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager();
        $collectionJoinDetector = new CollectionJoinDetector($entityManager, $sqlExtractor);

        $analyzer = new CartesianProductAnalyzer(
            $issueFactory,
            $suggestionFactory,
            $sqlExtractor,
            $collectionJoinDetector,
            n1CollectionThreshold: 2,
        );

        $builder = QueryDataBuilder::create();
        for ($i = 1; $i <= 2; $i++) {
            $builder->addQuery(
                'SELECT k0_.id FROM keyword k0_ ' .
                'INNER JOIN resource_keyword rk1_ ON k0_.id = rk1_.keyword_id ' .
                'WHERE rk1_.resource_id = ?',
            );
            $builder->addQuery(
                'SELECT t0_.id FROM topic t0_ ' .
                'INNER JOIN resource_topic rt1_ ON t0_.id = rt1_.topic_id ' .
                'WHERE rt1_.resource_id = ?',
            );
        }

        $issues = $analyzer->analyze($builder->build());

        $issuesArray = $issues->toArray();
        $riskIssues = array_filter(
            $issuesArray,
            static fn ($issue) => str_contains((string) $issue->getTitle(), 'Cartesian Product Risk'),
        );

        self::assertCount(1, $riskIssues);
    }
}
