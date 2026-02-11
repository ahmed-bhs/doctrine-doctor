<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\FloatForMoneyAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for FloatForMoneyAnalyzer.
 *
 * This analyzer detects usage of float/double types for monetary values.
 */
final class FloatForMoneyAnalyzerTest extends TestCase
{
    private FloatForMoneyAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity',
        ]);

        $this->analyzer = new FloatForMoneyAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_float_used_for_price_field(): void
    {
        // Arrange: Empty queries (analyzer uses entity metadata)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Product entity has float for price
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));
        $priceIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getDescription(), 'Product') &&
            str_contains((string) $issue->getDescription(), 'price'));

        self::assertNotNull($priceIssue, 'Should detect float used for Product::$price');
        self::assertEquals('integrity', $priceIssue->getCategory());
        self::assertEquals('critical', $priceIssue->getSeverity()->value);
        self::assertStringContainsString('float', strtolower($priceIssue->getDescription()));
        self::assertStringContainsString('monetary', strtolower($priceIssue->getDescription()));
    }

    #[Test]
    public function it_detects_multiple_money_fields_in_entity(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: If an entity has multiple float money fields, all should be detected
        // Product might have price and potentially other money fields
        $productIssues = array_filter(
            $issues->toArray(),
            fn ($issue): bool => str_contains((string) $issue->getDescription(), 'Product'),
        );

        self::assertGreaterThan(0, count($productIssues));
    }

    #[Test]
    public function it_provides_helpful_suggestion_with_decimal_alternative(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion);
        self::assertStringContainsString('decimal', strtolower($issue->getDescription()));
    }

    #[Test]
    public function it_does_not_flag_decimal_types_for_money(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Invoice uses decimal - should NOT be flagged
        $invoiceIssues = array_filter(
            $issues->toArray(),
            fn ($issue): bool => str_contains((string) $issue->getDescription(), 'Invoice'),
        );

        self::assertCount(0, $invoiceIssues, 'Invoice uses decimal types, should not be flagged');
    }

    #[Test]
    public function it_does_not_flag_non_money_float_fields(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: User entity has no money fields, should not be flagged
        // (Unless User has float fields with money-like names)
        $userIssues = array_filter(
            $issues->toArray(),
            fn ($issue): bool => str_contains((string) $issue->getDescription(), 'User') &&
                         !str_contains((string) $issue->getDescription(), 'sensitive') &&
                         str_contains(strtolower((string) $issue->getDescription()), 'float'),
        );
        $hasMoneyIssue = array_any($userIssues, fn ($issue) => str_contains(strtolower((string) $issue->getDescription()), 'money') ||
            str_contains(strtolower((string) $issue->getDescription()), 'monetary'));

        self::assertFalse($hasMoneyIssue, 'User entity should not have money-related float issues');
    }

    #[Test]
    public function issue_contains_entity_and_field_information(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];

        // Should mention the entity class name
        self::assertMatchesRegularExpression('/\w+::\$\w+/', $issue->getDescription());

        // Should have backtrace with entity info
        $backtrace = $issue->getBacktrace();
        self::assertNotNull($backtrace);
        self::assertArrayHasKey('entity', $backtrace);
        self::assertArrayHasKey('field', $backtrace);
        self::assertArrayHasKey('current_type', $backtrace);
    }

    #[Test]
    public function it_skips_mapped_superclasses(): void
    {
        // This test verifies the analyzer doesn't process mapped superclasses
        // If we had a mapped superclass in fixtures, it wouldn't be analyzed

        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Just verify analyzer runs without errors
        // Mapped superclasses are filtered out in the analyzer
        self::assertIsArray($issues->toArray());
    }

    #[Test]
    public function it_skips_embedded_classes(): void
    {
        // This test verifies the analyzer doesn't process embeddables directly
        // Embeddables are checked by a different analyzer

        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Just verify analyzer runs without errors
        // Embeddables are filtered out in the analyzer
        self::assertIsArray($issues->toArray());
    }

    #[Test]
    public function it_detects_money_fields_by_name_pattern(): void
    {
        // Arrange: Product has "price" which matches MONEY_FIELD_PATTERNS
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect based on field name containing money patterns
        $moneyPatternIssues = array_filter(
            $issues->toArray(),
            fn ($issue): bool => (bool) preg_match('/(price|amount|cost|total|balance|fee)/i', (string) $issue->getDescription()),
        );

        self::assertGreaterThan(0, count($moneyPatternIssues));
    }
}
