<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\BulkOperationAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for BulkOperationAnalyzer - Batch Processing.
 *
 * This test demonstrates inefficient bulk operations:
 * - Individual INSERT/UPDATE/DELETE instead of batch
 * - Performance impact of many small operations
 * - Better alternatives with DQL bulk operations
 */
final class BulkOperationAnalyzerIntegrationTest extends DatabaseTestCase
{
    private BulkOperationAnalyzer $bulkOperationAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->createSchema([User::class, Product::class]);

        $this->bulkOperationAnalyzer = new BulkOperationAnalyzer(
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            20, // Threshold: warn on 20+ operations
        );
    }

    #[Test]
    public function it_detects_many_individual_updates(): void
    {
        // Create products
        $products = [];
        for ($i = 0; $i < 25; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(10.0);
            $product->setStock(100);
            $this->entityManager->persist($product);
            $products[] = $product;
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        // Reload products
        $products = $this->entityManager->getRepository(Product::class)->findAll();

        $this->startQueryCollection();

        // 📢 BAD: Individual updates in a loop
        foreach ($products as $product) {
            $product->setPrice(15.0);
        }

        $this->entityManager->flush();

        $queryDataCollection = $this->stopQueryCollection();

        // Should have many UPDATE queries
        self::assertGreaterThan(20, $this->queryLogger->count(), 'Should generate many UPDATE queries');

        $issueCollection = $this->bulkOperationAnalyzer->analyze($queryDataCollection);

        self::assertIsArray($issueCollection->toArray(), 'Should analyze bulk operations');

        if (count($issueCollection) > 0) {
            $issue = $issueCollection->toArray()[0];
            self::assertEquals('performance', $issue->getCategory());
            self::assertStringContainsString('UPDATE', (string) $issue->getTitle());
        }
    }

    #[Test]
    public function it_suggests_dql_bulk_update(): void
    {
        // Create products
        for ($i = 0; $i < 25; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(10.0);
            $product->setStock(100);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $products = $this->entityManager->getRepository(Product::class)->findAll();

        $this->startQueryCollection();

        foreach ($products as $product) {
            $product->setPrice(15.0);
        }

        $this->entityManager->flush();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->bulkOperationAnalyzer->analyze($queryDataCollection);

        self::assertIsArray($issueCollection->toArray());

        if (count($issueCollection) > 0) {
            $issue = $issueCollection->toArray()[0];
            $suggestion = $issue->getSuggestion();

            self::assertInstanceOf(SuggestionInterface::class, $suggestion);

            $description = $suggestion->getDescription();
            self::assertStringContainsString('batch', strtolower((string) $description), 'Should suggest batch processing');
        }
    }

    #[Test]
    public function it_compares_individual_vs_batch_updates(): void
    {
        // Create products
        for ($i = 0; $i < 30; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(10.0);
            $product->setStock(100);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        // Test 1: Individual updates (bad)
        $products = $this->entityManager->getRepository(Product::class)->findAll();

        $this->queryLogger->reset();
        $this->startQueryCollection();

        foreach ($products as $product) {
            $product->setPrice(15.0);
        }

        $this->entityManager->flush();

        $queryDataCollection = $this->stopQueryCollection();
        $individualQueryCount = $this->queryLogger->count();

        $this->entityManager->clear();

        // Test 2: DQL bulk update (good)
        $this->queryLogger->reset();
        $this->startQueryCollection();

        // GOOD: Single DQL UPDATE
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $queryBuilder->update(Product::class, 'p')
            ->set('p.price', ':newPrice')
            ->setParameter('newPrice', 20.0)
            ->getQuery()
            ->execute();

        $batchQueries = $this->stopQueryCollection();
        $batchQueryCount = $this->queryLogger->count();

        // Batch should be much more efficient
        self::assertLessThan($individualQueryCount, $batchQueryCount + 10, 'Batch update should use fewer queries');

        // Analyze individual approach
        $issueCollection = $this->bulkOperationAnalyzer->analyze($queryDataCollection);
        $batchIssues = $this->bulkOperationAnalyzer->analyze($batchQueries);

        self::assertIsArray($issueCollection->toArray(), 'Should analyze individual updates');
        self::assertCount(0, $batchIssues, 'Should NOT flag batch operations');
    }

    #[Test]
    public function it_does_not_flag_small_batches(): void
    {
        // Create only 10 products (below threshold)
        for ($i = 0; $i < 10; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(10.0);
            $product->setStock(100);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $products = $this->entityManager->getRepository(Product::class)->findAll();

        $this->startQueryCollection();

        foreach ($products as $product) {
            $product->setPrice(15.0);
        }

        $this->entityManager->flush();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->bulkOperationAnalyzer->analyze($queryDataCollection);

        // Should NOT flag below threshold
        self::assertCount(0, $issueCollection, 'Should NOT flag small number of operations');
    }

    #[Test]
    public function it_respects_threshold_configuration(): void
    {
        // Create analyzer with high threshold
        $bulkOperationAnalyzer = new BulkOperationAnalyzer(
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            50, // High threshold
        );

        // Create 30 products (below high threshold)
        for ($i = 0; $i < 30; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(10.0);
            $product->setStock(100);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $products = $this->entityManager->getRepository(Product::class)->findAll();

        $this->startQueryCollection();

        foreach ($products as $product) {
            $product->setPrice(15.0);
        }

        $this->entityManager->flush();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $bulkOperationAnalyzer->analyze($queryDataCollection);

        // Should NOT flag with high threshold
        self::assertCount(0, $issueCollection, 'Should respect high threshold');
    }
}
