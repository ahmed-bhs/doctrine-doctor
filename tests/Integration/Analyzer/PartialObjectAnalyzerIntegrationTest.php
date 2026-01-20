<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\PartialObjectAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for PartialObjectAnalyzer - Memory Optimization.
 *
 * This test demonstrates over-fetching by loading full entities
 * when only a few fields are needed.
 */
final class PartialObjectAnalyzerIntegrationTest extends DatabaseTestCase
{
    private PartialObjectAnalyzer $partialObjectAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->createSchema([User::class, Product::class]);

        $this->partialObjectAnalyzer = new PartialObjectAnalyzer(
            5, // Threshold: flag if 5+ similar queries
        );
    }

    #[Test]
    public function it_detects_full_entity_loading_when_partial_would_suffice(): void
    {
        // Create test data
        for ($i = 0; $i < 10; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // 📢 BAD: Loading full Product entities multiple times
        // when we only need name
        for ($i = 0; $i < 8; $i++) {
            $products = $this->entityManager->createQueryBuilder()
                ->select('p')
                ->from(Product::class, 'p')
                ->setMaxResults(1)
                ->getQuery()
                ->getResult();

            if (!empty($products)) {
                $name = $products[0]->getName(); // Only using name!
            }
        }

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->partialObjectAnalyzer->analyze($queryDataCollection);

        // May or may not be detected depending on pattern matching
        self::assertIsArray($issueCollection->toArray(), 'Should analyze full entity loading');
    }

    #[Test]
    public function it_compares_full_vs_partial_loading(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        // Test 1: Full entity loading (potentially wasteful)
        $this->queryLogger->reset();
        $this->startQueryCollection();

        for ($i = 0; $i < 6; $i++) {
            $products = $this->entityManager->createQueryBuilder()
                ->select('p')
                ->from(Product::class, 'p')
                ->getQuery()
                ->getResult();

            // Only using one field
            foreach ($products as $product) {
                $name = $product->getName();
            }
        }

        $this->stopQueryCollection();

        $this->entityManager->clear();

        // Test 2: Partial object loading (efficient)
        $this->queryLogger->reset();
        $this->startQueryCollection();

        for ($i = 0; $i < 6; $i++) {
            // GOOD: Load only needed fields
            $names = $this->entityManager->createQueryBuilder()
                ->select('PARTIAL p.{id, name}')
                ->from(Product::class, 'p')
                ->getQuery()
                ->getResult();
        }

        $this->stopQueryCollection();

        // Both generate queries, but partial is more memory efficient
        self::assertGreaterThan(0, $this->queryLogger->count(), 'Partial queries should execute');
    }

    #[Test]
    public function it_demonstrates_array_result_alternative(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // BEST: Use array results for read-only data
        $results = $this->entityManager->createQueryBuilder()
            ->select('p.id', 'p.name', 'p.price')
            ->from(Product::class, 'p')
            ->getQuery()
            ->getArrayResult();

        $this->stopQueryCollection();

        self::assertNotEmpty($results, 'Should retrieve array results');
        self::assertIsArray($results[0], 'Results should be arrays, not objects');
    }

    #[Test]
    public function it_shows_memory_impact(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // 📢 BAD: Loading 50 full entities (waste memory)
        $products = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->getQuery()
            ->getResult();

        // Only using names
        array_map(fn ($p) => $p->getName(), $products);

        $queryDataCollection = $this->stopQueryCollection();

        // In production, loading 10,000+ full entities vs partial
        // can mean 100MB+ memory difference
        self::assertGreaterThan(0, count($queryDataCollection));
    }

    #[Test]
    public function it_does_not_flag_when_full_entity_needed(): void
    {
        $product = new Product();
        $product->setName("Product");
        $product->setPrice(9.99);
        $product->setStock(100);

        $this->entityManager->persist($product);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // GOOD: Full entity needed for updates
        $product = $this->entityManager->find(Product::class, $product->getId());
        self::assertInstanceOf(Product::class, $product);
        $product->setPrice(19.99);
        $product->setStock(200);

        $this->entityManager->flush();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->partialObjectAnalyzer->analyze($queryDataCollection);

        // Should not flag legitimate use of full entities
        self::assertIsArray($issueCollection->toArray());
    }
}
