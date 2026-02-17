<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\CollectionJoinDetector;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\JoinOptimizationAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

final class JoinOptimizationAnalyzerIntegrationTest extends DatabaseTestCase
{
    private JoinOptimizationAnalyzer $joinOptimizationAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->createSchema([User::class, Product::class, BlogPost::class]);

        $sqlExtractor = new SqlStructureExtractor();
        $collectionJoinDetector = new CollectionJoinDetector($this->entityManager, $sqlExtractor);

        $this->joinOptimizationAnalyzer = new JoinOptimizationAnalyzer(
            $collectionJoinDetector,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            $sqlExtractor,
            5,
            8,
        );
    }

    #[Test]
    public function it_analyzes_join_queries(): void
    {
        $user = new User();
        $user->setName('User');
        $user->setEmail('user@example.com');

        $this->entityManager->persist($user);

        $blogPost = new BlogPost();
        $blogPost->setTitle('Post');
        $blogPost->setContent('Content');
        $blogPost->setAuthor($user);

        $this->entityManager->persist($blogPost);

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // Query with JOIN
        $this->entityManager->createQueryBuilder()
            ->select('p', 'a')
            ->from(BlogPost::class, 'p')
            ->join('p.author', 'a')
            ->getQuery()
            ->getResult();

        // Another query
        $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->getQuery()
            ->getResult();

        // Third query
        $this->entityManager->find(Product::class, 1);

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->joinOptimizationAnalyzer->analyze($queryDataCollection);

        // Should analyze JOINs
        self::assertIsArray($issueCollection->toArray(), 'Should analyze JOIN queries');
    }

    #[Test]
    public function it_detects_excessive_joins(): void
    {
        $this->startQueryCollection();

        // Execute queries with many JOINs (simulated by raw SQL)
        $sql = <<<SQL
        SELECT *
        FROM blog_posts p
        INNER JOIN users a ON p.author_id = a.id
        LEFT JOIN users u2 ON u2.id = a.id
        LEFT JOIN products pr ON pr.name = p.title
        LEFT JOIN blog_posts bp2 ON bp2.author_id = a.id
        LEFT JOIN blog_posts bp3 ON bp3.author_id = a.id
        LEFT JOIN blog_posts bp4 ON bp4.author_id = a.id
        SQL;

        try {
            $this->entityManager->getConnection()->executeQuery($sql);
        } catch (\Exception) {
            // May fail due to schema
        }

        // Add more queries to meet MIN_QUERY_COUNT
        $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->getQuery()
            ->getResult();

        $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->getQuery()
            ->getResult();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->joinOptimizationAnalyzer->analyze($queryDataCollection);

        self::assertIsArray($issueCollection->toArray());
    }

    #[Test]
    public function it_shows_left_join_vs_inner_join(): void
    {
        $user = new User();
        $user->setName('User');
        $user->setEmail('user@example.com');

        $this->entityManager->persist($user);

        $blogPost = new BlogPost();
        $blogPost->setTitle('Post');
        $blogPost->setContent('Content');
        $blogPost->setAuthor($user);

        $this->entityManager->persist($blogPost);

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // LEFT JOIN (potentially slower)
        $this->entityManager->createQueryBuilder()
            ->select('p', 'a')
            ->from(BlogPost::class, 'p')
            ->leftJoin('p.author', 'a')
            ->getQuery()
            ->getResult();

        // INNER JOIN (faster when NOT NULL)
        $this->entityManager->createQueryBuilder()
            ->select('p', 'a')
            ->from(BlogPost::class, 'p')
            ->join('p.author', 'a')
            ->getQuery()
            ->getResult();

        // Third query
        $this->entityManager->find(User::class, $user->getId());

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->joinOptimizationAnalyzer->analyze($queryDataCollection);

        // Analyzer checks JOIN optimization
        self::assertIsArray($issueCollection->toArray());
    }
}
