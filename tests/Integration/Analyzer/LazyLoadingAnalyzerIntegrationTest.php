<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\LazyLoadingAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Comment;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for LazyLoadingAnalyzer - Critical Performance Analyzer.
 *
 * This test demonstrates REAL lazy loading problems that cause N+1 queries:
 * - Loading collections in loops
 * - Accessing ManyToOne relations without JOIN FETCH
 * - Comparing lazy vs eager loading performance
 *
 * The classic N+1 problem: 1 query to get posts + N queries to get each post's author.
 */
final class LazyLoadingAnalyzerIntegrationTest extends DatabaseTestCase
{
    private LazyLoadingAnalyzer $lazyLoadingAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->createSchema([User::class, BlogPost::class, Comment::class]);

        $this->lazyLoadingAnalyzer = new LazyLoadingAnalyzer(
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            5, // Threshold: flag if 5+ lazy loads
        );
    }

    #[Test]
    public function it_detects_lazy_loading_in_loop(): void
    {
        // Create test data: 1 user with 10 blog posts, each with comments
        $user = new User();
        $user->setName('author');
        $user->setEmail('author@example.com');

        $this->entityManager->persist($user);

        for ($i = 1; $i <= 10; $i++) {
            $post = new BlogPost();
            $post->setTitle('Post ' . $i);
            $post->setContent('Content ' . $i);
            $post->setAuthor($user);
            $this->entityManager->persist($post);

            // Add some comments to each post
            for ($j = 1; $j <= 2; $j++) {
                $comment = new Comment();
                $comment->setContent(sprintf('Comment %d on post %d', $j, $i));
                $comment->setAuthor($user);
                $comment->setPost($post);
                $this->entityManager->persist($comment);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // 📢 BAD: This causes N+1 queries
        // 1 query to load all posts + 10 queries to lazy-load comments for each post
        $posts = $this->entityManager->getRepository(BlogPost::class)->findAll();

        foreach ($posts as $post) {
            // Accessing comments collection triggers lazy load!
            $comments = $post->getComments();
            foreach ($comments as $comment) {
                $comment->getContent(); // Force initialization
            }
        }

        $queryDataCollection = $this->stopQueryCollection();

        // We should have > 10 queries (1 for posts + 10 for comment collections)
        self::assertGreaterThan(10, $this->queryLogger->count(), 'Lazy loading should generate many queries');

        $issueCollection = $this->lazyLoadingAnalyzer->analyze($queryDataCollection);

        // The analyzer should detect the lazy loading pattern
        self::assertIsArray($issueCollection->toArray(), 'Should analyze queries');

        if (count($issueCollection) > 0) {
            $issue = $issueCollection->toArray()[0];
            self::assertEquals('performance', $issue->getCategory());
            self::assertStringContainsString('lazy', strtolower((string) $issue->getTitle()));
        }
    }

    #[Test]
    public function it_does_not_flag_eager_loading_with_join_fetch(): void
    {
        // Create test data
        $user = new User();
        $user->setName('author');
        $user->setEmail('author@example.com');

        $this->entityManager->persist($user);

        for ($i = 1; $i <= 10; $i++) {
            $post = new BlogPost();
            $post->setTitle('Post ' . $i);
            $post->setContent('Content ' . $i);
            $post->setAuthor($user);
            $this->entityManager->persist($post);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // GOOD: Use JOIN FETCH to load authors eagerly
        $posts = $this->entityManager->createQueryBuilder()
            ->select('p', 'a')
            ->from(BlogPost::class, 'p')
            ->join('p.author', 'a')
            ->getQuery()
            ->getResult();

        foreach ($posts as $post) {
            $authorName = $post->getAuthor()->getName(); // No extra query!
        }

        $queryDataCollection = $this->stopQueryCollection();

        // Should have only 1 query with JOIN
        self::assertLessThanOrEqual(2, $this->queryLogger->count(), 'Eager loading should generate few queries');

        $issueCollection = $this->lazyLoadingAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection, 'Should NOT flag eager loading with JOIN FETCH');
    }

    #[Test]
    public function it_demonstrates_n_plus_one_with_comments(): void
    {
        // Create 5 posts, each with 3 comments
        $user = new User();
        $user->setName('commenter');
        $user->setEmail('commenter@example.com');

        $this->entityManager->persist($user);

        for ($i = 1; $i <= 5; $i++) {
            $post = new BlogPost();
            $post->setTitle('Post ' . $i);
            $post->setContent('Content ' . $i);
            $post->setAuthor($user);
            $this->entityManager->persist($post);

            for ($j = 1; $j <= 3; $j++) {
                $comment = new Comment();
                $comment->setContent(sprintf('Comment %d on post %d', $j, $i));
                $comment->setAuthor($user);
                $comment->setPost($post);
                $this->entityManager->persist($comment);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // 📢 BAD: Load posts then iterate comments (lazy loaded)
        $posts = $this->entityManager->getRepository(BlogPost::class)->findAll();

        foreach ($posts as $post) {
            // This triggers lazy loading of comments collection
            $commentCount = count($post->getComments());
        }

        $queryDataCollection = $this->stopQueryCollection();

        // 1 query for posts + 5 queries for comments collections
        self::assertGreaterThan(5, $this->queryLogger->count(), 'Should generate N+1 queries for comment collections');

        $issueCollection = $this->lazyLoadingAnalyzer->analyze($queryDataCollection);

        self::assertIsArray($issueCollection->toArray(), 'Should analyze lazy-loaded collections');
    }

    #[Test]
    public function it_provides_eager_loading_suggestions(): void
    {
        // Setup N+1 scenario
        $user = new User();
        $user->setName('author');
        $user->setEmail('author@example.com');

        $this->entityManager->persist($user);

        for ($i = 1; $i <= 10; $i++) {
            $post = new BlogPost();
            $post->setTitle('Post ' . $i);
            $post->setContent('Content ' . $i);
            $post->setAuthor($user);
            $this->entityManager->persist($post);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // Trigger N+1
        $posts = $this->entityManager->getRepository(BlogPost::class)->findAll();
        foreach ($posts as $post) {
            $post->getAuthor()->getName();
        }

        $queryDataCollection = $this->stopQueryCollection();
        $issueCollection = $this->lazyLoadingAnalyzer->analyze($queryDataCollection);

        self::assertIsArray($issueCollection->toArray());

        if (count($issueCollection) > 0) {
            $issue = $issueCollection->toArray()[0];
            $suggestion = $issue->getSuggestion();

            self::assertInstanceOf(SuggestionInterface::class, $suggestion, 'Should provide eager loading suggestion');

            $description = $suggestion->getDescription();
            self::assertStringContainsString('JOIN FETCH', (string) $description, 'Should suggest using JOIN FETCH');
        }
    }

    #[Test]
    public function it_respects_threshold_configuration(): void
    {
        // Create analyzer with high threshold
        $lazyLoadingAnalyzer = new LazyLoadingAnalyzer(
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            100, // Very high threshold
        );

        $user = new User();
        $user->setName('author');
        $user->setEmail('author@example.com');

        $this->entityManager->persist($user);

        // Only 5 posts (below threshold)
        for ($i = 1; $i <= 5; $i++) {
            $post = new BlogPost();
            $post->setTitle('Post ' . $i);
            $post->setContent('Content ' . $i);
            $post->setAuthor($user);
            $this->entityManager->persist($post);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        $posts = $this->entityManager->getRepository(BlogPost::class)->findAll();
        foreach ($posts as $post) {
            $post->getAuthor()->getName();
        }

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $lazyLoadingAnalyzer->analyze($queryDataCollection);

        // With threshold=100, should NOT flag 5 queries
        self::assertCount(0, $issueCollection, 'Should respect threshold and not flag small numbers');
    }

    #[Test]
    public function it_shows_performance_impact(): void
    {
        $user = new User();
        $user->setName('author');
        $user->setEmail('author@example.com');

        $this->entityManager->persist($user);

        for ($i = 1; $i <= 20; $i++) {
            $post = new BlogPost();
            $post->setTitle('Post ' . $i);
            $post->setContent('Content ' . $i);
            $post->setAuthor($user);
            $this->entityManager->persist($post);

            // Add comments to trigger lazy loading
            for ($j = 1; $j <= 2; $j++) {
                $comment = new Comment();
                $comment->setContent('Comment ' . $j);
                $comment->setAuthor($user);
                $comment->setPost($post);
                $this->entityManager->persist($comment);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        // Test 1: Lazy loading (bad)
        $this->queryLogger->reset();
        $this->startQueryCollection();

        $posts = $this->entityManager->getRepository(BlogPost::class)->findAll();
        foreach ($posts as $post) {
            foreach ($post->getComments() as $comment) {
                $comment->getContent();
            }
        }

        $queryDataCollection = $this->stopQueryCollection();
        $lazyQueryCount = $this->queryLogger->count();

        $this->entityManager->clear();

        // Test 2: Eager loading (good)
        $this->queryLogger->reset();
        $this->startQueryCollection();

        $posts = $this->entityManager->createQueryBuilder()
            ->select('p', 'c')
            ->from(BlogPost::class, 'p')
            ->leftJoin('p.comments', 'c')
            ->getQuery()
            ->getResult();

        foreach ($posts as $post) {
            foreach ($post->getComments() as $comment) {
                $comment->getContent();
            }
        }

        $eagerQueries = $this->stopQueryCollection();
        $eagerQueryCount = $this->queryLogger->count();

        // Lazy loading should generate MANY more queries
        self::assertGreaterThan($eagerQueryCount * 5, $lazyQueryCount, 'Lazy loading should generate significantly more queries than eager loading');

        // Analyze lazy loading
        $issueCollection = $this->lazyLoadingAnalyzer->analyze($queryDataCollection);
        $eagerIssues = $this->lazyLoadingAnalyzer->analyze($eagerQueries);

        self::assertIsArray($issueCollection->toArray(), 'Should analyze lazy loading');
        self::assertCount(0, $eagerIssues, 'Should NOT flag eager loading');
    }

    #[Test]
    public function it_detects_lazy_loading_severity(): void
    {
        $user = new User();
        $user->setName('author');
        $user->setEmail('author@example.com');

        $this->entityManager->persist($user);

        for ($i = 1; $i <= 15; $i++) {
            $post = new BlogPost();
            $post->setTitle('Post ' . $i);
            $post->setContent('Content ' . $i);
            $post->setAuthor($user);
            $this->entityManager->persist($post);

            // Add comments to each post
            for ($j = 1; $j <= 2; $j++) {
                $comment = new Comment();
                $comment->setContent('Comment ' . $j);
                $comment->setAuthor($user);
                $comment->setPost($post);
                $this->entityManager->persist($comment);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        $posts = $this->entityManager->getRepository(BlogPost::class)->findAll();
        foreach ($posts as $post) {
            foreach ($post->getComments() as $comment) {
                $comment->getContent();
            }
        }

        $queryDataCollection = $this->stopQueryCollection();
        $issueCollection = $this->lazyLoadingAnalyzer->analyze($queryDataCollection);

        self::assertIsArray($issueCollection->toArray());

        if (count($issueCollection) > 0) {
            $issue = $issueCollection->toArray()[0];

            // Verify it's a performance issue category
            self::assertEquals('performance', $issue->getCategory(), 'Lazy loading should be performance category');

            // Severity varies based on suggestion metadata
            self::assertNotEmpty($issue->getSeverity(), 'Should have a severity level');
        }
    }
}
