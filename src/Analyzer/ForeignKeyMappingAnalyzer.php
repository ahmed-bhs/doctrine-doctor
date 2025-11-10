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
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\CodeQualityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;

/**
 * Detects foreign keys mapped as primitive types instead of object relations.
 * This is an anti-pattern that goes against Doctrine ORM best practices:
 * - Foreign keys should be mapped as ManyToOne/OneToOne relations
 * - Storing integer IDs defeats the purpose of using an ORM
 * - Makes code procedural instead of object-oriented
 * - Prevents lazy loading and relationship management
 * Example:
 * BAD:
 *   class Order {
 *       private int $userId;  // Foreign key as primitive
 *   }
 *  GOOD:
 *   class Order {
 *       private User $user;  // Proper object relation
 *   }
 */
class ForeignKeyMappingAnalyzer implements AnalyzerInterface
{
    /**
     * Common suffixes that indicate foreign key fields.
     */
    private const FK_SUFFIXES = ['_id', 'Id', '_ID'];

    /**
     * Entity name patterns that are likely referenced entities.
     */
    private const ENTITY_PATTERNS = [
        'user', 'customer', 'account', 'product', 'order', 'category',
        'company', 'organization', 'team', 'group', 'role', 'permission',
    ];

    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private SuggestionFactory $suggestionFactory,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                $classMetadataFactory = $this->entityManager->getMetadataFactory();
                $allMetadata          = $classMetadataFactory->getAllMetadata();

                assert(is_iterable($allMetadata), '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    $entityIssues = $this->analyzeEntity($metadata, $allMetadata);

                    assert(is_iterable($entityIssues), '$entityIssues must be iterable');

                    foreach ($entityIssues as $entityIssue) {
                        yield $entityIssue;
                    }
                }
            },
        );
    }

    /**
     * Analyze a single entity for foreign key mapping issues.
     */
    private function analyzeEntity(ClassMetadata $classMetadata, array $allMetadata): array
    {

        $issues      = [];
        $entityClass = $classMetadata->getName();

        // Get all field mappings (excluding associations)
        $fieldMappings = $classMetadata->fieldMappings;

        assert(is_iterable($fieldMappings), '$fieldMappings must be iterable');

        foreach ($fieldMappings as $fieldName => $mapping) {
            // Skip non-integer fields (foreign keys are typically integers)
            $type = MappingHelper::getString($mapping, 'type');

            if (!in_array($type, ['integer', 'bigint', 'smallint'], true)) {
                continue;
            }

            // Check if field name suggests it's a foreign key
            // Check if there's already a proper relation for this field
            if ($this->isForeignKeyField($fieldName) && !$this->hasProperRelation($classMetadata, $fieldName)) {
                $issue = $this->createForeignKeyIssue(
                    $entityClass,
                    $fieldName,
                    $mapping,
                    $allMetadata,
                );
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * Check if field name suggests it's a foreign key.
     */
    private function isForeignKeyField(string $fieldName): bool
    {
        // Check common FK suffixes
        foreach (self::FK_SUFFIXES as $suffix) {
            if (str_ends_with($fieldName, $suffix)) {
                return true;
            }
        }

        // Check if field name contains entity patterns
        $lowerFieldName = strtolower($fieldName);

        foreach (self::ENTITY_PATTERNS as $pattern) {
            if (str_contains($lowerFieldName, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if entity already has a proper object relation for this field.
     */
    private function hasProperRelation(ClassMetadata $classMetadata, string $fieldName): bool
    {
        // Remove common FK suffixes to get base name
        $baseName = $fieldName;

        foreach (self::FK_SUFFIXES as $suffix) {
            if (str_ends_with($baseName, $suffix)) {
                $baseName = substr($baseName, 0, -strlen($suffix));
                break;
            }
        }

        // Check if there's an association with this base name
        $associations = $classMetadata->getAssociationNames();

        assert(is_iterable($associations), '$associations must be iterable');

        foreach ($associations as $association) {
            if (strtolower($association) === strtolower($baseName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create issue for foreign key mapping anti-pattern.
     * @param array<string, mixed>|object $mapping
     */
    private function createForeignKeyIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        array $allMetadata,
    ): CodeQualityIssue {
        // Try to guess the target entity
        $targetEntity = $this->guessTargetEntity($fieldName, $allMetadata);

        $type = MappingHelper::getString($mapping, 'type');

        // Create synthetic backtrace
        $backtrace = $this->createEntityFieldBacktrace($entityClass, $fieldName);

        $codeQualityIssue = new CodeQualityIssue([
            'entity'        => $entityClass,
            'field'         => $fieldName,
            'type'          => $type,
            'target_entity' => $targetEntity,
            'backtrace'     => $backtrace,
        ]);

        $codeQualityIssue->setSeverity('warning');
        $codeQualityIssue->setTitle('Foreign Key Mapped as Primitive Type');

        $message = DescriptionHighlighter::highlight(
            "Field {field} in entity {class} appears to be a foreign key but is mapped as a primitive type {type}. This is an anti-pattern in Doctrine ORM.",
            [
                'field' => $fieldName,
                'class' => $entityClass,
                'type' => $type,
            ],
        );
        $codeQualityIssue->setMessage($message);
        $codeQualityIssue->setSuggestion($this->createSuggestionInterface($entityClass, $fieldName, $targetEntity));

        return $codeQualityIssue;
    }

    /**
     * Try to guess the target entity from field name.
     */
    private function guessTargetEntity(string $fieldName, array $allMetadata): ?string
    {
        // Remove FK suffix
        $baseName = $fieldName;

        foreach (self::FK_SUFFIXES as $suffix) {
            if (str_ends_with($baseName, $suffix)) {
                $baseName = substr($baseName, 0, -strlen($suffix));
                break;
            }
        }

        // Try to find matching entity
        $baseNameLower = strtolower($baseName);

        assert(is_iterable($allMetadata), '$allMetadata must be iterable');

        foreach ($allMetadata as $metadata) {
            $className = $metadata->getName();
            $shortName = strtolower($this->getShortClassName($className));

            if ($shortName === $baseNameLower) {
                return $className;
            }
        }

        // Return capitalized base name as guess
        return ucfirst($baseName);
    }

    /**
     * Get short class name (without namespace).
     */
    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }

    /**
     * Create suggestion interface for fixing the issue.
     */
    private function createSuggestionInterface(string $entityClass, string $fieldName, ?string $targetEntity): SuggestionInterface
    {
        $baseName = $fieldName;

        foreach (self::FK_SUFFIXES as $suffix) {
            if (str_ends_with($baseName, $suffix)) {
                $baseName = substr($baseName, 0, -strlen($suffix));
                break;
            }
        }

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'foreign_key_primitive',
            context: [
                'entity_class'     => $this->getShortClassName($entityClass),
                'field_name'       => $fieldName,
                'target_entity'    => $targetEntity ?? 'Unknown',
                'association_type' => 'ManyToOne',
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::codeQuality(),
                severity: Severity::warning(),
                title: 'Foreign Key Mapped as Primitive Type',
                tags: ['code-quality', 'orm', 'anti-pattern'],
            ),
        );
    }

    /**
     * Create synthetic backtrace pointing to entity field.
     * @return array<int, array<string, mixed>>|null
     */
    private function createEntityFieldBacktrace(string $entityClass, string $fieldName): ?array
    {
        try {
            assert(class_exists($entityClass));
            $reflectionClass = new ReflectionClass($entityClass);
            $fileName        = $reflectionClass->getFileName();

            if (false === $fileName) {
                return null;
            }

            // Try to find the property line
            $lineNumber = $reflectionClass->getStartLine();

            if ($reflectionClass->hasProperty($fieldName)) {
                $reflectionProperty = $reflectionClass->getProperty($fieldName);
                $propertyLine       = $reflectionProperty->getDeclaringClass()->getStartLine();

                if (false !== $propertyLine) {
                    $lineNumber = $propertyLine;
                }
            }

            return [
                [
                    'file'     => $fileName,
                    'line'     => $lineNumber ?: 1,
                    'class'    => $entityClass,
                    'function' => '$' . $fieldName,
                    'type'     => '::',
                ],
            ];
        } catch (\Exception) {
            return null;
        }
    }
}
