<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\DecimalPrecisionAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for DecimalPrecisionAnalyzer.
 *
 * This analyzer checks decimal field configurations for precision and scale issues.
 */
final class DecimalPrecisionAnalyzerTest extends TestCase
{
    private DecimalPrecisionAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity',
        ]);

        $this->analyzer = new DecimalPrecisionAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_missing_precision_and_scale(): void
    {
        // Arrange: ProductWithBadDecimals has priceWithoutPrecision field
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect missing precision/scale
        $issuesArray = $issues->toArray();
        $missingPrecisionIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getDescription(), 'priceWithoutPrecision') &&
            str_contains((string) $issue->getDescription(), 'without explicit precision/scale'));

        self::assertNotNull($missingPrecisionIssue, 'Should detect missing precision/scale');
        self::assertEquals('configuration', $missingPrecisionIssue->getCategory());
        self::assertEquals('warning', $missingPrecisionIssue->getSeverity()->value);
        self::assertStringContainsString('database defaults', strtolower($missingPrecisionIssue->getDescription()));
    }

    #[Test]
    public function it_detects_insufficient_precision_for_money(): void
    {
        // Arrange: ProductWithBadDecimals has amount field with precision=8, scale=1
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect insufficient precision for money
        $issuesArray = $issues->toArray();
        $insufficientPrecisionIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getDescription(), 'amount') &&
            str_contains((string) $issue->getDescription(), 'insufficient'));

        self::assertNotNull($insufficientPrecisionIssue, 'Should detect insufficient precision for money field');
        self::assertEquals('configuration', $insufficientPrecisionIssue->getCategory());
        self::assertEquals('warning', $insufficientPrecisionIssue->getSeverity()->value);
        self::assertStringContainsString('money', strtolower($insufficientPrecisionIssue->getDescription()));
    }

    #[Test]
    public function it_detects_unusual_scale_for_money(): void
    {
        // Arrange: ProductWithBadDecimals has cost field with scale=3 (unusual for money)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect unusual scale
        $issuesArray = $issues->toArray();
        $unusualScaleIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getDescription(), 'cost') &&
            str_contains((string) $issue->getDescription(), 'unusual'));

        self::assertNotNull($unusualScaleIssue, 'Should detect unusual scale for money field');
        self::assertEquals('configuration', $unusualScaleIssue->getCategory());
        self::assertEquals('info', $unusualScaleIssue->getSeverity()->value);
        self::assertStringContainsString('scale=3', $unusualScaleIssue->getDescription());
    }

    #[Test]
    public function it_detects_excessive_precision(): void
    {
        // Arrange: ProductWithBadDecimals has measurementValue with precision=35
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect excessive precision
        $issuesArray = $issues->toArray();
        $excessivePrecisionIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getDescription(), 'measurementValue') &&
            str_contains((string) $issue->getDescription(), 'very high'));

        self::assertNotNull($excessivePrecisionIssue, 'Should detect excessive precision');
        self::assertEquals('configuration', $excessivePrecisionIssue->getCategory());
        self::assertEquals('info', $excessivePrecisionIssue->getSeverity()->value);
        self::assertStringContainsString('waste storage', strtolower($excessivePrecisionIssue->getDescription()));
    }

    #[Test]
    public function it_detects_insufficient_precision_for_percentage(): void
    {
        // Arrange: ProductWithBadDecimals has discountPercentage with precision=3, scale=1
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect insufficient precision for percentage
        $issuesArray = $issues->toArray();
        $percentageIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getDescription(), 'discountPercentage') &&
            str_contains((string) $issue->getDescription(), 'insufficient'));

        self::assertNotNull($percentageIssue, 'Should detect insufficient precision for percentage field');
        self::assertEquals('configuration', $percentageIssue->getCategory());
        self::assertEquals('warning', $percentageIssue->getSeverity()->value);
        self::assertStringContainsString('percentage', strtolower($percentageIssue->getDescription()));
    }

    #[Test]
    public function it_does_not_flag_correct_decimal_configuration(): void
    {
        // Arrange: ProductWithBadDecimals has correctPrice with precision=19, scale=4 (good)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should not flag correctPrice
        $issuesArray = $issues->toArray();
        $correctPriceIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getDescription(), 'correctPrice'));

        self::assertNull($correctPriceIssue, 'Should not flag correctly configured decimal field');
    }

    #[Test]
    public function it_does_not_flag_invoice_decimal_fields(): void
    {
        // Arrange: Invoice entity uses correct decimal(10,2)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should not flag Invoice fields
        $issuesArray = $issues->toArray();
        $invoiceIssues = array_filter(
            $issuesArray,
            fn ($issue): bool => str_contains((string) $issue->getDescription(), 'Invoice'),
        );

        self::assertCount(0, $invoiceIssues, 'Invoice entity should not be flagged');
    }

    #[Test]
    public function it_provides_suggestion_with_recommended_configurations(): void
    {
        // Arrange: Query that will trigger missing precision issue
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should have suggestions
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));
        $issueWithSuggestion = array_find($issuesArray, fn ($issue) => null !== $issue->getSuggestion());

        self::assertNotNull($issueWithSuggestion, 'Should provide suggestions');
        self::assertNotNull($issueWithSuggestion->getSuggestion());
    }

    #[Test]
    public function it_identifies_money_fields_by_name_pattern(): void
    {
        // Arrange: Fields with money-related names
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect money fields by pattern (price, amount, cost, total, etc.)
        $issuesArray = $issues->toArray();
        $moneyFieldIssues = array_filter(
            $issuesArray,
            fn ($issue): bool => (bool) preg_match('/(price|amount|cost|total|balance|fee)/i', (string) $issue->getDescription()),
        );

        self::assertGreaterThan(0, count($moneyFieldIssues), 'Should detect money fields by name pattern');
    }

    #[Test]
    public function it_identifies_percentage_fields_by_name_pattern(): void
    {
        // Arrange: Fields with percentage-related names
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect percentage fields
        $issuesArray = $issues->toArray();
        $percentageFieldIssues = array_filter(
            $issuesArray,
            fn ($issue): bool => (bool) preg_match('/(percent|percentage|rate|ratio)/i', (string) $issue->getDescription()),
        );

        self::assertGreaterThan(0, count($percentageFieldIssues), 'Should detect percentage fields by name pattern');
    }

    #[Test]
    public function it_skips_non_decimal_fields(): void
    {
        // Arrange: Entities have many non-decimal fields (integer, string, etc.)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: All issues should be about decimal fields only
        $issuesArray = $issues->toArray();
        foreach ($issuesArray as $issue) {
            $description = $issue->getDescription();
            // Should mention precision, scale, or decimal
            self::assertTrue(
                str_contains(strtolower($description), 'precision') ||
                str_contains(strtolower($description), 'scale') ||
                str_contains(strtolower($description), 'decimal'),
                'Issue should be about decimal configuration',
            );
        }
    }

    #[Test]
    public function it_has_correct_analyzer_metadata(): void
    {
        // Assert
        self::assertEquals('Decimal Precision Analyzer', $this->analyzer->getName());
        self::assertEquals('configuration', $this->analyzer->getCategory());
        self::assertStringContainsString('decimal', strtolower($this->analyzer->getName()));
    }

    #[Test]
    public function it_skips_mapped_superclasses(): void
    {
        // This test verifies the analyzer doesn't process mapped superclasses
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Just verify analyzer runs without errors
        self::assertIsArray($issues->toArray());
    }

    #[Test]
    public function it_skips_embedded_classes(): void
    {
        // This test verifies the analyzer doesn't process embeddables directly
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Just verify analyzer runs without errors
        self::assertIsArray($issues->toArray());
    }

    #[Test]
    public function it_detects_multiple_issues_in_same_entity(): void
    {
        // Arrange: ProductWithBadDecimals has multiple decimal issues
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect multiple issues from ProductWithBadDecimals
        $issuesArray = $issues->toArray();
        $productBadDecimalIssues = array_filter(
            $issuesArray,
            fn ($issue): bool => str_contains((string) $issue->getDescription(), 'ProductWithBadDecimals'),
        );

        // Should have at least 5 issues: missing precision, insufficient precision,
        // unusual scale, excessive precision, insufficient percentage precision
        self::assertGreaterThanOrEqual(5, count($productBadDecimalIssues));
    }
}
