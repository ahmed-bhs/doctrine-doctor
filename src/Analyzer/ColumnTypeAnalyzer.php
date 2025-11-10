<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\CodeQualityIssue;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;

/**
 * Analyzes Doctrine column types for best practices.
 * Detects:
 * - Use of 'object' type (deprecated, insecure)
 * - Use of 'array' type (serialization issues)
 * - Use of 'simple_array' for complex data
 * - Missing 'json' type for structured data
 * - Improper use of 'enum' type
 */
class ColumnTypeAnalyzer implements AnalyzerInterface
{
    // Deprecated/problematic types
    private const PROBLEMATIC_TYPES = [
        'object' => [
            'severity'    => 'critical',
            'reason'      => 'Uses PHP serialize() which is insecure and fragile',
            'replacement' => 'json',
        ],
        'array' => [
            'severity'    => 'warning',
            'reason'      => 'Uses PHP serialize() which breaks with class changes',
            'replacement' => 'json',
        ],
    ];

    // Types that need validation
    private const SIMPLE_ARRAY_MAX_LENGTH = 255;

    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
        /**
         * @readonly
         */
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param QueryDataCollection $queryDataCollection - Not used, entity metadata analyzer
     * @return IssueCollection<CodeQualityIssue>
     */
    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                try {
                    $metadataFactory = $this->entityManager->getMetadataFactory();
                    $allMetadata     = $metadataFactory->getAllMetadata();

                    assert(is_iterable($allMetadata), '$allMetadata must be iterable');

                    foreach ($allMetadata as $metadata) {
                        $entityIssues = $this->analyzeEntity($metadata);

                        assert(is_iterable($entityIssues), '$entityIssues must be iterable');

                        foreach ($entityIssues as $entityIssue) {
                            yield $entityIssue;
                        }
                    }
                } catch (\Throwable $throwable) {
                    $this->logger?->error('ColumnTypeAnalyzer failed', [
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                        'file' => $throwable->getFile(),
                        'line' => $throwable->getLine(),
                    ]);
                }
            },
        );
    }

    /**
     * @return array<CodeQualityIssue>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {

        $issues      = [];
        $entityClass = $classMetadata->getName();

        foreach ($classMetadata->fieldMappings as $fieldName => $mapping) {
            // Normalize mapping to array format (supports ORM 2.x arrays and ORM 3.x FieldMapping objects)
            $mappingArray = $this->normalizeMappingToArray($mapping);
            $type         = $mappingArray['type'] ?? null;

            // Check for problematic types (object, array)
            if (isset(self::PROBLEMATIC_TYPES[$type])) {
                $issues[] = $this->createProblematicTypeIssue(
                    $entityClass,
                    $fieldName,
                    $type,
                    self::PROBLEMATIC_TYPES[$type],
                );
            }

            // Check for simple_array misuse
            if ('simple_array' === $type) {
                $issue = $this->checkSimpleArrayUsage($entityClass, $fieldName, $mappingArray);

                if ($issue instanceof CodeQualityIssue) {
                    $issues[] = $issue;
                }
            }

            // Check for missing enum type (PHP 8.1+)
            $issue = $this->checkForEnumOpportunity($entityClass, $fieldName, $mappingArray);

            if ($issue instanceof CodeQualityIssue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * Normalize mapping to array format for compatibility with both ORM 2.x and 3.x.
     * ORM 2.x: fieldMappings contains arrays
     * ORM 3.x: fieldMappings contains FieldMapping objects
     */
    private function normalizeMappingToArray(array|object $mapping): array
    {
        if (is_array($mapping)) {
            // ORM 2.x - already an array
            return $mapping;
        }

        // ORM 3.x - FieldMapping object, convert to array
        $normalized = [];

        // Access public properties or use reflection to extract data
        if (property_exists($mapping, 'type')) {
            $normalized['type'] = $mapping->type;
        }

        if (property_exists($mapping, 'fieldName')) {
            $normalized['fieldName'] = $mapping->fieldName;
        }

        if (property_exists($mapping, 'length')) {
            $normalized['length'] = $mapping->length;
        }

        if (property_exists($mapping, 'nullable')) {
            $normalized['nullable'] = $mapping->nullable;
        }

        if (property_exists($mapping, 'columnName')) {
            $normalized['columnName'] = $mapping->columnName;
        }

        return $normalized;
    }

    private function createProblematicTypeIssue(
        string $entityClass,
        string $fieldName,
        string $type,
        array $typeInfo,
    ): CodeQualityIssue {
        $shortClassName = $this->getShortClassName($entityClass);

        $description = sprintf(
            'Field "%s::$%s" uses deprecated type "%s". %s. ' .
            'Use "%s" type instead for better security, performance, and maintainability.',
            $shortClassName,
            $fieldName,
            $type,
            $typeInfo['reason'],
            $typeInfo['replacement'],
        );

        return new CodeQualityIssue([
            'title'       => sprintf('Problematic column type "%s" in %s::$%s', $type, $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => $typeInfo['severity'],
            'suggestion'  => $this->suggestionFactory->createCodeSuggestion(
                description: sprintf('Replace "%s" type with "%s"', $type, $typeInfo['replacement']),
                code: $this->generateTypeReplacementCode($entityClass, $fieldName, $type),
                filePath: $entityClass,
            ),
            'backtrace' => null,
            'queries'   => [],
        ]);
    }

    private function checkSimpleArrayUsage(
        string $entityClass,
        string $fieldName,
        array $mapping,
    ): ?CodeQualityIssue {
        $shortClassName = $this->getShortClassName($entityClass);

        // simple_array stores as comma-separated string
        // Issues:
        // 1. Cannot contain commas in values
        // 2. Limited to 255 characters by default
        // 3. No validation
        // 4. Always returns strings (no type preservation)

        $length = $mapping['length'] ?? 255;

        if ($length <= self::SIMPLE_ARRAY_MAX_LENGTH) {
            return new CodeQualityIssue([
                'title'       => sprintf('simple_array type with limited length in %s::$%s', $shortClassName, $fieldName),
                'description' => sprintf(
                    'Field "%s::$%s" uses "simple_array" type with length=%d. ' .
                    'This type has limitations: cannot contain commas, limited length, no type preservation. ' .
                    'Consider using "json" type for better flexibility and data integrity.',
                    $shortClassName,
                    $fieldName,
                    $length,
                ),
                'severity'   => 'info',
                'suggestion' => $this->suggestionFactory->createCodeSuggestion(
                    description: 'Replace simple_array with json type',
                    code: $this->generateSimpleArrayMigrationCode($entityClass, $fieldName),
                    filePath: $entityClass,
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }

        return null;
    }

    private function checkForEnumOpportunity(
        string $entityClass,
        string $fieldName,
        array $mapping,
    ): ?CodeQualityIssue {
        $type = $mapping['type'] ?? null;

        // Check if field is string with limited values (potential enum)
        if ('string' === $type && $this->looksLikeEnum($fieldName)) {
            $shortClassName = $this->getShortClassName($entityClass);

            return new CodeQualityIssue([
                'title'       => sprintf('Consider using native enum for %s::$%s', $shortClassName, $fieldName),
                'description' => sprintf(
                    'Field "%s::$%s" appears to store enum-like values (e.g., status, type, role). ' .
                    'PHP 8.1+ supports native enums with Doctrine integration. ' .
                    'This provides type safety, IDE autocomplete, and prevents invalid values.',
                    $shortClassName,
                    $fieldName,
                ),
                'severity'   => 'info',
                'suggestion' => $this->suggestionFactory->createCodeSuggestion(
                    description: 'Migrate to PHP 8.1+ native enum',
                    code: $this->generateEnumMigrationCode($entityClass, $fieldName),
                    filePath: $entityClass,
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }

        return null;
    }

    private function looksLikeEnum(string $fieldName): bool
    {
        $enumPatterns = [
            'status', 'state', 'type', 'kind', 'role', 'level',
            'priority', 'category', 'mode', 'phase', 'stage',
        ];

        $lowerFieldName = strtolower($fieldName);

        assert(is_iterable($enumPatterns), '$enumPatterns must be iterable');

        foreach ($enumPatterns as $enumPattern) {
            if (str_contains($lowerFieldName, $enumPattern)) {
                return true;
            }
        }

        return false;
    }

    private function generateTypeReplacementCode(
        string $entityClass,
        string $fieldName,
        string $oldType,
    ): string {
        $shortClassName = $this->getShortClassName($entityClass);

        $code = "// In {$shortClassName} entity:

";

        if ('object' === $oldType) {
            $code .= "// BEFORE - Insecure and fragile:
";
            $code .= "#[ORM\Column(type: 'object')]
";
            $code .= "private object \${$fieldName};

";

            $code .= "//  AFTER - Secure and maintainable:
";
            $code .= "#[ORM\Column(type: 'json')]
";
            $code .= "private array \${$fieldName};

";

            $code .= "// Migration:
";
            $code .= "// 1. Change type to 'json'
";
            $code .= "// 2. Update getter/setter to work with arrays
";
            $code .= "// 3. Run: doctrine:migrations:diff
";
            $code .= "// 4. Run: doctrine:migrations:migrate

";

            $code .= "// If you need object behavior, use a DTO:
";
            $code .= 'private function get' . ucfirst($fieldName) . "(): MyDataObject
";
            $code .= "{
";
            $code .= "    return new MyDataObject(\$this->{$fieldName});
";
            $code .= "}
";
        } elseif ('array' === $oldType) {
            $code .= "// BEFORE - Uses serialize():
";
            $code .= "#[ORM\Column(type: 'array')]
";
            $code .= "private array \${$fieldName};

";

            $code .= "//  AFTER - Uses JSON:
";
            $code .= "#[ORM\Column(type: 'json')]
";
            $code .= "private array \${$fieldName};

";

            $code .= "// Benefits:
";
            $code .= "// - JSON is portable (works across languages)
";
            $code .= "// - No class autoloading issues
";
            $code .= "// - Can query JSON fields in database
";
            $code .= "// - Better performance
";
        }

        return $code;
    }

    private function generateSimpleArrayMigrationCode(string $entityClass, string $fieldName): string
    {
        $shortClassName = $this->getShortClassName($entityClass);

        $code = "// In {$shortClassName} entity:

";
        $code .= "// BEFORE - simple_array limitations:
";
        $code .= "#[ORM\Column(type: 'simple_array')]
";
        $code .= "private array \${$fieldName};
";
        $code .= "// Limitations:
";
        $code .= "// - Cannot contain commas in values
";
        $code .= "// - Limited to 255 characters
";
        $code .= "// - Returns strings only (no type preservation)
";
        $code .= "// - Stores as: 'value1,value2,value3'

";

        $code .= "//  AFTER - json type:
";
        $code .= "#[ORM\Column(type: 'json')]
";
        $code .= "private array \${$fieldName};
";
        $code .= "// Benefits:
";
        $code .= "// - Can contain any character
";
        $code .= "// - No length limitation
";
        $code .= "// - Preserves types (numbers, booleans, nested arrays)
";
        $code .= "// - Stores as: '[\"value1\",\"value2\",\"value3\"]'
";
        $code .= "// - Can be queried with JSON functions

";

        $code .= "// Migration:
";
        $code .= "// No data migration needed if values don't contain commas!
";

        return $code . "// Doctrine will automatically convert format on first save
";
    }

    private function generateEnumMigrationCode(string $entityClass, string $fieldName): string
    {
        $shortClassName = $this->getShortClassName($entityClass);
        $enumName       = ucfirst($fieldName) . 'Enum';

        $code = "// Step 1: Create the enum
";
        $code .= "// src/Enum/{$enumName}.php

";
        $code .= "namespace App\Enum;

";
        $code .= "enum {$enumName}: string
";
        $code .= "{
";
        $code .= "    case DRAFT = 'draft';
";
        $code .= "    case PUBLISHED = 'published';
";
        $code .= "    case ARCHIVED = 'archived';

";
        $code .= "    public function label(): string
";
        $code .= "    {
";
        $code .= "        return match(\$this) {
";
        $code .= "            self::DRAFT => 'Draft',
";
        $code .= "            self::PUBLISHED => 'Published',
";
        $code .= "            self::ARCHIVED => 'Archived',
";
        $code .= "        };
";
        $code .= "    }
";
        $code .= "}

";

        $code .= "// Step 2: Update entity
";
        $code .= "// {$shortClassName}.php

";
        $code .= "use App\Enum\{{$enumName}};

";
        $code .= "// BEFORE:
";
        $code .= "#[ORM\Column(type: 'string', length: 20)]
";
        $code .= "private string \${$fieldName};

";

        $code .= "//  AFTER:
";
        $code .= "#[ORM\Column(type: 'string', enumType: {$enumName}::class)]
";
        $code .= "private {$enumName} \${$fieldName};

";

        $code .= "// Benefits:
";
        $code .= "// - Type safety: IDE autocomplete
";
        $code .= "// - Cannot set invalid values
";
        $code .= "// - Refactoring-friendly
";
        $code .= "// - Self-documenting

";

        $code .= "// Usage:
";
        $code .= '$entity->set' . ucfirst($fieldName) . "({$enumName}::PUBLISHED);
";
        $code .= 'if ($entity->get' . ucfirst($fieldName) . "() === {$enumName}::DRAFT) {
";
        $code .= "    // Type-safe comparison!
";

        return $code . "}
";
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }
}
