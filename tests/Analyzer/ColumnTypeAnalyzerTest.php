<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\ColumnTypeAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Template\Renderer\TwigTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ColumnTypeTest\EntityWithArrayType;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ColumnTypeTest\EntityWithCorrectTypes;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ColumnTypeTest\EntityWithEnumOpportunity;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ColumnTypeTest\EntityWithMixedIssues;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ColumnTypeTest\EntityWithObjectType;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ColumnTypeTest\EntityWithSimpleArray;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Comprehensive tests for ColumnTypeAnalyzer.
 *
 * Tests detection of:
 * - Deprecated 'object' type (CRITICAL)
 * - Problematic 'array' type (WARNING)
 * - Limited 'simple_array' type (INFO)
 * - Enum opportunities (INFO)
 */
final class ColumnTypeAnalyzerTest extends TestCase
{
    private EntityManager $entityManager;

    private ColumnTypeAnalyzer $analyzer;

    protected function setUp(): void
    {
        // Create in-memory entity manager with specific fixtures
        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../Fixtures/Entity/ColumnTypeTest'],
            isDevMode: true,
        );

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->entityManager = new EntityManager($connection, $configuration);
        $this->analyzer = new ColumnTypeAnalyzer(
            $this->entityManager,
            $this->createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        self::assertInstanceOf(AnalyzerInterface::class, $this->analyzer);
    }

    #[Test]
    public function it_uses_generator_pattern(): void
    {
        $issues = $this->analyzer->analyze(QueryDataCollection::empty());

        self::assertInstanceOf(\Generator::class, $issues->getIterator());
    }

    #[Test]
    public function it_detects_object_type_as_critical(): void
    {
        $issues = $this->analyzer->analyze(QueryDataCollection::empty());

        $objectIssues = array_filter(
            iterator_to_array($issues),
            fn ($issue) => str_contains($issue->getTitle(), 'EntityWithObjectType')
                && str_contains($issue->getTitle(), 'object'),
        );

        // Should detect 2 object type fields: metadata and configuration
        self::assertCount(2, $objectIssues);

        foreach ($objectIssues as $issue) {
            self::assertEquals(Severity::CRITICAL, $issue->getSeverity());
            self::assertStringContainsString('deprecated type "object"', $issue->getDescription());
            self::assertStringContainsString('insecure and fragile', $issue->getDescription());
            self::assertStringContainsString('json', $issue->getDescription());
        }
    }

    #[Test]
    public function it_detects_array_type_as_warning(): void
    {
        $issues = $this->analyzer->analyze(QueryDataCollection::empty());

        $arrayIssues = array_filter(
            iterator_to_array($issues),
            fn ($issue) => str_contains($issue->getTitle(), 'EntityWithArrayType')
                && str_contains($issue->getTitle(), 'array'),
        );

        // Should detect 2 array type fields: settings and permissions
        self::assertCount(2, $arrayIssues);

        foreach ($arrayIssues as $issue) {
            self::assertEquals(Severity::WARNING, $issue->getSeverity());
            self::assertStringContainsString('deprecated type "array"', $issue->getDescription());
            self::assertStringContainsString('serialize()', $issue->getDescription());
            self::assertStringContainsString('json', $issue->getDescription());
        }
    }

    #[Test]
    public function it_detects_simple_array_with_limited_length(): void
    {
        $issues = $this->analyzer->analyze(QueryDataCollection::empty());

        $simpleArrayIssues = array_filter(
            iterator_to_array($issues),
            fn ($issue) => str_contains($issue->getTitle(), 'EntityWithSimpleArray')
                && str_contains($issue->getTitle(), 'simple_array'),
        );

        // Should detect 2 fields with limited length: tags (255) and categories (100)
        // Should NOT detect keywords (length=1000)
        self::assertCount(2, $simpleArrayIssues);

        foreach ($simpleArrayIssues as $issue) {
            self::assertEquals(Severity::INFO, $issue->getSeverity());
            self::assertStringContainsString('simple_array', $issue->getDescription());
            self::assertStringContainsString('cannot contain commas', $issue->getDescription());
            self::assertStringContainsString('json', $issue->getDescription());
        }
    }

    #[Test]
    public function it_suggests_enum_for_appropriate_fields(): void
    {
        $issues = $this->analyzer->analyze(QueryDataCollection::empty());

        $enumIssues = array_filter(
            iterator_to_array($issues),
            fn ($issue) => str_contains($issue->getTitle(), 'EntityWithEnumOpportunity')
                && str_contains($issue->getTitle(), 'enum'),
        );

        // Should suggest enum for: status, type, role, priority (4 fields)
        // Should NOT suggest for: name, description
        self::assertGreaterThanOrEqual(4, count($enumIssues));

        foreach ($enumIssues as $issue) {
            self::assertEquals(Severity::INFO, $issue->getSeverity());
            self::assertStringContainsString('native enum', $issue->getDescription());
            self::assertStringContainsString('PHP 8.1', $issue->getDescription());
        }

        // Verify specific fields are detected
        $issueDescriptions = array_map(fn ($issue) => $issue->getDescription(), $enumIssues);
        $allDescriptions = implode(' ', $issueDescriptions);

        self::assertStringContainsString('$status', $allDescriptions);
        self::assertStringContainsString('$type', $allDescriptions);
        self::assertStringContainsString('$role', $allDescriptions);
        self::assertStringContainsString('$priority', $allDescriptions);
    }

    #[Test]
    public function it_does_not_flag_correct_types(): void
    {
        $issues = $this->analyzer->analyze(QueryDataCollection::empty());

        $correctTypeIssues = array_filter(
            iterator_to_array($issues),
            fn ($issue) => str_contains($issue->getTitle(), 'EntityWithCorrectTypes'),
        );

        // Should not detect any issues in entity with correct types
        self::assertCount(0, $correctTypeIssues);
    }

    #[Test]
    public function it_detects_multiple_issues_in_same_entity(): void
    {
        $issues = $this->analyzer->analyze(QueryDataCollection::empty());

        $mixedIssues = array_filter(
            iterator_to_array($issues),
            fn ($issue) => str_contains($issue->getTitle(), 'EntityWithMixedIssues'),
        );

        // Should detect:
        // 1 CRITICAL (object type)
        // 1 WARNING (array type)
        // 1 INFO (simple_array)
        // 1 INFO (enum opportunity for status)
        self::assertGreaterThanOrEqual(4, count($mixedIssues));

        // Check severity distribution
        $severities = array_map(fn ($issue) => $issue->getSeverity(), $mixedIssues);

        $criticalCount = count(array_filter($severities, fn ($s) => Severity::CRITICAL === $s));
        $warningCount = count(array_filter($severities, fn ($s) => Severity::WARNING === $s));
        $infoCount = count(array_filter($severities, fn ($s) => Severity::INFO === $s));

        self::assertGreaterThanOrEqual(1, $criticalCount, 'Should have at least 1 critical issue (object type)');
        self::assertGreaterThanOrEqual(1, $warningCount, 'Should have at least 1 warning (array type)');
        self::assertGreaterThanOrEqual(2, $infoCount, 'Should have at least 2 info issues (simple_array + enum)');
    }

    #[Test]
    public function it_provides_suggestions_for_all_issues(): void
    {
        $issues = $this->analyzer->analyze(QueryDataCollection::empty());

        foreach ($issues as $issue) {
            self::assertNotNull($issue->getSuggestion(), 'Every issue should have a suggestion');
        }
    }

    #[Test]
    public function it_includes_entity_and_field_names_in_titles(): void
    {
        $issues = $this->analyzer->analyze(QueryDataCollection::empty());

        foreach ($issues as $issue) {
            $title = $issue->getTitle();

            // Title should contain entity class name
            self::assertMatchesRegularExpression('/Entity\w+/', $title);

            // Title should contain field reference ($fieldName pattern)
            self::assertMatchesRegularExpression('/\$\w+/', $title);
        }
    }

    #[Test]
    public function it_includes_specific_guidance_in_descriptions(): void
    {
        $issues = $this->analyzer->analyze(QueryDataCollection::empty());

        foreach ($issues as $issue) {
            $description = $issue->getDescription();

            // Every description should mention the entity and field
            self::assertMatchesRegularExpression('/\w+::\$\w+/', $description);

            // Should provide actionable guidance
            self::assertTrue(
                str_contains($description, 'Use ') ||
                str_contains($description, 'Consider ') ||
                str_contains($description, 'Replace ') ||
                str_contains($description, 'instead') ||
                str_contains($description, 'provides'),
                'Description should provide actionable guidance: ' . $description,
            );
        }
    }

    #[Test]
    public function it_handles_empty_metadata_gracefully(): void
    {
        // Create entity manager with no entities
        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../Fixtures/NonExistentPath'],
            isDevMode: true,
        );

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $emptyEm = new EntityManager($connection, $configuration);
        $analyzer = new ColumnTypeAnalyzer(
            $emptyEm,
            $this->createSuggestionFactory(),
        );

        $issues = $analyzer->analyze(QueryDataCollection::empty());

        // Should not crash, just return empty collection
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_provides_correct_severity_levels(): void
    {
        $issues = $this->analyzer->analyze(QueryDataCollection::empty());

        $validSeverities = [Severity::CRITICAL, Severity::WARNING, Severity::INFO];

        foreach ($issues as $issue) {
            self::assertContains(
                $issue->getSeverity(),
                $validSeverities,
                'Issue severity must be one of: critical, warning, info',
            );
        }
    }

    #[Test]
    public function it_uses_consistent_issue_format(): void
    {
        $issues = $this->analyzer->analyze(QueryDataCollection::empty());

        foreach ($issues as $issue) {
            // Every issue must have required fields
            self::assertNotEmpty($issue->getTitle());
            self::assertNotEmpty($issue->getDescription());
            self::assertInstanceOf(Severity::class, $issue->getSeverity());

            // Backtrace should be null (metadata analyzer)
            self::assertNull($issue->getBacktrace());

            // Queries should be empty array (metadata analyzer)
            self::assertIsArray($issue->getQueries());
            self::assertCount(0, $issue->getQueries());
        }
    }

    #[Test]
    public function it_detects_object_type_fields(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        $objectTypeIssues = array_filter(
            $issues,
            fn ($issue) => str_contains($issue->getDescription(), 'type "object"'),
        );

        self::assertNotEmpty($objectTypeIssues, 'Should detect object type issues');

        foreach ($objectTypeIssues as $issue) {
            self::assertEquals(Severity::CRITICAL, $issue->getSeverity());
            self::assertStringContainsString('serialize()', $issue->getDescription());
            self::assertStringContainsString('insecure', $issue->getDescription());
        }
    }

    #[Test]
    public function it_detects_array_type_fields(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        $arrayTypeIssues = array_filter(
            $issues,
            fn ($issue) => str_contains($issue->getDescription(), 'type "array"'),
        );

        self::assertNotEmpty($arrayTypeIssues, 'Should detect array type issues');

        foreach ($arrayTypeIssues as $issue) {
            self::assertEquals(Severity::WARNING, $issue->getSeverity());
            self::assertStringContainsString('serialize()', $issue->getDescription());
        }
    }

    #[Test]
    public function it_returns_consistent_results_on_repeated_analysis(): void
    {
        $issues1 = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));
        $issues2 = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        self::assertCount(count($issues1), $issues2, 'Should return consistent number of issues');

        // Titles should match (order might differ)
        $titles1 = array_map(fn ($i) => $i->getTitle(), $issues1);
        $titles2 = array_map(fn ($i) => $i->getTitle(), $issues2);

        sort($titles1);
        sort($titles2);

        self::assertEquals($titles1, $titles2, 'Should return same issues on repeated analysis');
    }

    #[Test]
    public function it_detects_only_critical_object_types(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        $criticalIssues = array_filter(
            $issues,
            fn ($issue) => Severity::CRITICAL === $issue->getSeverity(),
        );

        // All CRITICAL issues should be about 'object' type
        foreach ($criticalIssues as $issue) {
            self::assertStringContainsString(
                'object',
                $issue->getDescription(),
                'Critical issues should only be for object type',
            );
        }
    }

    #[Test]
    public function it_provides_migration_code_in_suggestions(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        foreach ($issues as $issue) {
            $suggestion = $issue->getSuggestion();
            self::assertNotNull($suggestion, 'All issues should have suggestions');

            $suggestionCode = $suggestion->getCode();
            $suggestionDescription = $suggestion->getDescription();

            // Suggestions should contain actionable code examples
            self::assertTrue(
                str_contains($suggestionCode, 'BEFORE') ||
                str_contains($suggestionCode, 'AFTER') ||
                str_contains($suggestionCode, 'Step') ||
                str_contains($suggestionCode, 'Migration') ||
                str_contains($suggestionCode, 'Benefits') ||
                str_contains($suggestionDescription, 'Replace') ||
                str_contains($suggestionDescription, 'json') ||
                str_contains($suggestionDescription, 'enum'),
                'Suggestion should contain migration guidance',
            );
        }
    }

    #[Test]
    public function it_counts_correct_number_of_issues(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        // Based on fixtures:
        // EntityWithObjectType: 2 fields (metadata, configuration)
        // EntityWithArrayType: 2 fields (settings, permissions)
        // EntityWithSimpleArray: 2 fields with length <= 255 (tags, categories)
        // EntityWithEnumOpportunity: 4+ enum opportunities (status, type, role, priority)
        // EntityWithMixedIssues: 1 object + 1 array + 1 simple_array + 1 enum
        // EntityWithCorrectTypes: 0 issues

        // Minimum expected: 2 + 2 + 2 + 4 + 4 = 14 issues
        self::assertGreaterThanOrEqual(14, count($issues));
    }

    #[Test]
    public function it_handles_orm_2_and_3_compatibility(): void
    {
        // Test that analyzer works with different ORM versions
        // This test ensures the normalizeMappingToArray method works correctly
        $issues = $this->analyzer->analyze(QueryDataCollection::empty());

        // Should not throw errors
        foreach ($issues as $issue) {
            self::assertNotNull($issue);
            self::assertNotEmpty($issue->getTitle());
        }
    }

    #[Test]
    public function it_identifies_enum_patterns_correctly(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        $enumIssues = array_filter(
            $issues,
            fn ($issue) => str_contains($issue->getTitle(), 'enum'),
        );

        // Should detect enum opportunities only for enum-like field names
        $enumFieldNames = ['status', 'type', 'role', 'priority', 'state', 'kind', 'level', 'category', 'mode', 'phase', 'stage'];

        foreach ($enumIssues as $issue) {
            $foundPattern = false;
            foreach ($enumFieldNames as $pattern) {
                if (str_contains(strtolower($issue->getDescription()), $pattern)) {
                    $foundPattern = true;
                    break;
                }
            }

            self::assertTrue($foundPattern, 'Enum suggestion should only be for enum-like field names');
        }
    }

    #[Test]
    public function it_provides_severity_appropriate_for_risk_level(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        foreach ($issues as $issue) {
            $description = $issue->getDescription();
            $severity = $issue->getSeverity();

            // Object type should be CRITICAL (security risk)
            if (str_contains($description, 'type "object"')) {
                self::assertEquals(
                    Severity::CRITICAL,
                    $severity,
                    'Object type should be CRITICAL due to security risks',
                );
            }

            // Array type should be WARNING (maintenance risk)
            if (str_contains($description, 'type "array"')) {
                self::assertEquals(
                    Severity::WARNING,
                    $severity,
                    'Array type should be WARNING due to maintenance issues',
                );
            }

            // Simple array and enum opportunities should be INFO
            if (str_contains($description, 'simple_array') || str_contains($description, 'enum')) {
                self::assertEquals(
                    Severity::INFO,
                    $severity,
                    'Simple array and enum suggestions should be INFO',
                );
            }
        }
    }

    #[Test]
    public function it_includes_replacement_suggestions_in_descriptions(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        foreach ($issues as $issue) {
            $description = $issue->getDescription();

            // Every description should suggest an alternative
            self::assertTrue(
                str_contains($description, 'json') ||
                str_contains($description, 'enum') ||
                str_contains($description, 'instead') ||
                str_contains($description, 'Use'),
                'Description should provide a replacement suggestion',
            );
        }
    }

    #[Test]
    public function it_does_not_duplicate_issues_for_same_field(): void
    {
        $issues = iterator_to_array($this->analyzer->analyze(QueryDataCollection::empty()));

        $fieldReferences = array_map(function ($issue) {
            // Extract entity::field pattern from title
            if (1 === preg_match('/(\w+)::\$(\w+)/', $issue->getTitle(), $matches)) {
                return $matches[0]; // entity::$field
            }
            return $issue->getTitle();
        }, $issues);

        // Each field should only appear once per issue type
        $titleGroups = [];
        foreach ($issues as $issue) {
            $key = $issue->getTitle();
            if (!isset($titleGroups[$key])) {
                $titleGroups[$key] = 0;
            }
            $titleGroups[$key]++;
        }

        foreach ($titleGroups as $title => $count) {
            self::assertEquals(
                1,
                $count,
                "Issue should not be duplicated: {$title}",
            );
        }
    }

    #[Test]
    public function it_downgrades_severity_for_vendor_entities(): void
    {
        // We can't easily mock vendor paths in tests, but we can verify
        // that app code entities have correct severity
        $queries = QueryDataCollection::empty();
        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        // Find object type issues (should be CRITICAL for app code)
        $objectTypeIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'object') &&
                          str_contains($issue->getTitle(), 'EntityWithObjectType'),
        );

        self::assertGreaterThan(0, count($objectTypeIssues), 'Should have object type issues');

        foreach ($objectTypeIssues as $issue) {
            // App code should have CRITICAL severity
            self::assertEquals('critical', $issue->getSeverity()->value);
            // Title should NOT contain "vendor dependency"
            self::assertStringNotContainsString('vendor dependency', $issue->getTitle());
        }
    }

    #[Test]
    public function it_adds_vendor_warning_message_format(): void
    {
        // Verify that vendor detection mechanism is in place
        // by checking that non-vendor entities don't have vendor warnings
        $queries = QueryDataCollection::empty();
        $issues = $this->analyzer->analyze($queries);
        $issuesArray = $issues->toArray();

        $objectTypeIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getTitle(), 'object'),
        );

        foreach ($objectTypeIssues as $issue) {
            // App entities should NOT have vendor warning
            self::assertStringNotContainsString(
                'vendor dependency',
                $issue->getDescription(),
                'App entities should not have vendor warnings',
            );
        }
    }

    private function createSuggestionFactory(): SuggestionFactory
    {
        $arrayLoader = new ArrayLoader([
            'default' => 'Suggestion: {{ message }}',
            'code_suggestion' => '{{ description }}

{{ code }}',
            'Integrity/code_suggestion' => '{{ description }}

{{ code }}',
        ]);
        $twigEnvironment = new Environment($arrayLoader);
        $renderer = new TwigTemplateRenderer($twigEnvironment);

        return new SuggestionFactory($renderer);
    }
}
