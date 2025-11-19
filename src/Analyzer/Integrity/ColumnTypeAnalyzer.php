<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Webmozart\Assert\Assert;

/**
 * Analyzes Doctrine column types for best practices.
 * Detects:
 * - Use of 'object' type (deprecated, insecure)
 * - Use of 'array' type (serialization issues)
 * - Use of 'simple_array' for complex data
 * - Missing 'json' type for structured data
 * - Improper use of 'enum' type
 */
class ColumnTypeAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
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
     * @return IssueCollection<IntegrityIssue>
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

                    Assert::isIterable($allMetadata, '$allMetadata must be iterable');

                    foreach ($allMetadata as $metadata) {
                        $entityIssues = $this->analyzeEntity($metadata);

                        Assert::isIterable($entityIssues, '$entityIssues must be iterable');

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
     * @return array<IntegrityIssue>
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

                if ($issue instanceof IntegrityIssue) {
                    $issues[] = $issue;
                }
            }

            // Check for missing enum type (PHP 8.1+)
            $issue = $this->checkForEnumOpportunity($entityClass, $fieldName, $mappingArray);

            if ($issue instanceof IntegrityIssue) {
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
    ): IntegrityIssue {
        $shortClassName = $this->getShortClassName($entityClass);
        $isVendor = $this->isVendorEntity($entityClass);

        // Adjust severity and message for vendor entities
        $severity = $typeInfo['severity'];
        $description = sprintf(
            'Field "%s::$%s" uses deprecated type "%s". %s. ' .
            'Use "%s" type instead for better security, performance, and maintainability.',
            $shortClassName,
            $fieldName,
            $type,
            $typeInfo['reason'],
            $typeInfo['replacement'],
        );

        if ($isVendor) {
            // Downgrade severity for vendor code
            $severityEnum = Severity::from($severity);
            $severity = $severityEnum->isCritical() ? 'warning' : 'info';

            $description .= sprintf(
                "\n\nWARNING: This entity is from a vendor dependency. " .
                "You cannot modify it directly. Consider:\n" .
                "1. Creating a local entity that extends this class and overrides the field mapping\n" .
                "2. Opening an issue/PR with the vendor to migrate to JSON\n" .
                "3. Accepting this as a known vendor limitation",
            );
        }

        return new IntegrityIssue([
            'title'       => sprintf(
                'Problematic column type "%s" in %s::$%s%s',
                $type,
                $shortClassName,
                $fieldName,
                $isVendor ? ' (vendor dependency)' : '',
            ),
            'description' => $description,
            'severity'    => $severity,
            'suggestion'  => $this->suggestionFactory->createCodeSuggestion(
                description: $isVendor
                    ? sprintf('Vendor entity - consider creating local override or opening vendor issue')
                    : sprintf('Replace "%s" type with "%s"', $type, $typeInfo['replacement']),
                code: $isVendor
                    ? $this->generateVendorOverrideCode($entityClass, $fieldName, $type, $typeInfo['replacement'])
                    : $this->generateTypeReplacementCode($entityClass, $fieldName, $type),
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
    ): ?IntegrityIssue {
        $shortClassName = $this->getShortClassName($entityClass);

        // simple_array stores as comma-separated string
        // Issues:
        // 1. Cannot contain commas in values
        // 2. Limited to 255 characters by default
        // 3. No validation
        // 4. Always returns strings (no type preservation)

        $length = $mapping['length'] ?? 255;

        if ($length <= self::SIMPLE_ARRAY_MAX_LENGTH) {
            return new IntegrityIssue([
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
    ): ?IntegrityIssue {
        $type = $mapping['type'] ?? null;

        if ('string' !== $type) {
            return null;
        }

        $analysis = $this->analyzeDistinctValues($entityClass, $fieldName, $mapping);

        // Not enough data to conclude
        if ($analysis['total'] < 10) {
            return null;
        }

        // Not an enum: too many distinct values or ratio too high
        if ($analysis['distinct'] > 15 || $analysis['ratio'] > 0.03) {
            return null;
        }

        $shortClassName = $this->getShortClassName($entityClass);

        return new IntegrityIssue([
            'title'       => sprintf('Consider using native enum for %s::$%s', $shortClassName, $fieldName),
            'description' => sprintf(
                'Field "%s::$%s" has only %d distinct values across %d rows (%.1f%% uniqueness). ' .
                'This suggests a fixed set of values that would benefit from a PHP 8.1+ native enum. ' .
                'This provides type safety, IDE autocomplete, and prevents invalid values.',
                $shortClassName,
                $fieldName,
                $analysis['distinct'],
                $analysis['total'],
                $analysis['ratio'] * 100,
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

    /**
     * Analyze distinct values in the database to determine if field looks like an enum.
     *
     * @return array{distinct: int, total: int, ratio: float}
     */
    private function analyzeDistinctValues(string $entityClass, string $fieldName, array $mapping): array
    {
        try {
            $metadata = $this->entityManager->getClassMetadata($entityClass);
            $tableName = $metadata->getTableName();
            $columnName = $mapping['columnName'] ?? $fieldName;

            $sql = sprintf(
                'SELECT COUNT(DISTINCT `%s`) as d, COUNT(*) as t FROM `%s`',
                $columnName,
                $tableName,
            );
            $result = $this->entityManager->getConnection()->executeQuery($sql)->fetchAssociative();

            if (!$result || 0 === (int) $result['t']) {
                return ['distinct' => 0, 'total' => 0, 'ratio' => 1.0];
            }

            $distinct = (int) $result['d'];
            $total = (int) $result['t'];

            return [
                'distinct' => $distinct,
                'total' => $total,
                'ratio' => $total > 0 ? $distinct / $total : 1.0,
            ];
        } catch (\Throwable) {
            return ['distinct' => 0, 'total' => 0, 'ratio' => 1.0];
        }
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

    /**
     * Check if an entity class is from a vendor dependency.
     * Simply checks if the file path contains /vendor/ directory.
     */
    private function isVendorEntity(string $entityClass): bool
    {
        try {
            /** @var class-string $entityClass */
            $reflectionClass = new ReflectionClass($entityClass);
            $filename = $reflectionClass->getFileName();

            if (false === $filename) {
                return false;
            }

            // Check if file is in vendor directory
            return str_contains($filename, '/vendor/') || str_contains($filename, '\\vendor\\');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Generate code suggestion for overriding vendor entity field.
     */
    private function generateVendorOverrideCode(
        string $vendorEntityClass,
        string $fieldName,
        string $oldType,
        string $newType,
    ): string {
        $vendorShortName = $this->getShortClassName($vendorEntityClass);

        $code = "Vendor entity - cannot modify directly.

";
        $code .= "Options:
";
        $code .= "1. Create local entity extending {$vendorShortName} with overridden field
";
        $code .= "2. Open issue with vendor ({$vendorEntityClass})
";
        $code .= "3. Accept as vendor limitation

";
        $code .= "If creating override:
";
        $code .= "#[ORM\Column(type: '{$newType}')]
";
        $code .= "protected \${$fieldName};
";

        return $code;
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }
}
