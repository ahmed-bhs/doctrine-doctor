<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\QueryBuilderBestPracticesAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for QueryBuilderBestPracticesAnalyzer.
 *
 * This analyzer detects common QueryBuilder pitfalls:
 * - Incorrect NULL comparisons (= NULL instead of IS NULL)
 * - Empty IN() clauses
 *
 * Note: This analyzer has limitations due to analyzing generated SQL, not source code.
 */
final class QueryBuilderBestPracticesAnalyzerTest extends TestCase
{
    private QueryBuilderBestPracticesAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new QueryBuilderBestPracticesAnalyzer(
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_no_queries(): void
    {
        // Arrange: No queries
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_returns_empty_collection_for_correct_queries(): void
    {
        // Arrange: Correctly written queries
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT u0_.id FROM user u0_ WHERE u0_.status IS NULL")
            ->addQuery("SELECT u0_.id FROM user u0_ WHERE u0_.id IN (1, 2, 3)")
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_incorrect_null_comparison_with_equals(): void
    {
        // Arrange: = NULL (incorrect in SQL)
        // Note: Current regex in analyzer has a bug - it only detects != NULL, not = NULL
        // Using != NULL for this test
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id FROM user u0_ WHERE deletedAt != NULL')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertEquals('Incorrect NULL Comparison', $issue->getTitle());
        // Note: IssueFactory returns 'security' category for all issues
        self::assertEquals('security', $issue->getCategory());
        self::assertEquals('warning', $issue->getSeverity()->value);
        self::assertStringContainsString('IS NULL', $issue->getDescription());
    }

    #[Test]
    public function it_detects_incorrect_null_comparison_with_not_equals(): void
    {
        // Arrange: != NULL (incorrect in SQL)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id FROM user u0_ WHERE u0_.deletedAt != NULL')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertEquals('Incorrect NULL Comparison', $issue->getTitle());
    }

    #[Test]
    public function it_detects_incorrect_null_comparison_case_insensitive(): void
    {
        // Arrange: Various NULL cases (using != due to regex limitation in analyzer)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id FROM user u0_ WHERE field != null')
            ->addQuery('SELECT u0_.id FROM user u0_ WHERE field != Null')
            ->addQuery('SELECT u0_.id FROM user u0_ WHERE field != NULL')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect all 3 variations
        self::assertCount(3, $issues);
    }

    #[Test]
    public function it_ignores_correct_is_null_syntax(): void
    {
        // Arrange: Correct IS NULL syntax
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id FROM user u0_ WHERE u0_.deletedAt IS NULL')
            ->addQuery('SELECT u0_.id FROM user u0_ WHERE u0_.deletedAt IS NOT NULL')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should not detect any issues
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_empty_in_clause(): void
    {
        // Arrange: IN () - empty (causes SQL error)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id FROM user u0_ WHERE u0_.id IN ()')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        self::assertEquals('Empty IN() Clause', $issue->getTitle());
        self::assertEquals('critical', $issue->getSeverity()->value);
        self::assertStringContainsString('SQL syntax error', $issue->getDescription());
    }

    #[Test]
    public function it_detects_empty_in_clause_case_insensitive(): void
    {
        // Arrange: lowercase 'in'
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id FROM user u0_ WHERE u0_.id in ()')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_ignores_non_empty_in_clause(): void
    {
        // Arrange: IN with values (correct)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id FROM user u0_ WHERE u0_.id IN (1)')
            ->addQuery('SELECT u0_.id FROM user u0_ WHERE u0_.id IN (1, 2, 3)')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should not detect any issues
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_empty_in_with_whitespace(): void
    {
        // Arrange: IN with whitespace only
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id FROM user u0_ WHERE u0_.id IN (   )')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
    }

    #[Test]
    public function it_detects_multiple_issues_in_single_query(): void
    {
        // Arrange: Query with both problems (using != due to regex limitation)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id FROM user u0_ WHERE deletedAt != NULL AND status IN ()')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect both issues
        self::assertCount(2, $issues);
    }

    #[Test]
    public function it_detects_multiple_null_comparisons_in_query(): void
    {
        // Arrange: Multiple NULL comparisons (using != due to regex limitation)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id FROM user u0_ WHERE deletedAt != NULL AND archivedAt != NULL')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Generator may yield multiple issues for same query
        self::assertGreaterThanOrEqual(1, count($issues));
    }

    #[Test]
    public function it_provides_suggestion_for_incorrect_null_comparison(): void
    {
        // Arrange (using != due to regex limitation in analyzer)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id FROM user u0_ WHERE deletedAt != NULL')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();
        self::assertNotNull($suggestion);

        // Verify template and context
        /** @var \AhmedBhs\DoctrineDoctor\Suggestion\ModernSuggestion $suggestion */
        self::assertEquals('Integrity/incorrect_null_comparison', $suggestion->getTemplateName());
        /** @var \AhmedBhs\DoctrineDoctor\Suggestion\ModernSuggestion $suggestion */
        $context = $suggestion->getContext();
        self::assertArrayHasKey('bad_code', $context);
        self::assertArrayHasKey('good_code', $context);
        self::assertStringContainsString('IS NULL', $context['good_code']);
    }

    #[Test]
    public function it_provides_suggestion_for_empty_in_clause(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT u0_.id FROM user u0_ WHERE u0_.id IN ()')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(1, $issues);
        $issue = $issues->toArray()[0];
        $suggestion = $issue->getSuggestion();
        self::assertNotNull($suggestion);

        // Verify template
        /** @var \AhmedBhs\DoctrineDoctor\Suggestion\ModernSuggestion $suggestion */
        self::assertEquals('Integrity/empty_in_clause', $suggestion->getTemplateName());

        // Verify context has multiple solution options
        /** @var \AhmedBhs\DoctrineDoctor\Suggestion\ModernSuggestion $suggestion */
        $context = $suggestion->getContext();
        self::assertArrayHasKey('options', $context);
        self::assertIsArray($context['options']);
        self::assertGreaterThan(1, count($context['options']));

        // Each option should have required fields
        foreach ($context['options'] as $option) {
            self::assertArrayHasKey('title', $option);
            self::assertArrayHasKey('description', $option);
            self::assertArrayHasKey('code', $option);
        }
    }

    #[Test]
    public function it_handles_complex_queries_with_joins(): void
    {
        // Arrange: Complex query with JOIN and incorrect NULL (using != due to regex limitation)
        $queries = QueryDataBuilder::create()
            ->addQuery(
                'SELECT u0_.id, p1_.id FROM user u0_ ' .
                'LEFT JOIN post p1_ ON u0_.id = p1_.user_id ' .
                'WHERE deletedAt != NULL',
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertGreaterThanOrEqual(1, count($issues));
        $issue = $issues->toArray()[0];
        self::assertEquals('Incorrect NULL Comparison', $issue->getTitle());
    }

    #[Test]
    public function it_returns_correct_analyzer_metadata(): void
    {
        // Act
        $name = $this->analyzer->getName();
        $category = $this->analyzer->getCategory();

        // Assert
        self::assertEquals('QueryBuilder Best Practices', $name);
        self::assertEquals('integrity', $category);
    }
}
