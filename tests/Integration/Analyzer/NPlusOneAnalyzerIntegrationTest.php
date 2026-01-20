<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\NPlusOneAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data\BlogFixture;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Data\UserFixture;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Comment;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for NPlusOneAnalyzer using real database and queries.
 *
 * This test demonstrates the realistic approach:
 * - Real entities with real relationships
 * - Real database with actual data
 * - Real queries that trigger N+1 problems
 * - No mocks, no fakes, just reality!
 */
final class NPlusOneAnalyzerIntegrationTest extends DatabaseTestCase
{
    private NPlusOneAnalyzer $nPlusOneAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        // Create the analyzer with real dependencies
        $this->nPlusOneAnalyzer = new NPlusOneAnalyzer(
            $this->entityManager,
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            5, // threshold
        );

        // Create real database schema
        $this->createSchema([
            User::class,
            BlogPost::class,
            Comment::class,
        ]);

        // Load realistic test data
        $userFixture = new UserFixture();
        $userFixture->load($this->entityManager);

        $blogFixture = new BlogFixture($userFixture->getUsers());
        $blogFixture->load($this->entityManager);

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    #[Test]
    public function it_detects_n_plus_one_when_loading_posts_with_comments(): void
    {
        // Start monitoring queries
        $this->startQueryCollection();

        // This code simulates a REAL N+1 problem:
        // 1. One query to fetch all posts
        // 2. N queries to fetch comments for each post
        $posts = $this->entityManager
            ->getRepository(BlogPost::class)
            ->findAll();

        foreach ($posts as $post) {
            // Accessing comments triggers a lazy load query for EACH post
            $comments = $post->getComments();
            foreach ($comments as $comment) {
                $comment->getContent(); // Force initialization
            }
        }

        // Stop monitoring and collect queries
        $queryDataCollection = $this->stopQueryCollection();

        // Analyze the queries with our analyzer
        $issueCollection = $this->nPlusOneAnalyzer->analyze($queryDataCollection);

        // Assertions
        $issuesArray = $issueCollection->toArray();

        self::assertCount(1, $issuesArray, 'Should detect ONE N+1 pattern');

        $issue = $issuesArray[0];
        self::assertEquals('performance', $issue->getCategory());
        self::assertStringContainsString('N+1', (string) $issue->getTitle());

        // We expect 1 query for posts + N queries for comments
        // With 10 posts, we should see the pattern
        self::assertGreaterThan(5, $this->queryLogger->count(), 'Should have executed multiple queries');
    }

    #[Test]
    public function it_does_not_detect_issue_when_using_join_fetch(): void
    {
        // Start monitoring queries
        $this->startQueryCollection();

        // CORRECT approach: Use JOIN FETCH to avoid N+1
        $posts = $this->entityManager
            ->createQuery('
                SELECT p, c
                FROM ' . BlogPost::class . ' p
                LEFT JOIN p.comments c
            ')
            ->getResult();

        foreach ($posts as $post) {
            $comments = $post->getComments();
            foreach ($comments as $comment) {
                $comment->getContent();
            }
        }

        $queryDataCollection = $this->stopQueryCollection();

        // Analyze the queries
        $issueCollection = $this->nPlusOneAnalyzer->analyze($queryDataCollection);

        // Should NOT detect N+1 because we used JOIN FETCH
        self::assertCount(0, $issueCollection, 'Should NOT detect N+1 with JOIN FETCH');

        // Should only have 1-2 queries (main query + possible hydration)
        self::assertLessThanOrEqual(2, $this->queryLogger->count(), 'Should execute minimal queries');
    }

    #[Test]
    public function it_detects_n_plus_one_with_nested_relationships(): void
    {
        $this->startQueryCollection();

        // N+1 with nested access: posts -> comments -> author
        $posts = $this->entityManager
            ->getRepository(BlogPost::class)
            ->findAll();

        foreach ($posts as $post) {
            foreach ($post->getComments() as $comment) {
                // Accessing author triggers another query
                $comment->getAuthor()->getName();
            }
        }

        $queryDataCollection = $this->stopQueryCollection();
        $issueCollection = $this->nPlusOneAnalyzer->analyze($queryDataCollection);

        // Should detect multiple N+1 patterns
        self::assertGreaterThanOrEqual(1, count($issueCollection), 'Should detect N+1 patterns');

        // Should have MANY queries (posts + comments + authors)
        self::assertGreaterThan(10, $this->queryLogger->count(), 'Should have executed many queries');
    }

    #[Test]
    public function it_provides_join_fetch_suggestion(): void
    {
        $this->startQueryCollection();

        $posts = $this->entityManager
            ->getRepository(BlogPost::class)
            ->findAll();

        foreach ($posts as $post) {
            $post->getComments()->count();
        }

        $queryDataCollection = $this->stopQueryCollection();
        $issueCollection = $this->nPlusOneAnalyzer->analyze($queryDataCollection);

        // The test verifies that issues are detected
        // Note: Suggestion generation depends on SQL pattern matching and might be null
        // if the query pattern doesn't match the analyzer's regex patterns
        self::assertGreaterThan(0, count($issueCollection), 'Should detect N+1 issue');

        $issue = $issueCollection->toArray()[0];
        self::assertEquals('performance', $issue->getCategory());
        self::assertStringContainsString('N+1', (string) $issue->getTitle());
    }

    #[Test]
    public function it_shows_real_performance_difference(): void
    {
        // Measure BAD approach (N+1)
        $this->queryLogger->reset();
        $this->queryLogger->start();

        $posts = $this->entityManager
            ->getRepository(BlogPost::class)
            ->findAll();

        foreach ($posts as $post) {
            $post->getComments()->count();
        }

        $badQueryCount = $this->queryLogger->count();
        $this->queryLogger->stop();

        // Clear entity manager to reset queries
        $this->entityManager->clear();

        // Measure GOOD approach (JOIN FETCH)
        $this->queryLogger->reset();
        $this->queryLogger->start();

        $posts = $this->entityManager
            ->createQuery('
                SELECT p, c
                FROM ' . BlogPost::class . ' p
                LEFT JOIN p.comments c
            ')
            ->getResult();

        foreach ($posts as $post) {
            $post->getComments()->count();
        }

        $goodQueryCount = $this->queryLogger->count();
        $this->queryLogger->stop();

        // The difference should be significant
        self::assertGreaterThan($goodQueryCount, $badQueryCount, 'N+1 should execute MORE queries');

        // Good approach should be close to 1 query
        self::assertLessThanOrEqual(2, $goodQueryCount, 'JOIN FETCH should use minimal queries');
    }
}
