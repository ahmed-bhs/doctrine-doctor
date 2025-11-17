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
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;
use Webmozart\Assert\Assert;

/**
 * Detects composition relationships without orphanRemoval=true.
 * In composition (parent owns children), orphanRemoval should be enabled.
 * Otherwise, removing a child from the collection leaves it orphaned in the DB.
 * Example:
 * class Order {
 *     @OneToMany(targetEntity="OrderItem", cascade={"remove"})
 *     private Collection $items;
 * }
 * $order->getItems()->remove($item);
 * $em->flush();  // Item stays in DB with order_id = NULL (orphan)
 *  Should have orphanRemoval=true
 */
class MissingOrphanRemovalOnCompositionAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Child entity name patterns that suggest composition.
     */
    private const COMPOSITION_CHILD_PATTERNS = [
        'Item', 'Line', 'Entry', 'Detail', 'Part', 'Component',
        'Element', 'Record', 'Row', 'Member',
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

                Assert::isIterable($allMetadata, '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    $entityIssues = $this->analyzeEntity($metadata, $allMetadata);

                    Assert::isIterable($entityIssues, '$entityIssues must be iterable');

                    foreach ($entityIssues as $entityIssue) {
                        yield $entityIssue;
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Missing OrphanRemoval on Composition Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects composition relationships without orphanRemoval=true which leaves orphaned records in database';
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     * @param list<ClassMetadata<object>> $allMetadata
     * @return array<\AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function analyzeEntity(ClassMetadata $classMetadata, array $allMetadata): array
    {
        $issues      = [];
        $entityClass = $classMetadata->getName();

        foreach ($classMetadata->getAssociationMappings() as $fieldName => $associationMapping) {
            // Only check OneToMany
            if (($associationMapping['type'] ?? 0) !== ClassMetadata::ONE_TO_MANY) {
                continue;
            }

            // Check if orphanRemoval is already enabled
            $orphanRemoval = (bool) ($associationMapping['orphanRemoval'] ?? false);

            if ($orphanRemoval) {
                continue;
            }

            // Check if this looks like a composition relationship
            $isComposition = $this->isCompositionRelationship($associationMapping, $allMetadata);

            if (!$isComposition) {
                continue;
            }

            $issue    = $this->createIssue($entityClass, $fieldName, $associationMapping, $allMetadata);
            $issues[] = $issue;
        }

        return $issues;
    }

    /**
     * Determine if a OneToMany relationship is a composition.
     */
    private function isCompositionRelationship(array|object $mapping, array $allMetadata): bool
    {
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? null;

        if (null === $targetEntity) {
            return false;
        }

        // Signal 1: Has cascade="remove" (parent deletes children)
        $cascade          = MappingHelper::getArray($mapping, 'cascade') ?? [];
        $hasCascadeRemove = in_array('remove', $cascade, true) || in_array('all', $cascade, true);

        // Signal 2: Child entity name suggests composition
        $hasCompositionName = $this->hasCompositionName($targetEntity);

        // Signal 3: Foreign key is NOT NULL (child must have parent)
        $isNotNullFK = $this->isForeignKeyNotNull($targetEntity, $mapping, $allMetadata);

        // If at least 2 signals, it's likely a composition
        $signals = (int) $hasCascadeRemove + (int) $hasCompositionName + (int) $isNotNullFK;

        return $signals >= 2;
    }

    private function hasCompositionName(string $entityClass): bool
    {
        foreach (self::COMPOSITION_CHILD_PATTERNS as $pattern) {
            if (str_contains($entityClass, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the foreign key on the child side is NOT NULL.
     * @param array<string, mixed>|object $parentMapping
     */
    private function isForeignKeyNotNull(string $targetEntity, array|object $parentMapping, array $allMetadata): bool
    {
        // Find target entity metadata
        $targetMetadata = null;

        Assert::isIterable($allMetadata, '$allMetadata must be iterable');

        foreach ($allMetadata as $metadata) {
            if ($metadata->getName() === $targetEntity) {
                $targetMetadata = $metadata;
                break;
            }
        }

        if (null === $targetMetadata) {
            return false;
        }

        // Get mappedBy field name
        $mappedBy = MappingHelper::getString($parentMapping, 'mappedBy');

        if (null === $mappedBy) {
            return false;
        }

        // Check if the association on the child side has nullable=false
        $childAssociations = $targetMetadata->getAssociationMappings();
        $childMapping      = $childAssociations[$mappedBy] ?? null;

        if (null === $childMapping) {
            return false;
        }

        // Check join column
        $joinColumns = MappingHelper::getArray($childMapping, 'joinColumns');

        if ([] === $joinColumns || null === $joinColumns) {
            return false;
        }

        Assert::isArray($joinColumns, '$joinColumns must be array');
        $firstJoinColumn = reset($joinColumns);
        // Handle both array and object joinColumn
        $nullable = is_array($firstJoinColumn)
            ? ($firstJoinColumn['nullable'] ?? true)
            : ($firstJoinColumn->nullable ?? true);

        return !$nullable;
    }

    private function createIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        array $allMetadata,
    ): IntegrityIssue {
        $targetEntity     = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $cascade          = MappingHelper::getArray($mapping, 'cascade') ?? [];
        $hasCascadeRemove = in_array('remove', $cascade, true) || in_array('all', $cascade, true);
        $isNotNullFK      = $this->isForeignKeyNotNull($targetEntity, $mapping, $allMetadata);
        $isVendor         = $this->isVendorEntity($entityClass);

        // Create synthetic backtrace
        $backtrace = $this->createEntityFieldBacktrace($entityClass, $fieldName);

        $codeQualityIssue = new IntegrityIssue([
            'entity'             => $entityClass,
            'field'              => $fieldName,
            'target_entity'      => $targetEntity,
            'cascade'            => $cascade,
            'has_cascade_remove' => $hasCascadeRemove,
            'nullable_fk'        => !$isNotNullFK,
            'backtrace'          => $backtrace,
        ]);

        // Adjust severity based on vendor status
        if ($isVendor) {
            // Downgrade severity for vendor entities
            $severity = $isNotNullFK ? 'warning' : 'info';
        } else {
            // CRITICAL if FK is not nullable (inconsistent: can't be orphaned but orphanRemoval is missing)
            $severity = $isNotNullFK ? 'critical' : 'warning';
        }

        $codeQualityIssue->setSeverity($severity);

        $title = 'Missing orphanRemoval on Composition Relationship';
        if ($isVendor) {
            $title .= ' (vendor dependency)';
        }
        $codeQualityIssue->setTitle($title);

        $message = DescriptionHighlighter::highlight(
            "OneToMany field {field} in entity {class} appears to be a composition relationship but lacks {orphan}. This leaves orphaned records in the database when children are removed from the collection.",
            [
                'field' => $fieldName,
                'class' => $entityClass,
                'orphan' => 'orphanRemoval=true',
            ],
        );

        if ($isVendor) {
            $message .= "\n\nWARNING: This entity is from a vendor dependency. " .
                "This may be an intentional design choice. Consider:\n" .
                "1. Verifying if this is intentional (e.g., Reviews should persist independently)\n" .
                "2. Creating a local entity that extends this class if you need different behavior\n" .
                "3. Accepting this as vendor design decision";
        }

        $codeQualityIssue->setMessage($message);
        $codeQualityIssue->setSuggestion($this->buildSuggestion($entityClass, $fieldName, $mapping, $isNotNullFK, $isVendor));

        return $codeQualityIssue;
    }

    /**
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    private function buildSuggestion(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        bool $isNotNullFK,
        bool $isVendor = false,
    ): SuggestionInterface {
        $targetEntity    = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $cascade         = MappingHelper::getArray($mapping, 'cascade') ?? [];
        $shortClassName  = $this->getShortClassName($entityClass);
        $shortTargetName = $this->getShortClassName($targetEntity);
        $mappedBy        = MappingHelper::getString($mapping, 'mappedBy') ?? 'parent';

        $currentCascade = [] === $cascade
            ? '// No cascade'
            : 'cascade: [' . implode(', ', array_map(fn (string $cascadeOp): string => sprintf("'%s'", $cascadeOp), $cascade)) . ']';

        if ($isVendor) {
            $severity = $isNotNullFK ? Severity::warning() : Severity::info();
            $title = 'Vendor Entity - Review orphanRemoval Design';
        } else {
            $severity = $isNotNullFK ? Severity::critical() : Severity::warning();
            $title = 'Add orphanRemoval for Composition Relationship';
        }

        $suggestionMetadata = new SuggestionMetadata(
            type: SuggestionType::integrity(),
            severity: $severity,
            title: $title,
        );

        return $this->suggestionFactory->createFromTemplate(
            'missing_orphan_removal',
            [
                'entity_class'    => $shortClassName,
                'target_class'    => $shortTargetName,
                'field_name'      => $fieldName,
                'mapped_by'       => $mappedBy,
                'current_cascade' => $currentCascade,
                'is_not_null_fk'  => $isNotNullFK,
                'is_vendor'       => $isVendor,
            ],
            $suggestionMetadata,
        );
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

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }

    /**
     * Create synthetic backtrace pointing to entity field.
     * @return array<int, array<string, mixed>>|null
     */
    private function createEntityFieldBacktrace(string $entityClass, string $fieldName): ?array
    {
        try {
            Assert::classExists($entityClass);
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
