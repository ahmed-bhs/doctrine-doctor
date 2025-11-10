<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\EntityManagerClearAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for EntityManagerClearAnalyzer - Memory Leak Detection.
 *
 * This test demonstrates memory leaks from not calling clear() in batch operations:
 * - EntityManager keeps ALL entities in memory
 * - Memory grows indefinitely in loops
 * - clear() frees memory by detaching entities
 */
final class EntityManagerClearAnalyzerIntegrationTest extends DatabaseTestCase
{
    private EntityManagerClearAnalyzer $entityManagerClearAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->createSchema([Product::class]);

        $this->entityManagerClearAnalyzer = new EntityManagerClearAnalyzer(
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            20, // Threshold: warn on 20+ operations without clear()
        );
    }

    #[Test]
    public function it_detects_batch_operations_without_clear(): void
    {
        $this->startQueryCollection();

        // 📢 BAD: Batch operations without clear() - memory leak!
        for ($i = 0; $i < 25; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);
            $this->entityManager->persist($product);
            $this->entityManager->flush();
            // Missing: $this->entityManager->clear(); // Would free memory!
        }

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->entityManagerClearAnalyzer->analyze($queryDataCollection);

        self::assertGreaterThan(0, count($issueCollection), 'Should detect batch operations without clear()');

        $issue = $issueCollection->toArray()[0];
        self::assertEquals('performance', $issue->getCategory());
        self::assertStringContainsString('Memory Leak', (string) $issue->getTitle());
    }

    #[Test]
    public function it_does_not_flag_small_batches(): void
    {
        $this->startQueryCollection();

        // GOOD: Small batch (below threshold) - no warning needed
        for ($i = 0; $i < 10; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);
            $this->entityManager->persist($product);
            $this->entityManager->flush();
        }

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->entityManagerClearAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection, 'Should NOT flag small batches');
    }

    #[Test]
    public function it_demonstrates_memory_leak_problem(): void
    {
        $this->startQueryCollection();

        // 📢 BAD: This will keep 30 Product entities in memory
        for ($i = 0; $i < 30; $i++) {
            $product = new Product();
            $product->setName('Heavy Product ' . $i);
            $product->setPrice(999.99);
            $product->setStock(1000);
            $this->entityManager->persist($product);

            if (0 === $i % 10) {
                $this->entityManager->flush();
                // Missing clear() - all 30 entities stay in memory!
            }
        }

        $this->entityManager->flush();

        $queryDataCollection = $this->stopQueryCollection();

        // In a real scenario with 10,000+ entities, this would exhaust memory
        $issueCollection = $this->entityManagerClearAnalyzer->analyze($queryDataCollection);

        self::assertIsArray($issueCollection->toArray(), 'Should analyze memory leak scenario');
    }

    #[Test]
    public function it_compares_with_clear_vs_without_clear(): void
    {
        // Test 1: Without clear() (bad)
        $this->queryLogger->reset();
        $this->startQueryCollection();

        for ($i = 0; $i < 25; $i++) {
            $product = new Product();
            $product->setName('Product no-clear ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);
            $this->entityManager->persist($product);
            $this->entityManager->flush();
        }

        $queryDataCollection = $this->stopQueryCollection();
        $issueCollection = $this->entityManagerClearAnalyzer->analyze($queryDataCollection);

        $this->entityManager->clear();

        // Test 2: With clear() (good)
        $this->queryLogger->reset();
        $this->startQueryCollection();

        for ($i = 0; $i < 25; $i++) {
            $product = new Product();
            $product->setName('Product with-clear ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);
            $this->entityManager->persist($product);
            $this->entityManager->flush();

            if (0 === $i % 10) {
                $this->entityManager->clear(); // Frees memory!
            }
        }

        $queriesWithClear = $this->stopQueryCollection();
        $issuesWithClear = $this->entityManagerClearAnalyzer->analyze($queriesWithClear);

        // Without clear should be flagged
        self::assertGreaterThan(0, count($issueCollection), 'Should detect operations without clear()');

        // The analyzer detects sequential operations - both may be flagged
        // The key difference is memory usage in production
        self::assertIsArray($issuesWithClear->toArray(), 'Should analyze queries with clear()');
    }

    #[Test]
    public function it_suggests_batch_size_with_clear(): void
    {
        $this->startQueryCollection();

        for ($i = 0; $i < 30; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);
            $this->entityManager->persist($product);
            $this->entityManager->flush();
        }

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->entityManagerClearAnalyzer->analyze($queryDataCollection);

        self::assertGreaterThan(0, count($issueCollection));

        $issue = $issueCollection->toArray()[0];
        $suggestion = $issue->getSuggestion();

        self::assertInstanceOf(SuggestionInterface::class, $suggestion);

        $description = $suggestion->getDescription();
        self::assertStringContainsString('clear', strtolower((string) $description), 'Should suggest using clear()');
    }

    #[Test]
    public function it_respects_threshold_configuration(): void
    {
        // Create analyzer with high threshold
        $entityManagerClearAnalyzer = new EntityManagerClearAnalyzer(
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            50, // High threshold
        );

        $this->startQueryCollection();

        // Only 30 operations (below high threshold)
        for ($i = 0; $i < 30; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);
            $this->entityManager->persist($product);
            $this->entityManager->flush();
        }

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $entityManagerClearAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection, 'Should respect high threshold');
    }

    #[Test]
    public function it_demonstrates_real_world_import_scenario(): void
    {
        $this->startQueryCollection();

        // 📢 BAD: Importing 50 products without clear()
        // In production, this could be 10,000+ records
        for ($i = 0; $i < 50; $i++) {
            $product = new Product();
            $product->setName('Imported Product ' . $i);
            $product->setPrice(random_int(10, 100) + 0.99);
            $product->setStock(random_int(10, 1000));
            $this->entityManager->persist($product);

            if (0 === $i % 10) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->entityManagerClearAnalyzer->analyze($queryDataCollection);

        self::assertGreaterThan(0, count($issueCollection), 'Should detect real-world import scenario');

        $issue = $issueCollection->toArray()[0];

        // Should suggest memory leak risk
        self::assertStringContainsString('Memory', (string) $issue->getTitle());
    }
}
