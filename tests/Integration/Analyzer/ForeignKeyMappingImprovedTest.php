<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\ForeignKeyMappingAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for improved ForeignKeyMappingAnalyzer.
 * Tests that the analyzer correctly detects real foreign keys
 * while avoiding false positives.
 */
final class ForeignKeyMappingImprovedTest extends TestCase
{
    private ForeignKeyMappingAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/ForeignKeyTest',
        ]);

        $this->analyzer = new ForeignKeyMappingAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_detects_real_foreign_keys_but_avoids_false_positives(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        // Assert
        self::assertIsArray($issuesArray);

        // Should detect real foreign keys from EntityWithRealForeignKeys
        $realForeignKeyIssues = array_filter($issuesArray, function ($issue) {
            $data = $issue->getData();
            $entityClass = $data['entity'] ?? '';
            return str_contains($entityClass, 'EntityWithRealForeignKeys');
        });

        // Should NOT detect false positives from EntityWithFalsePositives
        $falsePositiveIssues = array_filter($issuesArray, function ($issue) {
            $data = $issue->getData();
            $entityClass = $data['entity'] ?? '';
            return str_contains($entityClass, 'EntityWithFalsePositives');
        });

        // We expect to find real foreign key issues
        self::assertGreaterThan(0, count($realForeignKeyIssues),
            'Should detect real foreign key anti-patterns in EntityWithRealForeignKeys');

        // We expect NO false positives
        self::assertEquals(0, count($falsePositiveIssues),
            'Should NOT detect any false positives in EntityWithFalsePositives. ' .
            'Found: ' . implode(', ', $this->getFieldsFromIssues($falsePositiveIssues)));

        // Verify that detected fields are actually foreign keys
        $detectedFields = array_map(function ($issue) {
            $data = $issue->getData();
            return $data['field'] ?? '';
        }, $realForeignKeyIssues);

        $expectedFields = ['userId', 'customerId', 'productId', 'categoryId', 'authorId', 'countryId'];
        foreach ($expectedFields as $expectedField) {
            self::assertContains($expectedField, $detectedFields,
                "Should detect $expectedField as foreign key anti-pattern");
        }
    }

    #[Test]
    public function it_ignores_fields_with_non_fk_patterns(): void
    {
        // This test focuses on the specific patterns we added to avoid false positives
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        $issuesArray = $issues->toArray();
        $falsePositiveFields = [];

        foreach ($issuesArray as $issue) {
            $data = $issue->getData();
            $entityClass = $data['entity'] ?? '';
            $field = $data['field'] ?? '';

            if (str_contains($entityClass, 'EntityWithFalsePositives')) {
                $falsePositiveFields[] = $field;
            }
        }

        $shouldNotDetect = [
            'orderExpirationDays', // Our original false positive
            'userAge',
            'productCount',
            'orderTotal',
            'timeout',
            'limit',
            'maxAmount',
        ];

        foreach ($shouldNotDetect as $field) {
            self::assertNotContains($field, $falsePositiveFields,
                "Should NOT detect $field as foreign key (it's a configuration field)");
        }
    }

    #[Test]
    public function it_detects_simple_entity_references_correctly(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        $issuesArray = $issues->toArray();
        $detectedFields = [];

        foreach ($issuesArray as $issue) {
            $data = $issue->getData();
            $entityClass = $data['entity'] ?? '';
            $field = $data['field'] ?? '';

            if (str_contains($entityClass, 'EntityWithRealForeignKeys')) {
                $detectedFields[] = $field;
            }
        }

        // Should detect these simple entity references
        $shouldDetect = [
            'userId',
            'customerId',
            'productId',
            'authorId',
            'countryId',
            'currencyId',
            'teamId'
        ];

        foreach ($shouldDetect as $field) {
            self::assertContains($field, $detectedFields,
                "Should detect $field as foreign key (simple entity reference)");
        }
    }

    #[Test]
    public function it_handles_compound_field_names_correctly(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        $issuesArray = $issues->toArray();
        $falsePositiveFields = [];

        foreach ($issuesArray as $issue) {
            $data = $issue->getData();
            $entityClass = $data['entity'] ?? '';
            $field = $data['field'] ?? '';

            if (str_contains($entityClass, 'EntityWithFalsePositives')) {
                $falsePositiveFields[] = $field;
            }
        }

        // These compound fields contain entity patterns but are not FKs
        $shouldNotDetect = [
            'orderProcessingTime', // Contains "order" but is about time
            'userSessionTimeout',   // Contains "user" but is about timeout
            'productInventoryCount', // Contains "product" but is about count
            'customerLifetimeValue', // Contains "customer" but is about value
            'orderValidationCode',   // Contains "order" but is about code
        ];

        foreach ($shouldNotDetect as $field) {
            self::assertNotContains($field, $falsePositiveFields,
                "Should NOT detect $field as foreign key (compound field with non-FK meaning)");
        }
    }

    /**
     * Helper method to extract field names from issues for debugging.
     */
    private function getFieldsFromIssues(array $issues): array
    {
        $fields = [];
        foreach ($issues as $issue) {
            $data = $issue->getData();
            $fields[] = $data['field'] ?? 'unknown';
        }
        return $fields;
    }
}