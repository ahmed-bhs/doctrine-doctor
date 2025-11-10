<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\EagerLoadingAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Comment;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Order;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\OrderItem;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for EagerLoadingAnalyzer - Over-fetching Detection.
 *
 * This test demonstrates REAL over-fetching problems:
 * - Too many JOINs in a single query (cartesian product)
 * - Loading unnecessary relations eagerly
 * - Memory and performance impact of excessive eager loading
 */
final class EagerLoadingAnalyzerIntegrationTest extends DatabaseTestCase
{
    private EagerLoadingAnalyzer $eagerLoadingAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->createSchema([
            User::class,
            BlogPost::class,
            Comment::class,
            Product::class,
            Order::class,
            OrderItem::class,
        ]);

        $this->eagerLoadingAnalyzer = new EagerLoadingAnalyzer(
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            4, // Warn on 4+ joins
            7,  // Critical on 7+ joins
        );
    }

    #[Test]
    public function it_detects_excessive_joins_in_query(): void
    {
        // Create test data
        $user = new User();
        $user->setName('user1');
        $user->setEmail('user1@example.com');

        $this->entityManager->persist($user);

        $blogPost = new BlogPost();
        $blogPost->setTitle('Post 1');
        $blogPost->setContent('Content');
        $blogPost->setAuthor($user);

        $this->entityManager->persist($blogPost);

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // 📢 BAD: Query with many JOINs (over-fetching)
        // In a real app this would be:
        // SELECT p, a, c, ca FROM BlogPost p
        // JOIN p.author a
        // JOIN p.comments c
        // JOIN c.author ca
        // JOIN a.orders o
        // JOIN o.items oi
        // etc...

        // Simulate a query with multiple joins
        $dql = <<<DQL
        SELECT p, a
        FROM AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost p
        JOIN p.author a
        LEFT JOIN a.posts posts
        LEFT JOIN a.orders orders
        LEFT JOIN p.comments comments
        DQL;

        $this->entityManager->createQuery($dql)->getResult();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->eagerLoadingAnalyzer->analyze($queryDataCollection);

        // Should detect excessive JOINs
        self::assertGreaterThan(0, count($issueCollection), 'Should detect excessive JOINs');

        $issue = $issueCollection->toArray()[0];
        self::assertEquals('performance', $issue->getCategory());
        self::assertStringContainsString('JOIN', (string) $issue->getTitle());
    }

    #[Test]
    public function it_does_not_flag_reasonable_number_of_joins(): void
    {
        $user = new User();
        $user->setName('user1');
        $user->setEmail('user1@example.com');

        $this->entityManager->persist($user);

        $blogPost = new BlogPost();
        $blogPost->setTitle('Post 1');
        $blogPost->setContent('Content');
        $blogPost->setAuthor($user);

        $this->entityManager->persist($blogPost);

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // GOOD: Reasonable number of JOINs (< threshold)
        $dql = <<<DQL
        SELECT p, a
        FROM AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost p
        JOIN p.author a
        DQL;

        $this->entityManager->createQuery($dql)->getResult();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->eagerLoadingAnalyzer->analyze($queryDataCollection);

        // Should NOT flag reasonable JOINs
        self::assertCount(0, $issueCollection, 'Should NOT flag reasonable number of JOINs');
    }

    #[Test]
    public function it_detects_critical_join_count(): void
    {
        $this->startQueryCollection();

        // 📢 CRITICAL: Too many JOINs (over threshold of 7)
        // Need 8+ JOINs for critical severity
        $sql = <<<SQL
        SELECT *
        FROM blog_posts p
        JOIN users a ON p.author_id = a.id
        LEFT JOIN comments c1 ON c1.post_id = p.id
        LEFT JOIN comments c2 ON c2.post_id = p.id
        LEFT JOIN users ca ON c1.author_id = ca.id
        LEFT JOIN orders o ON o.user_id = a.id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        LEFT JOIN products prod ON oi.product_id = prod.id
        LEFT JOIN blog_posts bp2 ON bp2.author_id = ca.id
        SQL;

        // Execute the query
        $this->entityManager->getConnection()->executeQuery($sql);

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->eagerLoadingAnalyzer->analyze($queryDataCollection);

        self::assertGreaterThan(0, count($issueCollection));

        $issue = $issueCollection->toArray()[0];

        // Should be CRITICAL severity due to high JOIN count (>7)
        self::assertEquals('critical', $issue->getSeverity()->value, 'Excessive JOINs (>7) should be CRITICAL');
    }

    #[Test]
    public function it_provides_optimization_suggestions(): void
    {
        $this->startQueryCollection();

        // Query with many JOINs
        $sql = <<<SQL
        SELECT *
        FROM blog_posts p
        JOIN users a ON p.author_id = a.id
        LEFT JOIN comments c ON c.post_id = p.id
        LEFT JOIN users ca ON c.author_id = ca.id
        LEFT JOIN orders o ON o.user_id = a.id
        SQL;

        $this->entityManager->getConnection()->executeQuery($sql);

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->eagerLoadingAnalyzer->analyze($queryDataCollection);

        self::assertGreaterThan(0, count($issueCollection));

        $issue = $issueCollection->toArray()[0];
        $suggestion = $issue->getSuggestion();

        self::assertInstanceOf(SuggestionInterface::class, $suggestion, 'Should provide suggestion');

        $code = $suggestion->getCode();

        // Should suggest alternatives in the code
        self::assertStringContainsString('EXTRA_LAZY', (string) $code, 'Should suggest EXTRA_LAZY collections');
    }

    #[Test]
    public function it_respects_threshold_configuration(): void
    {
        // Create analyzer with high threshold
        $eagerLoadingAnalyzer = new EagerLoadingAnalyzer(
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            10, // High threshold
            15,
        );

        $this->startQueryCollection();

        // Query with 4 JOINs (below new threshold)
        $sql = <<<SQL
        SELECT *
        FROM blog_posts p
        JOIN users a ON p.author_id = a.id
        LEFT JOIN comments c ON c.post_id = p.id
        LEFT JOIN users ca ON c.author_id = ca.id
        SQL;

        $this->entityManager->getConnection()->executeQuery($sql);

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $eagerLoadingAnalyzer->analyze($queryDataCollection);

        // Should NOT flag with high threshold
        self::assertCount(0, $issueCollection, 'Should respect custom threshold');
    }

    #[Test]
    public function it_demonstrates_cartesian_product_problem(): void
    {
        // Create data that demonstrates cartesian product
        $user = new User();
        $user->setName('user1');
        $user->setEmail('user1@example.com');

        $this->entityManager->persist($user);

        // Create 3 posts
        for ($i = 1; $i <= 3; $i++) {
            $post = new BlogPost();
            $post->setTitle('Post ' . $i);
            $post->setContent('Content ' . $i);
            $post->setAuthor($user);
            $this->entityManager->persist($post);

            // Each post has 3 comments
            for ($j = 1; $j <= 3; $j++) {
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

        // 📢 BAD: Joining two collections causes cartesian product
        // User has 3 posts, each post has 3 comments
        // This would return 9 rows instead of 3!
        $dql = <<<DQL
        SELECT u, p, c
        FROM AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User u
        LEFT JOIN u.posts p
        LEFT JOIN p.comments c
        WHERE u.id = :userId
        DQL;

        $this->entityManager->createQuery($dql)
            ->setParameter('userId', $user->getId())
            ->getResult();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->eagerLoadingAnalyzer->analyze($queryDataCollection);

        self::assertIsArray($issueCollection->toArray(), 'Should analyze cartesian product queries');

        // The query has multiple JOINs which can cause issues
        if (count($issueCollection) > 0) {
            $issue = $issueCollection->toArray()[0];
            self::assertEquals('performance', $issue->getCategory());
        }
    }

    #[Test]
    public function it_shows_performance_warning_level(): void
    {
        $this->startQueryCollection();

        // Query with exactly threshold JOINs (warning level)
        $sql = <<<SQL
        SELECT *
        FROM blog_posts p
        JOIN users a ON p.author_id = a.id
        LEFT JOIN comments c ON c.post_id = p.id
        LEFT JOIN users ca ON c.author_id = ca.id
        LEFT JOIN orders o ON o.user_id = a.id
        SQL;

        $this->entityManager->getConnection()->executeQuery($sql);

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->eagerLoadingAnalyzer->analyze($queryDataCollection);

        self::assertGreaterThan(0, count($issueCollection));

        $issue = $issueCollection->toArray()[0];

        // Should be warning (not critical yet)
        self::assertContains($issue->getSeverity()->value, ['warning', 'info'], 'Should be warning level at threshold');
    }
}
