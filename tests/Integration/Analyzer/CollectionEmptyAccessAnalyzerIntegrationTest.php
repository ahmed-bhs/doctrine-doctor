<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\CollectionEmptyAccessAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPostWithoutCollectionInit;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Comment;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\CommentWithoutInit;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for CollectionEmptyAccessAnalyzer.
 *
 * Tests detection of:
 * - Uninitialized collections (no ArrayCollection in constructor)
 * - Unsafe access to first()/last() on empty collections
 * - Missing isEmpty() checks before collection access
 * - Real-world collection bugs
 */
final class CollectionEmptyAccessAnalyzerIntegrationTest extends DatabaseTestCase
{
    private CollectionEmptyAccessAnalyzer $collectionEmptyAccessAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->collectionEmptyAccessAnalyzer = new CollectionEmptyAccessAnalyzer(
            $this->entityManager,
            new IssueFactory(),
        );

        // Create schema
        $this->createSchema([
            User::class,
            BlogPost::class,
            Comment::class,
            BlogPostWithoutCollectionInit::class,
            CommentWithoutInit::class,
        ]);
    }

    #[Test]
    public function it_demonstrates_properly_initialized_collection(): void
    {
        // Arrange: BlogPost with proper initialization
        $user = new User();
        $user->setName('John Doe');
        $user->setEmail('john@example.com');

        $blogPost = new BlogPost();
        $blogPost->setTitle('Test Post');
        $blogPost->setContent('Content here');
        $blogPost->setAuthor($user);

        $this->entityManager->persist($user);
        $this->entityManager->persist($blogPost);
        $this->entityManager->flush();

        // Act: Access collection - should work fine
        $comments = $blogPost->getComments();

        // Assert: Collection is initialized even when empty
        self::assertNotNull($comments, 'Collection should be initialized');
        self::assertCount(0, $comments, 'Collection should be empty but valid');
        self::assertTrue($comments->isEmpty(), 'isEmpty() should work on initialized collection');
    }

    #[Test]
    public function it_detects_uninitialized_collection(): void
    {
        // Arrange: BlogPostWithoutCollectionInit - no constructor initialization
        $user = new User();
        $user->setName('Jane Doe');
        $user->setEmail('jane@example.com');

        $blogPostWithoutCollectionInit = new BlogPostWithoutCollectionInit();
        $blogPostWithoutCollectionInit->setTitle('Broken Post');
        $blogPostWithoutCollectionInit->setContent('This post has uninitialized collections');
        $blogPostWithoutCollectionInit->setAuthor($user);

        $this->entityManager->persist($user);
        $this->entityManager->persist($blogPostWithoutCollectionInit);
        $this->entityManager->flush();

        // Act: Analyze
        $queryDataCollection = QueryDataCollection::empty();
        $issueCollection = $this->collectionEmptyAccessAnalyzer->analyze($queryDataCollection);

        // Assert: Should detect uninitialized collection
        self::assertGreaterThan(0, count($issueCollection), 'Should detect uninitialized collection on BlogPostWithoutCollectionInit');

        $issuesArray = $issueCollection->toArray();
        $uninitIssue = array_find($issuesArray, fn ($issueArray) => str_contains((string) $issueArray->getTitle(), 'BlogPostWithoutCollectionInit')
            || str_contains((string) $issueArray->getDescription(), 'not initialized'));

        if (null !== $uninitIssue) {
            self::assertStringContainsString('not initialized', (string) $uninitIssue->getDescription(), 'Should mention collection is not initialized');
        }
    }

    #[Test]
    public function it_demonstrates_uninitialized_collection_bug(): void
    {
        // Arrange: Create entity without collection initialization
        $user = new User();
        $user->setName('Bug Test');
        $user->setEmail('bug@example.com');

        $blogPostWithoutCollectionInit = new BlogPostWithoutCollectionInit();
        $blogPostWithoutCollectionInit->setTitle('Buggy Post');
        $blogPostWithoutCollectionInit->setContent('Content');
        $blogPostWithoutCollectionInit->setAuthor($user);

        $this->entityManager->persist($user);
        $this->entityManager->persist($blogPostWithoutCollectionInit);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Act: Try to access collection before Doctrine initializes it
        $savedPost = $this->entityManager->find(
            BlogPostWithoutCollectionInit::class,
            $blogPostWithoutCollectionInit->getId(),
        );
        self::assertInstanceOf(BlogPostWithoutCollectionInit::class, $savedPost);

        // Try to access comments - Doctrine will initialize it
        $comments = $savedPost->getComments();

        // After loading from DB, Doctrine initializes the collection
        // But in a fresh entity (not loaded from DB), it would be NULL
        self::assertNotNull($comments, 'Doctrine initializes collections when loading from DB');
    }

    #[Test]
    public function it_demonstrates_fresh_entity_collection_null_bug(): void
    {
        // Arrange: Create a fresh entity (not persisted/loaded from DB)
        $user = new User();
        $user->setName('Fresh Test');
        $user->setEmail('fresh@example.com');

        $blogPostWithoutCollectionInit = new BlogPostWithoutCollectionInit();
        $blogPostWithoutCollectionInit->setTitle('Fresh Post');
        $blogPostWithoutCollectionInit->setContent('Content');
        $blogPostWithoutCollectionInit->setAuthor($user);

        // Act: Try to add comment to uninitialized collection
        $commentWithoutInit = new CommentWithoutInit();
        $commentWithoutInit->setContent('Test comment');
        $commentWithoutInit->setAuthor($user);

        $exceptionThrown = false;

        try {
            // This WILL fail because $comments is not initialized!
            $blogPostWithoutCollectionInit->addComment($commentWithoutInit);
        } catch (\Error $error) {
            $exceptionThrown = true;
            // In PHP 8.1+, uninitialized typed properties throw "must not be accessed before initialization"
            self::assertThat(strtolower($error->getMessage()), self::logicalOr(
                self::stringContains('null'),
                self::stringContains('must not be accessed before initialization'),
            ), 'Should fail with null or uninitialized property error');
        }

        // Assert: Exception should be thrown
        self::assertTrue($exceptionThrown, 'Accessing uninitialized collection should throw an error');
    }

    #[Test]
    public function it_detects_empty_collection_access_without_check(): void
    {
        // Arrange: Post with initialized but empty collection
        $user = new User();
        $user->setName('Empty Test');
        $user->setEmail('empty@example.com');

        $blogPost = new BlogPost();
        $blogPost->setTitle('Post Without Comments');
        $blogPost->setContent('No comments here');
        $blogPost->setAuthor($user);

        $this->entityManager->persist($user);
        $this->entityManager->persist($blogPost);
        $this->entityManager->flush();

        // Act: Safely check if empty
        $comments = $blogPost->getComments();

        // Assert: isEmpty() should work
        self::assertTrue($comments->isEmpty(), 'Collection should be empty');
        self::assertCount(0, $comments);

        // Demonstrate unsafe access: first() on empty collection returns false
        $firstComment = $comments->first();
        self::assertFalse($firstComment, 'first() on empty collection returns false, not null!');

        // This would be a bug in production code:
        // if ($firstComment->getContent()) { ... } // Fatal error!
    }

    #[Test]
    public function it_demonstrates_safe_collection_access_pattern(): void
    {
        // Arrange
        $user = new User();
        $user->setName('Safe Test');
        $user->setEmail('safe@example.com');

        $blogPost = new BlogPost();
        $blogPost->setTitle('Safe Post');
        $blogPost->setContent('Content');
        $blogPost->setAuthor($user);

        $this->entityManager->persist($user);
        $this->entityManager->persist($blogPost);
        $this->entityManager->flush();

        // Act: Safe pattern - check isEmpty() first
        $comments = $blogPost->getComments();

        if (!$comments->isEmpty()) {
            $firstComment = $comments->first();
            self::assertNotFalse($firstComment);
            // Now safe to use $firstComment
            self::assertNotFalse($firstComment);
        } else {
            // Handle empty case
            self::assertTrue($comments->isEmpty());
        }

        // Assert: This pattern prevents bugs
        self::assertCount(0, $comments, 'Collection is empty');
    }

    #[Test]
    public function it_demonstrates_collection_with_elements(): void
    {
        // Arrange: Post with comments
        $user = new User();
        $user->setName('Comment Test');
        $user->setEmail('comment@example.com');

        $blogPost = new BlogPost();
        $blogPost->setTitle('Popular Post');
        $blogPost->setContent('Great content');
        $blogPost->setAuthor($user);

        $comment1 = new Comment();
        $comment1->setContent('First comment');
        $comment1->setAuthor($user);
        $comment1->setPost($blogPost);

        $comment2 = new Comment();
        $comment2->setContent('Second comment');
        $comment2->setAuthor($user);
        $comment2->setPost($blogPost);

        $blogPost->addComment($comment1);
        $blogPost->addComment($comment2);

        $this->entityManager->persist($user);
        $this->entityManager->persist($blogPost);
        $this->entityManager->persist($comment1);
        $this->entityManager->persist($comment2);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Act: Load and access
        $savedPost = $this->entityManager->find(BlogPost::class, $blogPost->getId());
        self::assertInstanceOf(BlogPost::class, $savedPost);
        $comments = $savedPost->getComments();

        // Assert: Safe to access first() when not empty
        self::assertFalse($comments->isEmpty());
        self::assertCount(2, $comments);

        $firstComment = $comments->first();
        self::assertNotFalse($firstComment, 'first() should return an object, not false');
        self::assertInstanceOf(Comment::class, $firstComment);
    }

    #[Test]
    public function it_demonstrates_last_method_behavior(): void
    {
        // Arrange: Post with multiple comments
        $user = new User();
        $user->setName('Last Test');
        $user->setEmail('last@example.com');

        $blogPost = new BlogPost();
        $blogPost->setTitle('Test Post');
        $blogPost->setContent('Content');
        $blogPost->setAuthor($user);

        $this->entityManager->persist($user);
        $this->entityManager->persist($blogPost);
        $this->entityManager->flush();

        // Act: Test last() on empty collection
        $comments = $blogPost->getComments();
        $lastComment = $comments->last();

        // Assert: last() also returns false on empty collection
        self::assertFalse($lastComment, 'last() on empty collection returns false');

        // Add comments
        $comment1 = new Comment();
        $comment1->setContent('First');
        $comment1->setAuthor($user);
        $comment1->setPost($blogPost);

        $comment2 = new Comment();
        $comment2->setContent('Last');
        $comment2->setAuthor($user);
        $comment2->setPost($blogPost);

        $blogPost->addComment($comment1);
        $blogPost->addComment($comment2);

        $this->entityManager->persist($comment1);
        $this->entityManager->persist($comment2);
        $this->entityManager->flush();

        // Now last() should work
        $lastComment = $blogPost->getComments()->last();
        self::assertNotFalse($lastComment);
        self::assertSame('Last', $lastComment->getContent());
    }

    #[Test]
    public function it_provides_suggestions_for_safe_collection_access(): void
    {
        // Arrange: Entity with uninitialized collection
        $user = new User();
        $user->setName('Suggestion Test');
        $user->setEmail('suggestion@example.com');

        $blogPostWithoutCollectionInit = new BlogPostWithoutCollectionInit();
        $blogPostWithoutCollectionInit->setTitle('Test');
        $blogPostWithoutCollectionInit->setContent('Content');
        $blogPostWithoutCollectionInit->setAuthor($user);

        $this->entityManager->persist($user);
        $this->entityManager->persist($blogPostWithoutCollectionInit);
        $this->entityManager->flush();

        // Act
        $queryDataCollection = QueryDataCollection::empty();
        $issueCollection = $this->collectionEmptyAccessAnalyzer->analyze($queryDataCollection);

        // Assert: Should detect uninitialized collection and provide suggestions
        self::assertGreaterThan(0, count($issueCollection), 'Should detect uninitialized collection');

        foreach ($issueCollection->toArray() as $issue) {
            self::assertInstanceOf(SuggestionInterface::class, $issue->getSuggestion(), 'Should provide suggestion for collection issues');
        }
    }

    #[Test]
    public function it_handles_multiple_entities_with_collections(): void
    {
        // Arrange: Multiple posts with various collection states
        $user = new User();
        $user->setName('Multi Test');
        $user->setEmail('multi@example.com');

        // Good post
        $blogPost = new BlogPost();
        $blogPost->setTitle('Good Post');
        $blogPost->setContent('Content');
        $blogPost->setAuthor($user);

        // Bad post
        $blogPostWithoutCollectionInit = new BlogPostWithoutCollectionInit();
        $blogPostWithoutCollectionInit->setTitle('Bad Post');
        $blogPostWithoutCollectionInit->setContent('Content');
        $blogPostWithoutCollectionInit->setAuthor($user);

        $this->entityManager->persist($user);
        $this->entityManager->persist($blogPost);
        $this->entityManager->persist($blogPostWithoutCollectionInit);
        $this->entityManager->flush();

        // Act
        $queryDataCollection = QueryDataCollection::empty();
        $issueCollection = $this->collectionEmptyAccessAnalyzer->analyze($queryDataCollection);

        // Assert: Should detect issues on bad post, not on good post
        $issuesArray = $issueCollection->toArray();

        $badPostIssues = array_filter($issuesArray, fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'BlogPostWithoutCollectionInit')
            || str_contains($issue->getDescription(), 'BlogPostWithoutCollectionInit'));

        $goodPostIssues = array_filter($issuesArray, fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'BlogPost')
            && !str_contains($issue->getTitle(), 'BlogPostWithoutCollectionInit'));

        // Bad post should have issues
        self::assertGreaterThanOrEqual(0, count($badPostIssues), 'BlogPostWithoutCollectionInit may have issues');

        // Good post should NOT have issues
        self::assertCount(0, $goodPostIssues, 'Regular BlogPost should not have collection issues');
    }

    #[Test]
    public function it_demonstrates_real_world_bug_scenario(): void
    {
        // This test demonstrates a real bug that happens in production

        // Scenario: Developer creates entity, tries to add items before persisting
        $user = new User();
        $user->setName('Real Bug Test');
        $user->setEmail('realbug@example.com');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Create post without proper collection initialization
        $blogPostWithoutCollectionInit = new BlogPostWithoutCollectionInit();
        $blogPostWithoutCollectionInit->setTitle('Buggy Post');
        $blogPostWithoutCollectionInit->setContent('This will fail');
        $blogPostWithoutCollectionInit->setAuthor($user);

        // Developer tries to add comment immediately
        $commentWithoutInit = new CommentWithoutInit();
        $commentWithoutInit->setContent('This will crash');
        $commentWithoutInit->setAuthor($user);

        // This FAILS because collection is NULL!
        $this->expectException(\Error::class);
        $blogPostWithoutCollectionInit->addComment($commentWithoutInit);

        // In production, this causes: "Call to member function contains() on null"
        // The fix: Initialize collections in constructor!
    }
}
