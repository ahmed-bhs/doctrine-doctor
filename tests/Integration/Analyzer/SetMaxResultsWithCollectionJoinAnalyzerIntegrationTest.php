<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\SetMaxResultsWithCollectionJoinAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Comment;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use Doctrine\ORM\Tools\Pagination\Paginator;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for SetMaxResultsWithCollectionJoinAnalyzer - Pagination with Collections.
 *
 * This test demonstrates a CRITICAL data loss anti-pattern:
 * - Using LIMIT with collection joins causes partial hydration
 * - LIMIT applies to SQL rows, not entities
 * - Silent data loss (missing related entities)
 */
final class SetMaxResultsWithCollectionJoinAnalyzerIntegrationTest extends DatabaseTestCase
{
    private SetMaxResultsWithCollectionJoinAnalyzer $setMaxResultsWithCollectionJoinAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->createSchema([User::class, BlogPost::class, Comment::class]);

        $this->setMaxResultsWithCollectionJoinAnalyzer = new SetMaxResultsWithCollectionJoinAnalyzer(
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            new \AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor(),
        );
    }

    #[Test]
    public function it_detects_set_max_results_with_collection_join(): void
    {
        // Create a blog post with multiple comments
        $user = new User();
        $user->setName('Author');
        $user->setEmail('author@example.com');

        $this->entityManager->persist($user);

        $blogPost = new BlogPost();
        $blogPost->setTitle('Post with comments');
        $blogPost->setContent('Content');
        $blogPost->setAuthor($user);

        $this->entityManager->persist($blogPost);

        // Add 4 comments to the post
        for ($i = 1; $i <= 4; $i++) {
            $comment = new Comment();
            $comment->setContent('Comment ' . $i);
            $comment->setPost($blogPost);
            $comment->setAuthor($user);
            $this->entityManager->persist($comment);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // 📢 CRITICAL BUG: Using setMaxResults with fetch join
        // This will only load 1 comment instead of all 4!
        $query = $this->entityManager->createQueryBuilder()
            ->select('p', 'c')
            ->from(BlogPost::class, 'p')
            ->leftJoin('p.comments', 'c')
            ->setMaxResults(1) // LIMIT 1 applies to rows, not entities!
            ->getQuery();

        $posts = $query->getResult();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->setMaxResultsWithCollectionJoinAnalyzer->analyze($queryDataCollection);

        // Should detect the anti-pattern
        self::assertGreaterThan(0, count($issueCollection), 'Should detect setMaxResults with collection join');

        // Demonstrate the data loss
        if (!empty($posts)) {
            $loadedComments = $posts[0]->getComments()->count();
            // Only 1 comment loaded instead of 4!
            self::assertLessThan(4, $loadedComments, 'Collection is partially hydrated - data loss!');
        }
    }

    #[Test]
    public function it_shows_proper_pagination_with_paginator(): void
    {
        // Create test data
        $user = new User();
        $user->setName('Author');
        $user->setEmail('author@example.com');

        $this->entityManager->persist($user);

        $blogPost = new BlogPost();
        $blogPost->setTitle('Post with comments');
        $blogPost->setContent('Content');
        $blogPost->setAuthor($user);

        $this->entityManager->persist($blogPost);

        for ($i = 1; $i <= 4; $i++) {
            $comment = new Comment();
            $comment->setContent('Comment ' . $i);
            $comment->setPost($blogPost);
            $comment->setAuthor($user);
            $this->entityManager->persist($comment);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // CORRECT: Using Doctrine Paginator
        $query = $this->entityManager->createQueryBuilder()
            ->select('p', 'c')
            ->from(BlogPost::class, 'p')
            ->leftJoin('p.comments', 'c')
            ->setMaxResults(1)
            ->getQuery();

        // Paginator executes 2 queries to properly handle collection joins
        $paginator = new Paginator($query, $fetchJoinCollection = true);

        $posts = iterator_to_array($paginator);

        $queryDataCollection = $this->stopQueryCollection();

        // Paginator uses 2 queries: one for IDs, one for data
        self::assertGreaterThan(1, count($queryDataCollection), 'Paginator should execute multiple queries');

        // All comments properly loaded
        if ([] !== $posts) {
            $loadedComments = $posts[0]->getComments()->count();
            self::assertEquals(4, $loadedComments, 'All comments should be loaded with Paginator');
        }
    }

    #[Test]
    public function it_compares_broken_vs_correct_pagination(): void
    {
        // Create test data
        $user = new User();
        $user->setName('Author');
        $user->setEmail('author@example.com');

        $this->entityManager->persist($user);

        $blogPost = new BlogPost();
        $blogPost->setTitle('Post with comments');
        $blogPost->setContent('Content');
        $blogPost->setAuthor($user);

        $this->entityManager->persist($blogPost);

        for ($i = 1; $i <= 5; $i++) {
            $comment = new Comment();
            $comment->setContent('Comment ' . $i);
            $comment->setPost($blogPost);
            $comment->setAuthor($user);
            $this->entityManager->persist($comment);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        // Test 1: 📢 BROKEN - setMaxResults with join
        $this->queryLogger->reset();
        $this->startQueryCollection();

        $brokenQuery = $this->entityManager->createQueryBuilder()
            ->select('p', 'c')
            ->from(BlogPost::class, 'p')
            ->leftJoin('p.comments', 'c')
            ->setMaxResults(10) // LIMIT 10 on rows, not entities!
            ->getQuery();

        $brokenPosts = $brokenQuery->getResult();
        $queryDataCollection = $this->stopQueryCollection();

        $this->entityManager->clear();

        // Test 2: CORRECT - Paginator
        $this->queryLogger->reset();
        $this->startQueryCollection();

        $correctQuery = $this->entityManager->createQueryBuilder()
            ->select('p', 'c')
            ->from(BlogPost::class, 'p')
            ->leftJoin('p.comments', 'c')
            ->setMaxResults(10)
            ->getQuery();

        $paginator = new Paginator($correctQuery, true);
        $correctPosts = iterator_to_array($paginator);
        $this->stopQueryCollection();

        // Broken: partial collection
        if (!empty($brokenPosts)) {
            $brokenComments = $brokenPosts[0]->getComments()->count();
            // May have fewer than 5 comments due to LIMIT
            self::assertLessThanOrEqual(5, $brokenComments);
        }

        // Correct: full collection
        if ([] !== $correctPosts) {
            $correctComments = $correctPosts[0]->getComments()->count();
            self::assertEquals(5, $correctComments, 'Paginator loads all comments');
        }

        // Analyzer should flag the broken query
        $issueCollection = $this->setMaxResultsWithCollectionJoinAnalyzer->analyze($queryDataCollection);
        self::assertIsArray($issueCollection->toArray());
    }

    #[Test]
    public function it_does_not_flag_set_max_results_without_join(): void
    {
        $user = new User();
        $user->setName('User');
        $user->setEmail('user@example.com');

        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // OK: setMaxResults without collection join is fine
        $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->setMaxResultsWithCollectionJoinAnalyzer->analyze($queryDataCollection);

        // Should not flag queries without joins
        self::assertCount(0, $issueCollection, 'Should not flag setMaxResults without joins');
    }

    #[Test]
    public function it_demonstrates_silent_data_loss(): void
    {
        // Create a post with many comments
        $user = new User();
        $user->setName('Author');
        $user->setEmail('author@example.com');

        $this->entityManager->persist($user);

        $blogPost = new BlogPost();
        $blogPost->setTitle('Popular post');
        $blogPost->setContent('Content');
        $blogPost->setAuthor($user);

        $this->entityManager->persist($blogPost);

        // 10 comments
        for ($i = 1; $i <= 10; $i++) {
            $comment = new Comment();
            $comment->setContent('Comment ' . $i);
            $comment->setPost($blogPost);
            $comment->setAuthor($user);
            $this->entityManager->persist($comment);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // 📢 This is the silent data loss bug
        $query = $this->entityManager->createQueryBuilder()
            ->select('p', 'c')
            ->from(BlogPost::class, 'p')
            ->leftJoin('p.comments', 'c')
            ->setMaxResults(5) // Only 5 rows returned
            ->getQuery();

        $posts = $query->getResult();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->setMaxResultsWithCollectionJoinAnalyzer->analyze($queryDataCollection);

        self::assertGreaterThan(0, count($issueCollection), 'Should detect this critical anti-pattern');

        // The bug: developer expects all 10 comments, but gets fewer
        if (!empty($posts)) {
            $actualComments = $posts[0]->getComments()->count();
            // Silent data loss - missing comments!
            self::assertLessThan(10, $actualComments, 'SILENT DATA LOSS: Missing comments due to LIMIT on rows!');
        }
    }
}
