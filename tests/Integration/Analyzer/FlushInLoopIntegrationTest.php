<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\FlushInLoopAnalyzerModern;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for FlushInLoopAnalyzerModern using real database operations.
 *
 * Demonstrates:
 * - Real flush operations in loops
 * - Real batch processing comparison
 * - Real performance differences
 */
final class FlushInLoopIntegrationTest extends DatabaseTestCase
{
    private FlushInLoopAnalyzerModern $flushInLoopAnalyzerModern;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->flushInLoopAnalyzerModern = new FlushInLoopAnalyzerModern(
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );

        $this->createSchema([Product::class]);
    }

    #[Test]
    public function it_detects_flush_in_loop_with_real_inserts(): void
    {
        $this->startQueryCollection();

        // BAD: Flush inside the loop
        for ($i = 0; $i < 10; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);

            $this->entityManager->persist($product);
            $this->entityManager->flush(); // 📢 Anti-pattern!

            // Query after flush creates the INSERT -> SELECT pattern
            $this->entityManager->getRepository(Product::class)->findOneBy(['name' => 'Product ' . $i]);
        }

        $queryDataCollection = $this->stopQueryCollection();

        // We should see 10 INSERT queries + 10 SELECT queries = 20 queries
        self::assertGreaterThanOrEqual(10, $this->queryLogger->count(), 'Should have 10+ queries (flush per iteration)');

        // The analyzer should detect this pattern
        $issueCollection = $this->flushInLoopAnalyzerModern->analyze($queryDataCollection);
        self::assertGreaterThan(0, count($issueCollection), 'Should detect flush in loop pattern');
    }

    #[Test]
    public function it_does_not_detect_issue_with_batch_processing(): void
    {
        $this->startQueryCollection();

        // GOOD: Batch processing with single flush
        for ($i = 0; $i < 10; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);

            $this->entityManager->persist($product);
        }

        $this->entityManager->flush(); // Single flush at the end

        $queryDataCollection = $this->stopQueryCollection();

        // Batch flush will still generate 10 INSERTs, but in a single transaction
        // The key difference is NO pattern of INSERT -> SELECT -> INSERT -> SELECT
        self::assertGreaterThanOrEqual(10, $this->queryLogger->count(), 'Should have 10 INSERTs from batch flush');

        $issueCollection = $this->flushInLoopAnalyzerModern->analyze($queryDataCollection);
        self::assertCount(0, $issueCollection, 'Should NOT detect issue with batch processing');
    }

    #[Test]
    public function it_demonstrates_real_performance_difference(): void
    {
        // Measure flush-in-loop approach
        $startBad = microtime(true);
        $this->queryLogger->reset();
        $this->queryLogger->start();

        for ($i = 0; $i < 50; $i++) {
            $product = new Product();
            $product->setName('Bad Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);

            $this->entityManager->persist($product);
            $this->entityManager->flush();
        }

        $badTime = microtime(true) - $startBad;
        $badQueryCount = $this->queryLogger->count();
        $this->queryLogger->stop();

        // Clear and measure batch approach
        $this->entityManager->clear();
        $this->queryLogger->reset();

        $startGood = microtime(true);
        $this->queryLogger->start();

        for ($i = 0; $i < 50; $i++) {
            $product = new Product();
            $product->setName('Good Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);

            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();

        $goodTime = microtime(true) - $startGood;
        $goodQueryCount = $this->queryLogger->count();
        $this->queryLogger->stop();

        // Performance comparison
        // Flush-in-loop generates MORE queries due to transaction overhead
        // Each flush() triggers a transaction commit and additional overhead
        self::assertGreaterThan($goodQueryCount, $badQueryCount, 'Flush-in-loop generates more queries due to transaction overhead');

        // Verify both approaches inserted 50 products each
        self::assertSame(
            50,
            (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Product::class, 'p')
            ->where('p.name LIKE :prefix')
            ->setParameter('prefix', 'Bad Product%')
            ->getQuery()
            ->getSingleScalarResult(),
        );

        self::assertSame(
            50,
            (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Product::class, 'p')
            ->where('p.name LIKE :prefix')
            ->setParameter('prefix', 'Good Product%')
            ->getQuery()
            ->getSingleScalarResult(),
        );

        // Flush-in-loop should be slower due to transaction overhead
        self::assertGreaterThan($goodTime, $badTime, 'Flush-in-loop should be SLOWER due to transaction overhead');

        $timeImprovement = (($badTime - $goodTime) / $badTime) * 100;

        self::assertGreaterThan(0, $timeImprovement, sprintf(
            'Batch processing improved time by %.1f%% (%.4fs vs %.4fs)',
            $timeImprovement,
            $badTime,
            $goodTime,
        ));
    }

    #[Test]
    public function it_handles_batch_size_optimization(): void
    {
        $this->startQueryCollection();

        $batchSize = 20;

        // BEST PRACTICE: Batch processing with periodic flush (without clear for memory optimization)
        // Using 60 items with batch size 30 to create 2 flush groups (well below the threshold of 5)
        $batchSize = 30;
        for ($i = 0; $i < 60; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);

            $this->entityManager->persist($product);

            // Flush every N entities (without clear to avoid triggering flush boundary detection)
            if (($i + 1) % $batchSize === 0) {
                $this->entityManager->flush();
            }
        }

        // Final flush for remaining entities
        $this->entityManager->flush();

        $queryDataCollection = $this->stopQueryCollection();

        // We have 60 INSERTs executed in 2 batches (60 / 30) + 1 final flush
        // Due to transaction overhead, we may have additional queries
        self::assertGreaterThanOrEqual(60, $this->queryLogger->count(), 'Should have at least 60 INSERTs');

        // Verify all 60 products were inserted
        self::assertSame(
            60,
            (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Product::class, 'p')
            ->getQuery()
            ->getSingleScalarResult(),
        );

        $issueCollection = $this->flushInLoopAnalyzerModern->analyze($queryDataCollection);
        // This is acceptable batch processing with periodic flush, not flush-in-loop anti-pattern
        // The analyzer threshold is 5, and with only 2 flush() calls in the loop, it won't trigger
        self::assertCount(0, $issueCollection, 'Batch processing should not be flagged');
    }
}
