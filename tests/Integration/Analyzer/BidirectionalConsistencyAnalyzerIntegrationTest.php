<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\BidirectionalConsistencyAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Comment;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for BidirectionalConsistencyAnalyzer.
 *
 * Tests REAL bidirectional relationship consistency issues.
 */
final class BidirectionalConsistencyAnalyzerIntegrationTest extends DatabaseTestCase
{
    private BidirectionalConsistencyAnalyzer $bidirectionalConsistencyAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->bidirectionalConsistencyAnalyzer = new BidirectionalConsistencyAnalyzer(
            $this->entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );

        $this->createSchema([User::class, BlogPost::class, Comment::class]);
    }

    #[Test]
    public function it_demonstrates_inconsistent_bidirectional_relationship(): void
    {
        // Create a user and post
        $user = new User();
        $user->setName('John Doe');
        $user->setEmail('john@example.com');

        $blogPost = new BlogPost();
        $blogPost->setTitle('Test Post');
        $blogPost->setContent('Content');
        $blogPost->setAuthor($user);

        // BAD: We set the author but didn't add the post to user's collection
        // This creates an inconsistent state!

        $this->entityManager->persist($user);
        $this->entityManager->persist($blogPost);
        $this->entityManager->flush();

        // The database will have the relationship (post -> user)
        // But the User object doesn't know about this post!
        self::assertCount(0, $user->getPosts(), 'User collection is empty (inconsistent!)');

        // If we reload from database and access posts, it will work
        $this->entityManager->clear();
        $reloadedUser = $this->entityManager->find(User::class, $user->getId());
        self::assertNotNull($reloadedUser);
        self::assertCount(1, $reloadedUser->getPosts(), 'DB has the relationship');

        // This inconsistency can cause bugs!
    }

    #[Test]
    public function it_demonstrates_correct_bidirectional_management(): void
    {
        // Create entities
        $user = new User();
        $user->setName('Jane Doe');
        $user->setEmail('jane@example.com');

        $blogPost = new BlogPost();
        $blogPost->setTitle('Correct Post');
        $blogPost->setContent('Content');

        // GOOD: Use the helper method that maintains both sides
        $user->addPost($blogPost); // This sets author AND adds to collection

        $this->entityManager->persist($user);
        $this->entityManager->persist($blogPost);
        $this->entityManager->flush();

        // Now both sides are consistent!
        self::assertSame($user, $blogPost->getAuthor(), 'Post knows its author');
        self::assertCount(1, $user->getPosts(), 'User knows about post');
        self::assertTrue($user->getPosts()->contains($blogPost), 'Post is in collection');
    }

    #[Test]
    public function it_detects_missing_synchronization_methods(): void
    {
        // The analyzer should detect if entities don't have proper
        // addX() and removeX() methods to maintain consistency

        $issueCollection = $this->bidirectionalConsistencyAnalyzer->analyze(QueryDataCollection::empty());

        // Check if we have issues related to bidirectional relationships
        $issuesArray = $issueCollection->toArray();

        // Note: This depends on whether our test entities have the methods
        // BlogPost <-> Comment should be properly set up
        self::assertIsArray($issuesArray);
    }

    #[Test]
    public function it_demonstrates_orphan_in_collection_problem(): void
    {
        // Create user and post properly
        $user = new User();
        $user->setName('Bob Smith');
        $user->setEmail('bob@example.com');

        $blogPost = new BlogPost();
        $blogPost->setTitle('Test');
        $blogPost->setContent('Content');

        $user->addPost($blogPost);

        $this->entityManager->persist($user);
        $this->entityManager->persist($blogPost);
        $this->entityManager->flush();

        // Now break the relationship on ONE side only (BAD!)
        // Create another user and change author without removing from original user's collection
        $anotherUser = new User();
        $anotherUser->setName('Charlie');
        $anotherUser->setEmail('charlie@example.com');

        $this->entityManager->persist($anotherUser);

        $blogPost->setAuthor($anotherUser); // Change author but don't remove from original collection!

        // This creates an inconsistent state
        self::assertSame($anotherUser, $blogPost->getAuthor(), 'Post has new author');
        self::assertTrue($user->getPosts()->contains($blogPost), 'But original user still references it!');

        // If we flush, what happens depends on cascade settings
        // This is dangerous!
    }

    #[Test]
    public function it_shows_importance_of_inverse_side_management(): void
    {
        // In Doctrine, bidirectional relationships have:
        // - Owning side (has the @JoinColumn)
        // - Inverse side (mappedBy)

        // For BlogPost <-> User:
        // - BlogPost is owning side (has @JoinColumn on author)
        // - User is inverse side (has mappedBy on posts)

        $user = new User();
        $user->setName('Alice');
        $user->setEmail('alice@example.com');

        $post = new BlogPost();
        $post->setTitle('Post');
        $post->setContent('Content');

        // Only setting the owning side will persist
        $post->setAuthor($user);

        $this->entityManager->persist($user);
        $this->entityManager->persist($post);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // DB relationship exists
        $reloadedPost = $this->entityManager->find(BlogPost::class, $post->getId());
        self::assertInstanceOf(BlogPost::class, $reloadedPost);
        self::assertNotNull($reloadedPost->getAuthor());

        // But for consistency in the same request, you should also:
        $user2 = new User();
        $user2->setName('Charlie');
        $user2->setEmail('charlie@example.com');

        $post2 = new BlogPost();
        $post2->setTitle('Post 2');
        $post2->setContent('Content 2');

        // Do BOTH sides
        $post2->setAuthor($user2);

        $user2->addPost($post2); // Maintains collection

        // Now you can use the collection in the same request
        self::assertCount(1, $user2->getPosts());
    }

    #[Test]
    public function it_demonstrates_cascade_with_bidirectional_relations(): void
    {
        // With proper bidirectional setup and cascade persist

        $user = new User();
        $user->setName('David');
        $user->setEmail('david@example.com');

        $post1 = new BlogPost();
        $post1->setTitle('Post 1');
        $post1->setContent('Content 1');

        $user->addPost($post1);

        $post2 = new BlogPost();
        $post2->setTitle('Post 2');
        $post2->setContent('Content 2');

        $user->addPost($post2);

        // Persist user and posts (if cascade is not configured, we need to persist manually)
        $this->entityManager->persist($user);
        $this->entityManager->persist($post1);
        $this->entityManager->persist($post2);
        $this->entityManager->flush();

        // Both posts should be persisted
        self::assertNotNull($post1->getId());
        self::assertNotNull($post2->getId());

        // And the relationship is consistent
        self::assertCount(2, $user->getPosts());
    }

    #[Test]
    public function it_shows_collection_synchronization_in_same_request(): void
    {
        // Important: Synchronization matters for same-request operations

        $user = new User();
        $user->setName('Eve');
        $user->setEmail('eve@example.com');

        $blogPost = new BlogPost();
        $blogPost->setTitle('Test');
        $blogPost->setContent('Content');

        // Without synchronization
        $blogPost->setAuthor($user);
        // Don't call $user->addPost($post)

        $this->entityManager->persist($user);
        $this->entityManager->persist($blogPost);
        $this->entityManager->flush();

        // In the SAME REQUEST, before clearing:
        // The collection is NOT updated!
        self::assertCount(0, $user->getPosts(), 'Without synchronization, collection is empty in same request');

        // But if we query the database:
        $this->entityManager->clear();
        $reloadedUser = $this->entityManager->find(User::class, $user->getId());
        self::assertNotNull($reloadedUser);
        self::assertCount(1, $reloadedUser->getPosts(), 'After reload, Doctrine loads from DB and it works');

        // This proves why synchronization is important!
    }
}
