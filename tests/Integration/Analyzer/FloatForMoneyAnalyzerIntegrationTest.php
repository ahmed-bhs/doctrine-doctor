<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\FloatForMoneyAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Product;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for FloatForMoneyAnalyzer.
 *
 * This demonstrates a REAL anti-pattern: using float for money values.
 * The Product entity intentionally uses float for price to test detection.
 */
final class FloatForMoneyAnalyzerIntegrationTest extends DatabaseTestCase
{
    private FloatForMoneyAnalyzer $floatForMoneyAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->floatForMoneyAnalyzer = new FloatForMoneyAnalyzer(
            $this->entityManager,
            new IssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );

        $this->createSchema([Product::class]);
    }

    #[Test]
    public function it_detects_float_used_for_money_in_real_entity(): void
    {
        // Act: Analyze real entity metadata
        $issueCollection = $this->floatForMoneyAnalyzer->analyze(QueryDataCollection::empty());

        // Assert: Should detect that Product.price uses float
        $issuesArray = $issueCollection->toArray();

        self::assertGreaterThan(0, count($issuesArray), 'Should detect float for money');

        $issue = $issuesArray[0];
        self::assertEquals('integrity', $issue->getCategory());
        self::assertStringContainsString('float', strtolower((string) $issue->getTitle()));
        self::assertStringContainsString('price', strtolower((string) $issue->getDescription()));
    }

    #[Test]
    public function it_demonstrates_precision_problems_with_float(): void
    {
        // This test shows WHY float is bad for money

        // Create products with prices that will have precision issues
        $product1 = new Product();
        $product1->setName('Product 1');
        $product1->setPrice(0.1); // 10 cents
        $product1->setStock(10);

        $product2 = new Product();
        $product2->setName('Product 2');
        $product2->setPrice(0.2); // 20 cents
        $product2->setStock(10);

        $this->entityManager->persist($product1);
        $this->entityManager->persist($product2);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Reload from database
        $reloaded1 = $this->entityManager->find(Product::class, $product1->getId());
        self::assertNotNull($reloaded1);
        $reloaded2 = $this->entityManager->find(Product::class, $product2->getId());
        self::assertNotNull($reloaded2);

        // Calculate total: 0.1 + 0.2 should be 0.3
        $total = $reloaded1->getPrice() + $reloaded2->getPrice();

        // Float precision issues may occur (famous 0.1 + 0.2 != 0.3)
        // This is why we shouldn't use float for money!
        self::assertIsFloat($total);

        // Document the problem
        $precision = abs($total - 0.3);
        if ($precision > 0.0000001) {
            self::markTestIncomplete(
                sprintf(
                    'Float precision issue detected: 0.1 + 0.2 = %.17f (expected 0.3)',
                    $total,
                ),
            );
        }
    }

    #[Test]
    public function it_provides_decimal_column_suggestion(): void
    {
        // Act
        $issueCollection = $this->floatForMoneyAnalyzer->analyze(QueryDataCollection::empty());

        // Assert: Should suggest using DECIMAL
        if (count($issueCollection) > 0) {
            $issue = $issueCollection->toArray()[0];
            $suggestion = $issue->getSuggestion();

            self::assertInstanceOf(SuggestionInterface::class, $suggestion, 'Should provide suggestion');

            // The suggestion should mention DECIMAL type
            $suggestionContent = $suggestion->getDescription();
            self::assertStringContainsString('decimal', strtolower((string) $suggestionContent));
        }
    }

    #[Test]
    public function it_demonstrates_correct_approach_would_be_decimal(): void
    {
        // This test documents what the CORRECT approach should be
        // (we can't change Product.php as it's intentionally wrong for testing)

        $connection = $this->entityManager->getConnection();

        // Create a table with DECIMAL (the correct way)
        $connection->executeStatement('
            CREATE TABLE correct_product (
                id INTEGER PRIMARY KEY,
                name VARCHAR(255),
                price DECIMAL(10, 2),  -- Correct: DECIMAL with 2 decimal places
                stock INTEGER
            )
        ');

        // Insert exact values
        $connection->executeStatement("
            INSERT INTO correct_product (name, price, stock)
            VALUES ('Test Product', 19.99, 10)
        ");

        // Retrieve
        $result = $connection->executeQuery("SELECT price FROM correct_product")->fetchOne();

        // With DECIMAL, we get exact precision
        self::assertEquals('19.99', $result, 'DECIMAL preserves exact precision');
    }

    #[Test]
    public function it_detects_issues_in_metadata_not_data(): void
    {
        // Important: The analyzer checks METADATA (entity definitions)
        // not actual data in the database

        // Even with no data in database...
        $countBefore = $this->entityManager->getRepository(Product::class)->count([]);
        self::assertSame(0, $countBefore, 'No products in database');

        // ... the analyzer should still detect the issue
        $issueCollection = $this->floatForMoneyAnalyzer->analyze(QueryDataCollection::empty());

        self::assertGreaterThan(0, count($issueCollection), 'Should detect issue from metadata alone');
    }

    #[Test]
    public function it_checks_all_entities_with_money_related_fields(): void
    {
        // The analyzer should check fields that look like money
        // Common patterns: price, cost, amount, total, subtotal, fee, etc.

        $issueCollection = $this->floatForMoneyAnalyzer->analyze(QueryDataCollection::empty());

        // Verify it detected the price field specifically
        $foundPriceIssue = false;
        foreach ($issueCollection as $issue) {
            if (str_contains(strtolower($issue->getDescription()), 'price')) {
                $foundPriceIssue = true;
                break;
            }
        }

        self::assertTrue($foundPriceIssue, 'Should specifically detect the price field');
    }

    #[Test]
    public function it_shows_real_world_rounding_errors(): void
    {
        // Real-world scenario: calculating totals

        $products = [];
        for ($i = 0; $i < 100; $i++) {
            $product = new Product();
            $product->setName('Product ' . $i);
            $product->setPrice(9.99); // Common price point
            $product->setStock(1);

            $this->entityManager->persist($product);
            $products[] = $product;
        }

        $this->entityManager->flush();

        // Calculate total using float arithmetic
        $total = 0.0;
        foreach ($products as $product) {
            $total += $product->getPrice();
        }

        // Expected: 100 * 9.99 = 999.00
        // But with float, we might get rounding errors
        $expected = 999.00;
        $difference = abs($total - $expected);

        // Document any precision loss
        self::assertLessThan(0.01, $difference, sprintf(
            'Float calculation shows precision issue: %.10f vs %.2f (diff: %.10f)',
            $total,
            $expected,
            $difference,
        ));
    }
}
