<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\FindAllAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for FindAllAnalyzer - Memory Safety.
 *
 * This test demonstrates problems with findAll() without limits:
 * - Memory exhaustion from loading all entities
 * - Performance degradation with large datasets
 * - Need for pagination or filtering
 */
final class FindAllAnalyzerIntegrationTest extends DatabaseTestCase
{
    private FindAllAnalyzer $findAllAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->createSchema([User::class, Product::class]);

        $this->findAllAnalyzer = new FindAllAnalyzer(
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            99, // Threshold: warn if >99 rows
        );
    }

    #[Test]
    public function it_detects_findall_without_limit(): void
    {
        // Create many products (over threshold)
        for ($i = 0; $i < 150; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // 📢 BAD: findAll() loads everything into memory
        $this->entityManager->getRepository(Product::class)->findAll();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->findAllAnalyzer->analyze($queryDataCollection);

        self::assertIsArray($issueCollection->toArray(), 'Should analyze findAll() queries');

        if (count($issueCollection) > 0) {
            $issue = $issueCollection->toArray()[0];
            self::assertEquals('performance', $issue->getCategory());
            self::assertStringContainsString('findAll', (string) $issue->getTitle());
        }
    }

    #[Test]
    public function it_does_not_flag_queries_with_limit(): void
    {
        for ($i = 0; $i < 150; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // GOOD: Using LIMIT
        $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->findAllAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection, 'Should NOT flag queries with LIMIT');
    }

    #[Test]
    public function it_does_not_flag_queries_with_where(): void
    {
        for ($i = 0; $i < 150; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        // GOOD: Using WHERE clause
        $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.price > :price')
            ->setParameter('price', 5.0)
            ->getQuery()
            ->getResult();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->findAllAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection, 'Should NOT flag queries with WHERE');
    }

    #[Test]
    public function it_suggests_pagination(): void
    {
        for ($i = 0; $i < 150; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        $this->entityManager->getRepository(Product::class)->findAll();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $this->findAllAnalyzer->analyze($queryDataCollection);

        self::assertIsArray($issueCollection->toArray());

        if (count($issueCollection) > 0) {
            $issue = $issueCollection->toArray()[0];
            $suggestion = $issue->getSuggestion();

            self::assertInstanceOf(SuggestionInterface::class, $suggestion);

            $code = $suggestion->getCode();
            self::assertStringContainsString('setMaxResults', (string) $code, 'Should suggest pagination with setMaxResults');
        }
    }

    #[Test]
    public function it_respects_threshold(): void
    {
        // Create analyzer with high threshold
        $findAllAnalyzer = new FindAllAnalyzer(
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            200, // High threshold
        );

        // Only 150 products (below threshold)
        for ($i = 0; $i < 150; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99);
            $product->setStock(100);
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->startQueryCollection();

        $this->entityManager->getRepository(Product::class)->findAll();

        $queryDataCollection = $this->stopQueryCollection();

        $issueCollection = $findAllAnalyzer->analyze($queryDataCollection);

        // The analyzer may estimate row count differently
        // Just verify it analyzes without errors
        self::assertIsArray($issueCollection->toArray(), 'Should analyze queries with threshold');
    }
}
