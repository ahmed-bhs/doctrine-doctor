<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Performance\StructuralMissingIndexAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Category;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\Test;

/**
 * Proves the analyzer flags a missing index on a tiny table (no EXPLAIN,
 * no row-count threshold), and stays silent once a leading index exists.
 */
final class StructuralMissingIndexAnalyzerIntegrationTest extends DatabaseTestCase
{
    private StructuralMissingIndexAnalyzer $structuralMissingIndexAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->structuralMissingIndexAnalyzer = new StructuralMissingIndexAnalyzer(
            suggestionFactory: PlatformAnalyzerTestHelper::createSuggestionFactory(),
            connection: $this->connection,
        );

        $this->createSchema([Product::class, Category::class]);
    }

    #[Test]
    public function it_flags_unindexed_equality_filter_on_a_tiny_table(): void
    {
        $category = new Category();
        $category->setName('Electronics');
        $this->entityManager->persist($category);

        $product = new Product();
        $product->setName('Single product');
        $product->setPrice(9.99);
        $product->setStock(1);
        $product->setCategory($category);
        $this->entityManager->persist($product);

        $this->entityManager->flush();

        $queryDataCollection = QueryDataCollection::fromArray([
            new QueryData(
                sql: "SELECT * FROM products WHERE stock = 1",
                executionTime: \AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime::fromMilliseconds(1),
                params: [],
                backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type
            ),
        ]);

        $issueCollection = $this->structuralMissingIndexAnalyzer->analyze($queryDataCollection);

        self::assertCount(1, $issueCollection);

        $issue = $issueCollection->toArray()[0];
        self::assertSame(Severity::INFO, $issue->getSeverity());
        self::assertStringContainsString('stock', $issue->getDescription());
    }

    #[Test]
    public function it_stays_silent_when_a_leading_index_already_covers_the_filter(): void
    {
        $category = new Category();
        $category->setName('Books');
        $this->entityManager->persist($category);

        $product = new Product();
        $product->setName('Single book');
        $product->setPrice(9.99);
        $product->setStock(1);
        $product->setCategory($category);
        $this->entityManager->persist($product);

        $this->entityManager->flush();

        $this->connection->executeStatement('CREATE INDEX idx_products_stock ON products (stock)');

        $queryDataCollection = QueryDataCollection::fromArray([
            new QueryData(
                sql: "SELECT * FROM products WHERE stock = 1",
                executionTime: \AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime::fromMilliseconds(1),
                params: [],
                backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type
            ),
        ]);

        $issueCollection = $this->structuralMissingIndexAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    #[Test]
    public function it_does_not_flag_the_primary_key_equality_filter(): void
    {
        $category = new Category();
        $category->setName('Toys');
        $this->entityManager->persist($category);

        $product = new Product();
        $product->setName('Single toy');
        $product->setPrice(9.99);
        $product->setStock(1);
        $product->setCategory($category);
        $this->entityManager->persist($product);

        $this->entityManager->flush();

        $queryDataCollection = QueryDataCollection::fromArray([
            new QueryData(
                sql: "SELECT * FROM products WHERE id = 1",
                executionTime: \AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime::fromMilliseconds(1),
                params: [],
                backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type
            ),
        ]);

        $issueCollection = $this->structuralMissingIndexAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }

    #[Test]
    public function it_skips_non_select_queries(): void
    {
        $queryDataCollection = QueryDataCollection::fromArray([
            new QueryData(
                sql: 'UPDATE products SET stock = 100 WHERE id = 1',
                executionTime: \AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime::fromMilliseconds(1),
                params: [],
                backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type
            ),
        ]);

        $issueCollection = $this->structuralMissingIndexAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection);
    }
}
