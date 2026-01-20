<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\NamingConventionAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for NamingConventionAnalyzer.
 *
 * This analyzer detects violations of database naming conventions:
 *
 * 1. Tables: snake_case, singular, no SQL reserved keywords, no special characters
 * 2. Columns: snake_case, no SQL reserved keywords, no special characters
 * 3. Foreign Keys: snake_case, _id suffix required
 * 4. Indexes: idx_ prefix for regular indexes, uniq_ prefix for unique constraints, snake_case
 *
 * Note: Index detection now works without database schema by reading PHP attributes directly.
 */
final class NamingConventionAnalyzerTest extends TestCase
{
    private NamingConventionAnalyzer $analyzer;

    protected function setUp(): void
    {
        // Create EntityManager with naming convention test entities
        // No need to create database schema - analyzer reads attributes directly
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity/NamingConventionTest',
        ]);

        $this->analyzer = new NamingConventionAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(), // @phpstan-ignore-line argument.type
        );
    }

    #[Test]
    public function it_detects_table_not_in_snake_case(): void
    {
        // Arrange: EntityWithTableCamelCase has table name "UserProfile" (camelCase)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $tableIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'EntityWithTableCamelCase')
                && str_contains($data['table_name'] ?? '', 'UserProfile')
                && ($data['violation_type'] ?? '') === 'not_snake_case';
        });

        self::assertCount(1, $tableIssues, 'Should detect table not in snake_case');

        $issue = reset($tableIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('UserProfile', $data['table_name']);
        self::assertEquals('user_profile', $data['suggested_name']);
        self::assertEquals('warning', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_column_not_in_snake_case(): void
    {
        // Arrange: EntityWithColumnCamelCase has columns "firstName" and "lastName" (camelCase)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $columnIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'EntityWithColumnCamelCase')
                && isset($data['column_name'])
                && ($data['violation_type'] ?? '') === 'not_snake_case';
        });

        self::assertGreaterThanOrEqual(2, count($columnIssues), 'Should detect at least 2 columns not in snake_case');

        // Check firstName
        $firstNameIssues = array_filter($columnIssues, static function ($issue) {
            $data = $issue->getData();
            return 'firstName' === $data['column_name'];
        });
        self::assertCount(1, $firstNameIssues);

        $issue = reset($firstNameIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('first_name', $data['suggested_name']);
        self::assertEquals('warning', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_plural_table_names(): void
    {
        // Arrange: EntityWithPluralTable has table name "products" (plural)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $pluralIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'EntityWithPluralTable')
                && ($data['violation_type'] ?? '') === 'plural';
        });

        self::assertCount(1, $pluralIssues, 'Should detect plural table name');

        $issue = reset($pluralIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('products', $data['table_name']);
        self::assertEquals('product', $data['suggested_name']);
        self::assertEquals('info', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_sql_reserved_keywords_in_tables(): void
    {
        // Arrange: EntityWithReservedKeyword has table name "order" (SQL reserved keyword)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $reservedIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'EntityWithReservedKeyword')
                && ($data['table_name'] ?? '') === 'order'
                && ($data['violation_type'] ?? '') === 'reserved_keyword';
        });

        self::assertCount(1, $reservedIssues, 'Should detect SQL reserved keyword in table name');

        $issue = reset($reservedIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('orders', $data['suggested_name']);
        self::assertEquals('info', $issue->getSeverity()->value, 'Reserved keywords should be INFO level (Doctrine handles them)');
    }

    #[Test]
    public function it_detects_sql_reserved_keywords_in_columns(): void
    {
        // Arrange: EntityWithReservedKeyword has columns "user" and "key" (SQL reserved keywords)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $reservedColumnIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'EntityWithReservedKeyword')
                && isset($data['column_name'])
                && ($data['violation_type'] ?? '') === 'reserved_keyword';
        });

        self::assertGreaterThanOrEqual(2, count($reservedColumnIssues), 'Should detect at least 2 columns with reserved keywords');

        // Check "user" column
        $userColumnIssues = array_filter($reservedColumnIssues, static function ($issue) {
            $data = $issue->getData();
            return 'user' === $data['column_name'];
        });
        self::assertCount(1, $userColumnIssues);

        $issue = reset($userColumnIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertEquals('info', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_special_characters_in_table_names(): void
    {
        // Arrange: EntityWithSpecialCharacters has table name "special-table" (contains hyphen)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $specialCharIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'EntityWithSpecialCharacters')
                && str_contains($data['table_name'] ?? '', 'special-table')
                && ($data['violation_type'] ?? '') === 'special_characters';
        });

        self::assertCount(1, $specialCharIssues, 'Should detect special characters in table name');

        $issue = reset($specialCharIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('special_table', $data['suggested_name']);
    }

    #[Test]
    public function it_detects_special_characters_in_column_names(): void
    {
        // Arrange: EntityWithSpecialCharacters has columns with hyphens and @ symbols
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $specialCharColumnIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'EntityWithSpecialCharacters')
                && isset($data['column_name'])
                && ($data['violation_type'] ?? '') === 'special_characters';
        });

        self::assertGreaterThanOrEqual(2, count($specialCharColumnIssues), 'Should detect at least 2 columns with special characters');
    }

    #[Test]
    public function it_detects_foreign_key_not_in_snake_case(): void
    {
        // Arrange: EntityWithBadForeignKey has FK "customerId" (camelCase)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $fkSnakeCaseIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'EntityWithBadForeignKey')
                && ($data['fk_column'] ?? '') === 'customerId'
                && ($data['violation_type'] ?? '') === 'not_snake_case';
        });

        self::assertCount(1, $fkSnakeCaseIssues, 'Should detect FK not in snake_case');

        $issue = reset($fkSnakeCaseIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('customer_id', $data['suggested_name']);
        self::assertEquals('critical', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_foreign_key_missing_id_suffix(): void
    {
        // Arrange: EntityWithBadForeignKey has FK "product" (missing _id suffix)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $fkSuffixIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'EntityWithBadForeignKey')
                && ($data['fk_column'] ?? '') === 'product'
                && ($data['violation_type'] ?? '') === 'missing_id_suffix';
        });

        self::assertCount(1, $fkSuffixIssues, 'Should detect FK missing _id suffix');

        $issue = reset($fkSuffixIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('product_id', $data['suggested_name']);
        self::assertEquals('critical', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_index_missing_idx_prefix(): void
    {
        // Arrange: EntityWithBadIndexes has index "email_index" (should be "idx_email")
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $idxPrefixIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'EntityWithBadIndexes')
                && ($data['index_name'] ?? '') === 'email_index'
                && ($data['violation_type'] ?? '') === 'missing_idx_prefix';
        });

        self::assertCount(1, $idxPrefixIssues, 'Should detect index missing idx_ prefix');

        $issue = reset($idxPrefixIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('idx_email', $data['suggested_name']);
        self::assertEquals('info', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_index_not_in_snake_case(): void
    {
        // Arrange: EntityWithBadIndexes has index "StatusCreatedAt" (not snake_case)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $indexSnakeCaseIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'EntityWithBadIndexes')
                && ($data['index_name'] ?? '') === 'StatusCreatedAt'
                && ($data['violation_type'] ?? '') === 'not_snake_case';
        });

        self::assertCount(1, $indexSnakeCaseIssues, 'Should detect index not in snake_case');

        $issue = reset($indexSnakeCaseIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('status_created_at', $data['suggested_name']);
    }

    #[Test]
    public function it_detects_unique_constraint_missing_uniq_prefix(): void
    {
        // Arrange: EntityWithBadIndexes has unique constraint "email_unique" and "skuConstraint"
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $uniqPrefixIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'EntityWithBadIndexes')
                && isset($data['index_name'])
                && ($data['violation_type'] ?? '') === 'missing_uniq_prefix';
        });

        self::assertGreaterThanOrEqual(2, count($uniqPrefixIssues), 'Should detect at least 2 unique constraints missing uniq_ prefix');

        // Check email_unique
        $emailUniqIssues = array_filter($uniqPrefixIssues, static function ($issue) {
            $data = $issue->getData();
            return 'email_unique' === $data['index_name'];
        });
        self::assertCount(1, $emailUniqIssues);

        $issue = reset($emailUniqIssues);

        assert($issue instanceof \AhmedBhs\DoctrineDoctor\Issue\IssueInterface);
        self::assertNotFalse($issue);
        $data = $issue->getData();
        self::assertEquals('uniq_email', $data['suggested_name']);
    }

    #[Test]
    public function it_does_not_flag_correct_naming(): void
    {
        // Arrange: EntityWithCorrectNaming has all correct conventions
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $correctEntityIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'EntityWithCorrectNaming');
        });

        self::assertCount(0, $correctEntityIssues, 'Correct naming conventions should not trigger issues');
    }

    #[Test]
    public function it_does_not_flag_compound_reserved_keywords(): void
    {
        // Arrange: EntityWithCompoundReservedKeyword has "app_user" and "user_name"
        // (compound names with underscores should NOT trigger reserved keyword issues)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $compoundIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'EntityWithCompoundReservedKeyword')
                && ($data['violation_type'] ?? '') === 'reserved_keyword';
        });

        self::assertCount(0, $compoundIssues, 'Compound names with underscores should not trigger reserved keyword issues');
    }

    #[Test]
    public function it_provides_correct_severity_for_violations(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Different violation types should have appropriate severities
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            $data = $issue->getData();
            $severity = $issue->getSeverity()->value;

            switch ($data['violation_type'] ?? '') {
                case 'not_snake_case':
                    // Table and column snake_case violations are WARNING
                    // Index snake_case violations are INFO
                    if (isset($data['table_name']) && !isset($data['index_name'])) {
                        self::assertEquals('warning', $severity, 'Table not in snake_case should be WARNING severity');
                    } elseif (isset($data['column_name'])) {
                        self::assertEquals('warning', $severity, 'Column not in snake_case should be WARNING severity');
                    } elseif (isset($data['index_name'])) {
                        self::assertEquals('info', $severity, 'Index not in snake_case should be INFO severity');
                    }
                    break;

                case 'plural':
                    self::assertEquals('info', $severity, 'Plural table names should be INFO severity');
                    break;

                case 'reserved_keyword':
                    self::assertEquals('info', $severity, 'Reserved keywords should be INFO severity');
                    break;

                case 'missing_id_suffix':
                case 'not_snake_case':
                    // Foreign key violations are CRITICAL
                    if (isset($data['fk_column'])) {
                        self::assertEquals('critical', $severity, 'FK violations should be CRITICAL severity');
                    }
                    break;

                case 'missing_idx_prefix':
                case 'missing_uniq_prefix':
                    self::assertEquals('info', $severity, 'Index naming violations should be INFO severity');
                    break;
            }
        }
    }

    #[Test]
    public function it_handles_entity_without_naming_issues(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should find issues from bad entities, but not from correct one
        $issuesArray = $issues->toArray();
        $correctEntityIssues = array_filter($issuesArray, static function ($issue) {
            $data = $issue->getData();
            return str_contains($data['entity'] ?? '', 'EntityWithCorrectNaming');
        });

        self::assertCount(0, $correctEntityIssues);
    }

    #[Test]
    public function it_uses_generator_pattern(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: IssueCollection uses generator pattern
        self::assertInstanceOf(\Generator::class, $issues->getIterator());
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        // Assert
        self::assertInstanceOf(\AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface::class, $this->analyzer);
    }

    #[Test]
    public function it_has_analyzer_name(): void
    {
        // Assert
        $name = $this->analyzer->getName();

        self::assertNotEmpty($name);
        self::assertStringContainsString('Naming', $name);
        self::assertStringContainsString('Convention', $name);
    }

    #[Test]
    public function it_has_analyzer_description(): void
    {
        // Assert
        $description = $this->analyzer->getDescription();

        self::assertNotEmpty($description);
        self::assertStringContainsString('naming', strtolower($description));
        self::assertStringContainsString('conventions', strtolower($description));
    }

    #[Test]
    public function it_includes_entity_information_in_issues(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            $data = $issue->getData();

            self::assertArrayHasKey('entity', $data, 'Should include entity information');
            self::assertNotEmpty($data['entity'], 'Entity should not be empty');
            self::assertArrayHasKey('violation_type', $data, 'Should include violation type');
            self::assertNotEmpty($data['violation_type'], 'Violation type should not be empty');
            self::assertArrayHasKey('suggested_name', $data, 'Should include suggested name');
            self::assertNotEmpty($data['suggested_name'], 'Suggested name should not be empty');
        }
    }

    #[Test]
    public function it_provides_suggestions_for_all_violations(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: All detected issues should have suggestions
        $issuesArray = $issues->toArray();

        self::assertGreaterThan(0, count($issuesArray), 'Should detect at least one issue');

        foreach ($issuesArray as $issue) {
            $suggestion = $issue->getSuggestion();
            self::assertNotNull($suggestion, 'Each violation should have a suggestion');
        }
    }

    #[Test]
    public function it_detects_multiple_violations_on_same_entity(): void
    {
        // Arrange: Some entities have multiple naming violations
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();

        // Group by entity
        $entitiesWithIssues = [];
        foreach ($issuesArray as $issue) {
            $data = $issue->getData();
            $entity = $data['entity'] ?? '';
            if (!isset($entitiesWithIssues[$entity])) {
                $entitiesWithIssues[$entity] = 0;
            }
            $entitiesWithIssues[$entity]++;
        }

        // At least one entity should have multiple issues
        $maxIssuesPerEntity = max($entitiesWithIssues); // @phpstan-ignore-line argument.type
        self::assertGreaterThan(1, $maxIssuesPerEntity, 'At least one entity should have multiple issues');
    }

    #[Test]
    public function it_checks_all_naming_aspects(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect different types of violations
        $issuesArray = $issues->toArray();

        $violationTypes = array_map(fn ($issue) => $issue->getData()['violation_type'] ?? '', $issuesArray);
        $uniqueViolationTypes = array_unique($violationTypes);

        // Should have detected various violation types
        self::assertContains('not_snake_case', $uniqueViolationTypes, 'Should detect not_snake_case');
        self::assertContains('plural', $uniqueViolationTypes, 'Should detect plural tables');
        self::assertContains('reserved_keyword', $uniqueViolationTypes, 'Should detect reserved keywords');
        self::assertContains('special_characters', $uniqueViolationTypes, 'Should detect special characters');
        self::assertContains('missing_id_suffix', $uniqueViolationTypes, 'Should detect missing _id suffix');
        self::assertContains('missing_idx_prefix', $uniqueViolationTypes, 'Should detect missing idx_ prefix');
        self::assertContains('missing_uniq_prefix', $uniqueViolationTypes, 'Should detect missing uniq_ prefix');
    }

    #[Test]
    public function it_handles_empty_query_collection(): void
    {
        // Arrange: Empty collection (analyzer doesn't use queries, but tests interface)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should still analyze entities (not query-based)
        self::assertIsObject($issues);
        self::assertIsArray($issues->toArray());
    }

    #[Test]
    public function it_provides_issue_titles(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();

        foreach ($issuesArray as $issue) {
            $title = $issue->getTitle();
            self::assertNotEmpty($title, 'Each issue should have a title');

            // Title should describe the violation
            $data = $issue->getData();
            $violationType = $data['violation_type'] ?? '';

            switch ($violationType) {
                case 'not_snake_case':
                    self::assertStringContainsString('snake_case', $title);
                    break;
                case 'plural':
                    self::assertStringContainsString('Singular', $title);
                    break;
                case 'reserved_keyword':
                    self::assertStringContainsString('Reserved', $title);
                    break;
                case 'special_characters':
                    self::assertStringContainsString('Special', $title);
                    break;
                case 'missing_id_suffix':
                    self::assertStringContainsString('_id', $title);
                    break;
                case 'missing_idx_prefix':
                    self::assertStringContainsString('idx_', $title);
                    break;
                case 'missing_uniq_prefix':
                    self::assertStringContainsString('uniq_', $title);
                    break;
            }
        }
    }
}
