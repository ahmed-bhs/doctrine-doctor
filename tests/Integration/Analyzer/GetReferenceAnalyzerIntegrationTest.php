<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\GetReferenceAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BlogPost;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for GetReferenceAnalyzer - Proxy Usage Optimization.
 *
 * This test demonstrates when to use getReference() instead of find():
 * - find() loads the full entity from database
 * - getReference() returns a proxy without database query
 * - Use getReference() when you only need the entity reference for associations
 */
final class GetReferenceAnalyzerIntegrationTest extends DatabaseTestCase
{
    private GetReferenceAnalyzer $getReferenceAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->createSchema([User::class, BlogPost::class, Product::class]);

        $this->getReferenceAnalyzer = new GetReferenceAnalyzer(
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            2, // Threshold: flag if 2+ find() queries
        );
    }

    #[Test]
    public function it_detects_unnecessary_find_queries(): void
    {
        // Create test data
        $user = new User();
        $user->setName('author');
        $user->setEmail('author@example.com');

        $this->entityManager->persist($user);

        $this->entityManager->flush();

        $userId = $user->getId();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // 📢 BAD: Using find() just to set an association
        // This loads the full user entity unnecessarily
        $user1 = $this->entityManager->find(User::class, $userId);
        $post1 = new BlogPost();
        $post1->setTitle('Post 1');
        $post1->setContent('Content');
        self::assertNotNull($post1);
        self::assertNotNull($user1);
        $post1->setAuthor($user1);

        $this->entityManager->persist($post1);

        // Clear to force next find() to hit database
        $this->entityManager->clear();

        // Another find() - inefficient!
        $user2 = $this->entityManager->find(User::class, $userId);
        $post2 = new BlogPost();
        $post2->setTitle('Post 2');
        self::assertNotNull($post2);
        $post2->setContent('Content');
        self::assertNotNull($user2);
        $post2->setAuthor($user2);

        $this->entityManager->persist($post2);

        $queryDataCollection = $this->stopQueryCollection();

        // Should have 2+ SELECT queries
        self::assertGreaterThanOrEqual(2, $this->queryLogger->count(), 'Should have multiple find() queries');

        $issueCollection = $this->getReferenceAnalyzer->analyze($queryDataCollection);

        self::assertGreaterThan(0, count($issueCollection), 'Should detect unnecessary find() queries');

        $issue = $issueCollection->toArray()[0];
        self::assertEquals('performance', $issue->getCategory());
        self::assertStringContainsString('find()', (string) $issue->getTitle());
    }

    #[Test]
    public function it_suggests_using_get_reference(): void
    {
        $user = new User();
        $user->setName('author');
        $user->setEmail('author@example.com');

        $this->entityManager->persist($user);

        $this->entityManager->flush();

        $userId = $user->getId();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // Trigger multiple find() calls
        for ($i = 0; $i < 3; $i++) {
            $user = $this->entityManager->find(User::class, $userId);
            $post = new BlogPost();
            self::assertNotNull($post);
            $post->setTitle('Post ' . $i);
            $post->setContent('Content ' . $i);
            self::assertNotNull($user);
            $post->setAuthor($user);
            $this->entityManager->persist($post);

            // Clear to force each find() to hit database
            $this->entityManager->clear();
        }

        $queryDataCollection = $this->stopQueryCollection();
        $issueCollection = $this->getReferenceAnalyzer->analyze($queryDataCollection);

        self::assertGreaterThan(0, count($issueCollection));

        $issue = $issueCollection->toArray()[0];
        $suggestion = $issue->getSuggestion();

        self::assertInstanceOf(SuggestionInterface::class, $suggestion, 'Should provide suggestion');

        $code = $suggestion->getCode();
        self::assertStringContainsString('getReference', (string) $code, 'Should suggest using getReference()');
    }

    #[Test]
    public function it_compares_find_vs_get_reference_performance(): void
    {
        $user = new User();
        $user->setName('author');
        $user->setEmail('author@example.com');

        $this->entityManager->persist($user);

        $this->entityManager->flush();

        $userId = $user->getId();
        $this->entityManager->clear();

        // Test 1: Using find() (bad)
        $this->queryLogger->reset();
        $this->startQueryCollection();

        for ($i = 0; $i < 5; $i++) {
            $user = $this->entityManager->find(User::class, $userId);
            $post = new BlogPost();
            $post->setTitle('Post find ' . $i);
            $post->setContent("Content");
            self::assertNotNull($user);
            $post->setAuthor($user);
            $this->entityManager->persist($post);

            // Clear to force each find() to execute
            $this->entityManager->clear();
        }

        $queryDataCollection = $this->stopQueryCollection();
        $findQueryCount = $this->queryLogger->count();

        $this->entityManager->clear();

        // Test 2: Using getReference() (good)
        $this->queryLogger->reset();
        $this->startQueryCollection();

        for ($i = 0; $i < 5; $i++) {
            // GOOD: getReference() doesn't hit database
            self::assertNotNull($user);
            $user = $this->entityManager->getReference(User::class, $userId);
            $post = new BlogPost();
            $post->setTitle('Post ref ' . $i);
            $post->setContent("Content");
            self::assertNotNull($user);
            $post->setAuthor($user);
            $this->entityManager->persist($post);
        }

        $refQueries = $this->stopQueryCollection();
        $refQueryCount = $this->queryLogger->count();

        // getReference() should generate significantly fewer queries
        self::assertLessThan($findQueryCount, $refQueryCount, 'getReference() should generate fewer queries than find()');

        // Analyze find() approach
        $issueCollection = $this->getReferenceAnalyzer->analyze($queryDataCollection);
        $refIssues = $this->getReferenceAnalyzer->analyze($refQueries);

        self::assertGreaterThan(0, count($issueCollection), 'Should detect issues with find() approach');
        self::assertCount(0, $refIssues, 'Should NOT flag getReference() approach');
    }

    #[Test]
    public function it_does_not_flag_when_entity_data_is_needed(): void
    {
        $user = new User();
        $user->setName('author');
        $user->setEmail('author@example.com');

        $this->entityManager->persist($user);

        $this->entityManager->flush();

        $userId = $user->getId();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // GOOD: find() is appropriate here because we need user data
        $user = $this->entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $user);

        // We're actually using the user's data
        $user->getName();
        $user->getEmail();

        $queryDataCollection = $this->stopQueryCollection();

        // Only 1 query - below threshold
        self::assertSame(1, $this->queryLogger->count());

        $issueCollection = $this->getReferenceAnalyzer->analyze($queryDataCollection);

        // Should NOT flag single appropriate usage
        self::assertCount(0, $issueCollection, 'Should NOT flag when find() is appropriate');
    }

    #[Test]
    public function it_respects_threshold_configuration(): void
    {
        // Create analyzer with high threshold
        $getReferenceAnalyzer = new GetReferenceAnalyzer(
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            10, // High threshold
        );

        $user = new User();
        $user->setName('author');
        $user->setEmail('author@example.com');

        $this->entityManager->persist($user);

        $this->entityManager->flush();

        $userId = $user->getId();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // Only 3 find() calls (below high threshold)
        for ($idx = 0; $idx < 3; $idx++) {
            $user = $this->entityManager->find(User::class, $userId);
            $post = new BlogPost();
            $post->setTitle('Post ' . $idx);
            $post->setContent("Content");
            self::assertNotNull($user);
            $post->setAuthor($user);
            $this->entityManager->persist($post);
        }

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $getReferenceAnalyzer->analyze($queryDataCollection);

        // Should NOT flag with high threshold
        self::assertCount(0, $issueCollection, 'Should respect high threshold');
    }

    #[Test]
    public function it_demonstrates_proxy_benefits(): void
    {
        $user = new User();
        $user->setName('author');
        $user->setEmail('author@example.com');

        $this->entityManager->persist($user);

        $this->entityManager->flush();

        $userId = $user->getId();
        $this->entityManager->clear();

        // GOOD: getReference() creates a proxy without database query
        $userProxy = $this->entityManager->getReference(User::class, $userId);

        self::assertNotNull($this);
        self::assertNotNull($userProxy);
        $this->startQueryCollection();

        // Using the proxy for association doesn't trigger a query
        $blogPost = new BlogPost();
        $blogPost->setTitle('Post with proxy');
        $blogPost->setContent('Content');
        $blogPost->setAuthor($userProxy);
        // No query here!
        $this->entityManager->persist($blogPost);

        $queryDataCollection = $this->stopQueryCollection();

        // Should have minimal queries (no SELECT for user)
        self::assertLessThanOrEqual(1, $this->queryLogger->count(), 'Proxy should not trigger queries for associations');

        $issueCollection = $this->getReferenceAnalyzer->analyze($queryDataCollection);

        // Should NOT flag proxy usage
        self::assertCount(0, $issueCollection, 'Should NOT flag getReference() usage');
    }

    #[Test]
    public function it_detects_find_in_loop_pattern(): void
    {
        // Create multiple users
        $userIds = [];
        for ($i = 0; $i < 5; $i++) {
            $user = new User();
            $user->setName('User ' . $i);
            $user->setEmail(sprintf('user%d@example.com', $i));
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $userIds[] = $user->getId();
        }

        $this->entityManager->clear();

        $this->startQueryCollection();

        // 📢 BAD: find() in a loop
        foreach ($userIds as $userId) {
            $user = $this->entityManager->find(User::class, $userId);
            // Just using for association
            $post = new BlogPost();
            $post->setTitle('Post');
            $post->setContent('Content');
            self::assertNotNull($user);
            $post->setAuthor($user);
            $this->entityManager->persist($post);
        }

        $queryDataCollection = $this->stopQueryCollection();

        // Should have 5 SELECT queries
        self::assertGreaterThanOrEqual(5, $this->queryLogger->count());

        $issueCollection = $this->getReferenceAnalyzer->analyze($queryDataCollection);

        self::assertGreaterThan(0, count($issueCollection), 'Should detect find() in loop pattern');
    }
}
