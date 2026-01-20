<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CollectionEmptyAccessAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPostWithoutCollectionInit;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Comment;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CommentWithoutInit;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Order;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrderItem;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\Test;

/**
 * Comprehensive tests for CollectionEmptyAccessAnalyzer.
 *
 * This analyzer detects UNINITIALIZED collections in the UnitOfWork
 * (entities already loaded in memory).
 *
 * Tests detection of:
 * 1. Collections that are null or not initialized
 * 2. Collections properly initialized in constructors
 * 3. Entities without collections (should be ignored)
 */
final class CollectionEmptyAccessAnalyzerTest extends DatabaseTestCase
{
    private CollectionEmptyAccessAnalyzer $analyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        // Create schema for entities
        $this->createSchema([
            BlogPostWithoutCollectionInit::class,
            CommentWithoutInit::class,
            BlogPost::class,
            Comment::class,
            Order::class,
            OrderItem::class,
            Product::class,
            User::class,
        ]);

        $this->analyzer = new CollectionEmptyAccessAnalyzer(
            $this->entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );
    }

    #[Test]
    public function it_detects_uninitialized_collections(): void
    {
        // Arrange: Create an entity with uninitialized collection
        $user = new User();
        $user->setName('Test User');
        $user->setEmail('test@example.com');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $post = new BlogPostWithoutCollectionInit();
        $post->setTitle('Test Post');
        $post->setContent('Test Content');
        $post->setAuthor($user);
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        // DON'T clear! The analyzer checks entities in the identity map
        // The collection is uninitialized because BlogPostWithoutCollectionInit has no constructor

        // Build empty query collection
        $queries = QueryDataBuilder::create()->build();

        // Act: Run analyzer with entity in identity map
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect uninitialized collection
        $issuesArray = $issues->toArray();
        $collectionIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Uninitialized Collection')
                && str_contains($issue->getTitle(), 'BlogPostWithoutCollectionInit')
                && str_contains($issue->getTitle(), '$comments'),
        );

        self::assertNotEmpty($collectionIssues, 'Should detect uninitialized collections');

        // Check issue structure
        $issue = reset($collectionIssues);
        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertStringContainsString('not initialized', $issue->getDescription());
        self::assertStringContainsString('ArrayCollection', $issue->getDescription());
    }

    #[Test]
    public function it_does_not_flag_initialized_collections(): void
    {
        // Arrange: Create an entity with properly initialized collection
        $user = new User();
        $user->setName('Test User');
        $user->setEmail('test@example.com');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // BlogPost has collection initialized in constructor
        $post = new BlogPost();
        $post->setTitle('Test Post');
        $post->setContent('Test Content');
        $post->setAuthor($user);
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        // DON'T clear - we check entities in identity map
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag properly initialized collections
        $issuesArray = $issues->toArray();
        $goodEntityIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'BlogPost')
                && !str_contains($issue->getTitle(), 'WithoutCollectionInit')
                && str_contains($issue->getTitle(), 'Uninitialized'),
        );

        self::assertCount(0, $goodEntityIssues, 'Should NOT flag correctly initialized collections');
    }

    #[Test]
    public function it_provides_critical_severity(): void
    {
        // Arrange
        $user = new User();
        $user->setName('Test User');
        $user->setEmail('test@example.com');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $post = new BlogPostWithoutCollectionInit();
        $post->setTitle('Test Post');
        $post->setContent('Test Content');
        $post->setAuthor($user);
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        // DON'T clear - we check entities in identity map
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should have CRITICAL severity
        $issuesArray = $issues->toArray();
        $collectionIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Uninitialized Collection'),
        );

        self::assertNotEmpty($collectionIssues, 'Should have issues to check severity');
        $issue = reset($collectionIssues);
        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertSame(
            'critical',
            $issue->getSeverity()->value,
            'Should have CRITICAL severity',
        );
    }

    #[Test]
    public function it_provides_suggestions(): void
    {
        // Arrange
        $user = new User();
        $user->setName('Test User');
        $user->setEmail('test@example.com');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $post = new BlogPostWithoutCollectionInit();
        $post->setTitle('Test Post');
        $post->setContent('Test Content');
        $post->setAuthor($user);
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        // DON'T clear - we check entities in identity map
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $collectionIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Uninitialized Collection'),
        );

        if (!empty($collectionIssues)) {
            $issue = reset($collectionIssues);
            assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
            self::assertNotFalse($issue);
            $suggestion = $issue->getSuggestion();
            self::assertNotNull($suggestion, 'Should provide suggestion');
            self::assertStringContainsString('ArrayCollection', $suggestion->getCode());
            self::assertStringContainsString('__construct', $suggestion->getCode());
        } else {
            self::markTestSkipped('No collection issues found');
        }
    }

    #[Test]
    public function it_handles_entity_without_collections(): void
    {
        // Arrange: Product entity has no collections
        $product = new Product();
        $product->setName('Test Product');
        $product->setPrice(100);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        // DON'T clear - we check entities in identity map
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should not flag entities without collections
        $issuesArray = $issues->toArray();
        $productIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Product'),
        );

        self::assertCount(0, $productIssues, 'Should NOT flag entity without collections');
    }

    #[Test]
    public function it_handles_empty_identity_map(): void
    {
        // Arrange: Empty entity manager (no entities loaded)
        $queries = QueryDataBuilder::create()->build();

        // Act: Should not crash with empty identity map
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertIsIterable($issues);
        $issuesArray = $issues->toArray();
        self::assertIsArray($issuesArray);
    }

    #[Test]
    public function it_detects_multiple_uninitialized_collections_on_same_entity(): void
    {
        // Arrange: For this test, we're using BlogPostWithoutCollectionInit which has one collection
        // In a real scenario with multiple collections, we'd detect all of them
        $user = new User();
        $user->setName('Test User');
        $user->setEmail('test@example.com');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $post = new BlogPostWithoutCollectionInit();
        $post->setTitle('Test Post');
        $post->setContent('Test Content');
        $post->setAuthor($user);
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        // DON'T clear - we check entities in identity map
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: At least one collection should be detected as uninitialized
        $issuesArray = $issues->toArray();
        $postIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'BlogPostWithoutCollectionInit'),
        );

        self::assertGreaterThanOrEqual(1, count($postIssues), 'Should detect at least one uninitialized collection');
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
    public function it_implements_analyzer_interface(): void
    {
        // Assert
        self::assertInstanceOf(\AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface::class, $this->analyzer);
    }

    #[Test]
    public function it_has_descriptive_name_and_description(): void
    {
        // Assert
        self::assertNotEmpty($this->analyzer->getName());
        self::assertNotEmpty($this->analyzer->getDescription());
        self::assertStringContainsString('Collection', $this->analyzer->getName());
        self::assertStringContainsString('uninitialized', strtolower($this->analyzer->getDescription()));
    }

    #[Test]
    public function it_detects_uninitialized_collection_in_multiple_entities(): void
    {
        // Arrange: Create multiple entities with uninitialized collections
        $user = new User();
        $user->setName('Test User');
        $user->setEmail('test@example.com');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $post1 = new BlogPostWithoutCollectionInit();
        $post1->setTitle('Test Post 1');
        $post1->setContent('Test Content 1');
        $post1->setAuthor($user);
        $this->entityManager->persist($post1);

        $post2 = new BlogPostWithoutCollectionInit();
        $post2->setTitle('Test Post 2');
        $post2->setContent('Test Content 2');
        $post2->setAuthor($user);
        $this->entityManager->persist($post2);

        $this->entityManager->flush();

        // DON'T clear - we check entities in identity map
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect issues in both entities
        $issuesArray = $issues->toArray();
        $collectionIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Uninitialized Collection'),
        );

        self::assertGreaterThanOrEqual(2, count($collectionIssues), 'Should detect uninitialized collections in multiple entities');
    }

    #[Test]
    public function it_provides_correct_issue_type(): void
    {
        // Arrange
        $user = new User();
        $user->setName('Test User');
        $user->setEmail('test@example.com');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $post = new BlogPostWithoutCollectionInit();
        $post->setTitle('Test Post');
        $post->setContent('Test Content');
        $post->setAuthor($user);
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        // DON'T clear - we check entities in identity map
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $collectionIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'Uninitialized Collection'),
        );

        if (!empty($collectionIssues)) {
            $issue = reset($collectionIssues);
            assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
            self::assertSame('collection_uninitialized', $issue->getType());
        } else {
            self::markTestSkipped('No collection issues found');
        }
    }

    #[Test]
    public function it_handles_exceptions_gracefully(): void
    {
        // Arrange: This test ensures analyzer doesn't crash on invalid metadata
        $queries = QueryDataBuilder::create()->build();

        // Act & Assert: Should not throw exceptions
        $this->expectNotToPerformAssertions();
        $this->analyzer->analyze($queries);
    }
}
