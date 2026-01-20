<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CollectionInitializationAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ArticleWithArrayInit;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ArticleWithConstructorButNoInit;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BaseOrderWithInit;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPostWithoutCollectionInit;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Category;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ChildOrderNoConstructor;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ChildOrderWithParentInit;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Comment;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CommentWithoutInit;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CourseWithMultipleCollections;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrderItem;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrderWithoutConstructor;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Tag;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;

/**
 * Comprehensive tests for CollectionInitializationAnalyzer.
 *
 * This analyzer analyzes SOURCE CODE of constructors using Reflection
 * to verify that collections are properly initialized.
 *
 * Tests detection of:
 * 1. Entity WITHOUT constructor but with collections (CRITICAL)
 * 2. Collection NOT initialized in constructor (CRITICAL) - reads source code
 * 3. Patterns: $this->fieldName = new ArrayCollection() or $this->fieldName = []
 *
 * Difference with CollectionEmptyAccessAnalyzer:
 * - CollectionEmptyAccess: verifies LOADED instances in UnitOfWork
 * - CollectionInitialization: analyzes constructor SOURCE CODE
 */
final class CollectionInitializationAnalyzerTest extends DatabaseTestCase
{
    private CollectionInitializationAnalyzer $analyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        // Create schema for entities
        $this->createSchema([
            BlogPost::class,
            BlogPostWithoutCollectionInit::class,
            OrderWithoutConstructor::class,
            ArticleWithArrayInit::class,
            ArticleWithConstructorButNoInit::class,
            \AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ArticleComment::class,
            CourseWithMultipleCollections::class,
            Comment::class,
            CommentWithoutInit::class,
            User::class,
            Product::class,
            Category::class,
            Tag::class,
            OrderItem::class,
            ChildOrderWithParentInit::class,
            ChildOrderNoConstructor::class,
        ]);

        $this->analyzer = new CollectionInitializationAnalyzer(
            $this->entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_missing_constructor(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - OrderWithoutConstructor has no constructor but has collections
        $issuesArray = $issues->toArray();
        $missingConstructorIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'OrderWithoutConstructor') &&
                          str_contains($issue->getTitle(), 'Missing constructor'),
        );

        self::assertNotEmpty($missingConstructorIssues, 'Should detect missing constructor');

        $issue = reset($missingConstructorIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertStringContainsString('no constructor', $issue->getDescription());
        self::assertStringContainsString('OrderWithoutConstructor', $issue->getTitle());
    }

    #[Test]
    public function it_detects_uninitialized_collection_in_constructor(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - ArticleWithConstructorButNoInit has constructor but doesn't initialize collection
        $issuesArray = $issues->toArray();
        $uninitializedIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'ArticleWithConstructorButNoInit') &&
                          str_contains($issue->getTitle(), 'Uninitialized collection'),
        );

        self::assertNotEmpty($uninitializedIssues, 'Should detect uninitialized collection in constructor');

        $issue = reset($uninitializedIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertStringContainsString('not initialized', $issue->getDescription());
        self::assertStringContainsString('constructor', $issue->getDescription());
    }

    #[Test]
    public function it_does_not_flag_properly_initialized_collections(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - BlogPost properly initializes collections with new ArrayCollection()
        $issuesArray = $issues->toArray();
        $blogPostIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'BlogPost') &&
                          !str_contains($issue->getTitle(), 'BlogPostWithoutCollectionInit'),
        );

        self::assertCount(0, $blogPostIssues, 'Should NOT flag properly initialized collections');
    }

    #[Test]
    public function it_detects_array_initialization(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - ArticleWithArrayInit uses $this->items = []
        $issuesArray = $issues->toArray();
        $arrayInitIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'ArticleWithArrayInit'),
        );

        // Should NOT flag array initialization as it's valid
        self::assertCount(0, $arrayInitIssues, 'Should NOT flag array initialization [] as it is valid');
    }

    #[Test]
    public function it_detects_arraycollection_initialization(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - BlogPost uses new ArrayCollection()
        $issuesArray = $issues->toArray();
        $blogPostIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'BlogPost') &&
                          !str_contains($issue->getTitle(), 'Without'),
        );

        self::assertCount(0, $blogPostIssues, 'Should recognize ArrayCollection initialization as valid');
    }

    #[Test]
    public function it_provides_suggestions(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        if (empty($issuesArray)) {
            self::markTestSkipped('No collection initialization issues found');
        }

        $issue = reset($issuesArray);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion, 'Should provide suggestion');
    }

    #[Test]
    public function it_provides_critical_severity(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        if (empty($issuesArray)) {
            self::markTestSkipped('No collection initialization issues found');
        }

        foreach ($issuesArray as $issue) {
            $severity = $issue->getSeverity();
            $severityValue = is_object($severity) ? $severity->value : $severity;
            self::assertSame('critical', $severityValue, 'Collection initialization issues should be critical');
        }
    }

    #[Test]
    public function it_handles_entity_without_collections(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - Product and Category have no collections
        $issuesArray = $issues->toArray();
        $productIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Product'),
        );
        $categoryIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Category'),
        );

        self::assertCount(0, $productIssues, 'Should NOT flag entity without collections (Product)');
        self::assertCount(0, $categoryIssues, 'Should NOT flag entity without collections (Category)');
    }

    #[Test]
    public function it_detects_multiple_uninitialized_collections(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - CourseWithMultipleCollections has 3 uninitialized collections
        $issuesArray = $issues->toArray();
        $courseIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'CourseWithMultipleCollections'),
        );

        self::assertGreaterThanOrEqual(
            3,
            count($courseIssues),
            'Should detect all 3 uninitialized collections (students, teachers, tags)',
        );
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

    #[Test]
    public function it_returns_issue_collection(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertIsObject($issues);
        self::assertIsIterable($issues);
    }

    #[Test]
    public function it_has_proper_analyzer_metadata(): void
    {
        // Assert
        self::assertSame('Collection Initialization Analyzer', $this->analyzer->getName());
        self::assertStringContainsString('collections', strtolower($this->analyzer->getDescription()));
        self::assertStringContainsString('constructor', strtolower($this->analyzer->getDescription()));
    }

    #[Test]
    public function it_provides_backtrace_information(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        if (empty($issuesArray)) {
            self::markTestSkipped('No collection initialization issues found');
        }

        $uninitializedIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Uninitialized collection'),
        );

        if (!empty($uninitializedIssues)) {
            $issue = reset($uninitializedIssues);
            assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
            self::assertNotFalse($issue);
            $backtrace = $issue->getBacktrace();

            // Backtrace should contain file and line for uninitialized collections
            self::assertIsArray($backtrace);
            self::assertArrayHasKey('file', $backtrace);
            self::assertArrayHasKey('line', $backtrace);
        }
    }

    #[Test]
    public function it_handles_different_collection_types(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - OrderWithoutConstructor has both OneToMany and ManyToMany
        $issuesArray = $issues->toArray();
        $orderIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'OrderWithoutConstructor'),
        );

        // Should detect both 'items' (OneToMany) and 'tags' (ManyToMany)
        self::assertGreaterThanOrEqual(
            2,
            count($orderIssues),
            'Should detect both OneToMany and ManyToMany collections',
        );
    }

    #[Test]
    public function it_does_not_flag_child_with_parent_constructor_init(): void
    {
        // Arrange - ChildOrderWithParentInit extends BaseOrderWithInit
        // Parent initializes items, child calls parent::__construct()
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - Should NOT flag items (initialized in parent)
        $issuesArray = $issues->toArray();
        $childOrderIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'ChildOrderWithParentInit'),
        );

        // Filter to check only items (not comments which is initialized in child)
        $parentCollectionIssues = array_filter(
            $childOrderIssues,
            fn ($issue) => str_contains($issue->getTitle(), '::$items'),
        );

        self::assertCount(
            0,
            $parentCollectionIssues,
            'Should NOT flag collections initialized in parent constructor (items)',
        );
    }

    #[Test]
    public function it_does_not_flag_child_without_constructor_when_parent_initializes(): void
    {
        // Arrange - ChildOrderNoConstructor has NO constructor
        // But extends BaseOrderWithInit which initializes collections
        // PHP automatically calls parent::__construct()
        //
        // This is the EXACT scenario of Sylius Order:
        // App\Entity\Order extends Core\Model\Order extends Order\Model\Order
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - Should NOT flag items
        $issuesArray = $issues->toArray();
        $childOrderIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'ChildOrderNoConstructor'),
        );

        self::assertCount(
            0,
            $childOrderIssues,
            'Should NOT flag collections when parent constructor initializes them (Sylius scenario)',
        );
    }

    #[Test]
    public function it_still_flags_uninitialized_collections_in_hierarchy(): void
    {
        // Arrange - BlogPostWithoutCollectionInit has constructor but doesn't initialize
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert - Should STILL flag uninit collections even with hierarchy check
        $issuesArray = $issues->toArray();
        $blogPostIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'BlogPostWithoutCollectionInit'),
        );

        self::assertGreaterThan(
            0,
            count($blogPostIssues),
            'Should still flag truly uninitialized collections',
        );
    }
}
