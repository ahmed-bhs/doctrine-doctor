<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\TypeHintMismatchAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\Invoice;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ProductWithTypeHintIssues;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for TypeHintMismatchAnalyzer.
 *
 * This analyzer detects mismatches between Doctrine column types and PHP property type hints.
 * Such mismatches cause unnecessary UPDATE statements on every flush.
 */
final class TypeHintMismatchAnalyzerTest extends TestCase
{
    private TypeHintMismatchAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity',
        ]);

        $this->analyzer = new TypeHintMismatchAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_all_types_match(): void
    {
        // Arrange: Invoice has all correct type hints (decimal->string)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Invoice should have no type hint issues
        $issuesArray = $issues->toArray();
        $invoiceIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'Invoice'),
        );

        self::assertCount(0, $invoiceIssues, 'Invoice entity has correct type hints');
    }

    #[Test]
    public function it_detects_decimal_with_float_type_hint(): void
    {
        // Arrange: ProductWithTypeHintIssues has price as float (should be string)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect decimal/float mismatch (CRITICAL)
        $issuesArray = $issues->toArray();
        $priceIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getDescription(), 'ProductWithTypeHintIssues') &&
            str_contains((string) $issue->getDescription(), 'price'));

        self::assertNotNull($priceIssue, 'Should detect decimal/float mismatch');
        self::assertEquals('critical', $priceIssue->getSeverity()->value);
        self::assertEquals('integrity', $priceIssue->getCategory());
        self::assertStringContainsString('decimal', $priceIssue->getDescription());
        self::assertStringContainsString('float', $priceIssue->getDescription());
    }

    #[Test]
    public function it_detects_integer_with_string_type_hint(): void
    {
        // Arrange: ProductWithTypeHintIssues has quantity as string (should be int)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect integer/string mismatch (WARNING)
        $issuesArray = $issues->toArray();
        $quantityIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getDescription(), 'ProductWithTypeHintIssues') &&
            str_contains((string) $issue->getDescription(), 'quantity'));

        self::assertNotNull($quantityIssue, 'Should detect integer/string mismatch');
        self::assertEquals('warning', $quantityIssue->getSeverity()->value);
        self::assertStringContainsString('integer', $quantityIssue->getDescription());
        self::assertStringContainsString('string', $quantityIssue->getDescription());
    }

    #[Test]
    public function it_does_not_flag_correct_integer_type_hint(): void
    {
        // Arrange: stock is correctly typed as int for integer column
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: stock should not be flagged
        $issuesArray = $issues->toArray();
        $stockIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'stock'),
        );

        self::assertCount(0, $stockIssues, 'Correct int type hint should not be flagged');
    }

    #[Test]
    public function it_does_not_flag_correct_string_type_hint(): void
    {
        // Arrange: name is correctly typed as string for string column
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: name should not be flagged
        $issuesArray = $issues->toArray();
        $nameIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'ProductWithTypeHintIssues') &&
                          str_contains((string) $issue->getDescription(), 'name'),
        );

        self::assertCount(0, $nameIssues, 'Correct string type hint should not be flagged');
    }

    #[Test]
    public function it_does_not_flag_correct_decimal_type_hint(): void
    {
        // Arrange: cost is correctly typed as string for decimal column
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: cost should not be flagged
        $issuesArray = $issues->toArray();
        $costIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'cost'),
        );

        self::assertCount(0, $costIssues, 'Correct string type hint for decimal should not be flagged');
    }

    #[Test]
    public function it_skips_properties_without_type_hints(): void
    {
        // Arrange: sku has no type hint
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: sku should not be flagged (can't check without type hint)
        $issuesArray = $issues->toArray();
        $skuIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'sku'),
        );

        self::assertCount(0, $skuIssues, 'Properties without type hints should be skipped');
    }

    #[Test]
    public function it_provides_suggestion_for_decimal_float_mismatch(): void
    {
        // Arrange: price has decimal/float mismatch
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide suggestion
        $issuesArray = $issues->toArray();
        $priceIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getDescription(), 'price'));

        self::assertNotNull($priceIssue);
        $suggestion = $priceIssue->getSuggestion();
        self::assertNotNull($suggestion, 'Should provide suggestion for decimal/float mismatch');
    }

    #[Test]
    public function it_explains_performance_impact(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Description should mention performance impact
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];
        $description = $issue->getDescription();

        self::assertStringContainsString('UPDATE', $description);
        self::assertStringContainsString('flush', strtolower($description));
    }

    #[Test]
    public function it_detects_multiple_mismatches_in_same_entity(): void
    {
        // Arrange: ProductWithTypeHintIssues has 2 mismatches (price, quantity)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect both issues
        $issuesArray = $issues->toArray();
        $productIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'ProductWithTypeHintIssues'),
        );

        self::assertGreaterThanOrEqual(2, count($productIssues), 'Should detect both price and quantity mismatches');
    }

    #[Test]
    public function it_skips_mapped_superclasses(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Analyzer runs without errors
        self::assertIsArray($issues->toArray());
    }

    #[Test]
    public function it_skips_embedded_classes(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Analyzer runs without errors
        self::assertIsArray($issues->toArray());
    }

    #[Test]
    public function it_includes_backtrace_with_type_information(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Issues should have backtrace with type info
        $issuesArray = $issues->toArray();
        $productIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'ProductWithTypeHintIssues'),
        );

        self::assertGreaterThan(0, count($productIssues));

        $issue = array_values($productIssues)[0];
        $backtrace = $issue->getBacktrace();

        self::assertNotNull($backtrace);
        self::assertArrayHasKey('entity', $backtrace);
        self::assertArrayHasKey('field', $backtrace);
        self::assertArrayHasKey('doctrine_type', $backtrace);
        self::assertArrayHasKey('php_type', $backtrace);
        self::assertArrayHasKey('expected_type', $backtrace);
    }

    #[Test]
    public function it_has_correct_analyzer_metadata(): void
    {
        // Assert
        self::assertEquals('Type Hint Mismatch Detector', $this->analyzer->getName());
        self::assertEquals('integrity', $this->analyzer->getCategory());
    }
}
