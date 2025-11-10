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
 * Detects inconsistencies in bidirectional associations.
 * Checks for conflicts between the two sides of a relation:
 * - orphanRemoval=true but nullable FK
 * - cascade="remove" but onDelete="SET NULL"
 * - orphanRemoval without cascade="persist"
 * Example:
 * class Order {
 *     @OneToMany(orphanRemoval=true)
 *     private Collection $items;
 * }
 * class OrderItem {
 *     @ManyToOne @JoinColumn(nullable=true)  //  Inconsistent!
 *     private ?Order $order;
 * }
 */
class BidirectionalConsistencyAnalyzer implements AnalyzerInterface
{
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

                // Create metadata map for quick lookup
                $metadataMap = [];

                assert(is_iterable($allMetadata), '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    $metadataMap[$metadata->getName()] = $metadata;
                }

                assert(is_iterable($allMetadata), '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    $entityIssues = $this->analyzeEntity($metadata, $metadataMap);

                    assert(is_iterable($entityIssues), '$entityIssues must be iterable');

                    foreach ($entityIssues as $entityIssue) {
                        yield $entityIssue;
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Bidirectional Consistency Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects inconsistencies between the two sides of bidirectional associations';
    }

    /**
     * @param ClassMetadata<object>                $classMetadata
     * @param array<string, ClassMetadata<object>> $metadataMap
     * @return array<CodeQualityIssue>
     */
    private function analyzeEntity(ClassMetadata $classMetadata, array $metadataMap): array
    {

        $issues      = [];
        $entityClass = $classMetadata->getName();

        foreach ($classMetadata->getAssociationMappings() as $fieldName => $associationMapping) {
            // Only check OneToMany and OneToOne (owning side of bidirectional)
            // In Doctrine 4, associationMapping is an object, we access type directly from array fallback
            $type = ($associationMapping['type'] ?? null) ?? (is_object($associationMapping) ? $associationMapping::class : 0);

            // For Doctrine 4 objects, check by class name
            if (is_string($type)) {
                $isOneToMany = str_contains($type, 'OneToManyAssociationMapping');
                $isOneToOne = str_contains($type, 'OneToOneAssociationMapping');
                if (!$isOneToMany && !$isOneToOne) {
                    continue;
                }
            } elseif (!in_array($type, [ClassMetadata::ONE_TO_MANY, ClassMetadata::ONE_TO_ONE], true)) {
                continue;
            }

            // Only check bidirectional associations
            $mappedBy = MappingHelper::getString($associationMapping, 'mappedBy') ?? null;

            if (null === $mappedBy) {
                continue;
            }
            assert(is_string($mappedBy), 'mappedBy must be string');

            // Get target entity metadata
            $targetEntity   = MappingHelper::getString($associationMapping, 'targetEntity');
            $targetMetadata = null !== $targetEntity ? ($metadataMap[$targetEntity] ?? null) : null;

            if (null === $targetMetadata) {
                continue;
            }

            // Check for inconsistencies
            $inconsistencies = $this->checkBidirectionalConsistency($associationMapping, $targetMetadata, $mappedBy);

            assert(is_iterable($inconsistencies), '$inconsistencies must be iterable');

            foreach ($inconsistencies as $inconsistency) {
                $issue    = $this->createIssue($entityClass, $fieldName, $associationMapping, $inconsistency);
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     * @return array<array{type: string, severity: string, inverse_field: string}>
     */
    private function checkBidirectionalConsistency(
        array|object $owningMapping,
        ClassMetadata $classMetadata,
        string $mappedBy,
    ): array {
        $inconsistencies = [];

        // Get inverse mapping
        $inverseMappings = $classMetadata->getAssociationMappings();
        $inverseMapping  = $inverseMappings[$mappedBy] ?? null;

        if (null === $inverseMapping) {
            return $inconsistencies;
        }

        // Check 1: orphanRemoval=true but nullable FK
        if ($this->hasOrphanRemovalButNullableFK($owningMapping, $inverseMapping)) {
            $inconsistencies[] = [
                'type'          => 'orphan_removal_nullable_fk',
                'severity'      => 'high',
                'inverse_field' => $mappedBy,
            ];
        }

        // Check 2: cascade="remove" but onDelete="SET NULL"
        if ($this->hasCascadeRemoveButSetNull($owningMapping, $inverseMapping)) {
            $inconsistencies[] = [
                'type'          => 'cascade_remove_set_null',
                'severity'      => 'medium',
                'inverse_field' => $mappedBy,
            ];
        }

        // Check 3: orphanRemoval without cascade="persist"
        if ($this->hasOrphanRemovalWithoutCascadePersist($owningMapping)) {
            $inconsistencies[] = [
                'type'          => 'orphan_removal_no_persist',
                'severity'      => 'medium',
                'inverse_field' => $mappedBy,
            ];
        }

        // Check 4: onDelete="CASCADE" in DB but no cascade in ORM
        if ($this->hasOnDeleteCascadeButNoCascadeORM($owningMapping, $inverseMapping)) {
            $inconsistencies[] = [
                'type'          => 'ondelete_cascade_no_orm',
                'severity'      => 'medium',
                'inverse_field' => $mappedBy,
            ];
        }

        return $inconsistencies;
    }

    private function hasOrphanRemovalButNullableFK(array|object $owningMapping, array|object $inverseMapping): bool
    {
        $orphanRemoval = MappingHelper::getBool($owningMapping, 'orphanRemoval') ?? false;

        if (false === $orphanRemoval) {
            return false;
        }

        // Check if FK is nullable
        $joinColumns = MappingHelper::getArray($inverseMapping, 'joinColumns') ?? [];

        if ([] === $joinColumns) {
            return false;
        }

        $firstJoinColumn = reset($joinColumns);

        // Handle both array and object joinColumn (Doctrine 3 vs 4)
        $nullable = is_array($firstJoinColumn)
            ? ($firstJoinColumn['nullable'] ?? true)
            : ($firstJoinColumn->nullable ?? true);

        return (bool) $nullable;
    }

    private function hasCascadeRemoveButSetNull(array|object $owningMapping, array|object $inverseMapping): bool
    {
        $cascade          = MappingHelper::getArray($owningMapping, 'cascade') ?? [];
        $hasCascadeRemove = in_array('remove', $cascade, true) || in_array('all', $cascade, true);

        if (!$hasCascadeRemove) {
            return false;
        }

        // Check onDelete
        $joinColumns = MappingHelper::getArray($inverseMapping, 'joinColumns') ?? [];

        if ([] === $joinColumns) {
            return false;
        }

        $firstJoinColumn = reset($joinColumns);
        // Handle both array and object joinColumn (Doctrine 3 vs 4)
        $onDelete = is_array($firstJoinColumn)
            ? strtoupper($firstJoinColumn['onDelete'] ?? '')
            : strtoupper($firstJoinColumn->onDelete ?? '');

        return 'SET NULL' === $onDelete;
    }

    private function hasOrphanRemovalWithoutCascadePersist(array|object $owningMapping): bool
    {
        $orphanRemoval = MappingHelper::getBool($owningMapping, 'orphanRemoval') ?? false;

        if (false === $orphanRemoval) {
            return false;
        }

        $cascade           = MappingHelper::getArray($owningMapping, 'cascade') ?? [];
        $hasCascadePersist = in_array('persist', $cascade, true) || in_array('all', $cascade, true);

        return !$hasCascadePersist;
    }

    private function hasOnDeleteCascadeButNoCascadeORM(array|object $owningMapping, array|object $inverseMapping): bool
    {
        // Check if DB has onDelete CASCADE
        $joinColumns = MappingHelper::getArray($inverseMapping, 'joinColumns') ?? [];

        if ([] === $joinColumns) {
            return false;
        }

        $firstJoinColumn = reset($joinColumns);
        // Handle both array and object joinColumn (Doctrine 3 vs 4)
        $onDelete = is_array($firstJoinColumn)
            ? strtoupper($firstJoinColumn['onDelete'] ?? '')
            : strtoupper($firstJoinColumn->onDelete ?? '');

        if ('CASCADE' !== $onDelete) {
            return false;
        }

        // Check if ORM has cascade remove
        $cascade          = MappingHelper::getArray($owningMapping, 'cascade') ?? [];
        $hasCascadeRemove = in_array('remove', $cascade, true) || in_array('all', $cascade, true);

        return !$hasCascadeRemove;
    }

    /**
     * @param array<string, mixed>|object                                  $mapping
     * @param array{type: string, severity: string, inverse_field: string} $inconsistency
     */
    private function createIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        array $inconsistency,
    ): CodeQualityIssue {
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $inverseField = $inconsistency['inverse_field'] ?? 'unknown';

        // Create synthetic backtrace
        $backtrace = $this->createEntityFieldBacktrace($entityClass, $fieldName);

        $codeQualityIssue = new CodeQualityIssue([
            'entity'             => $entityClass,
            'field'              => $fieldName,
            'target_entity'      => $targetEntity,
            'inverse_field'      => $inverseField,
            'inconsistency_type' => $inconsistency['type'],
            'backtrace'          => $backtrace,
        ]);

        // Convert string severity to Severity enum
        $severityString = $inconsistency['severity'] ?? 'medium';
        $severity = match ($severityString) {
            'high' => Severity::CRITICAL,
            'medium' => Severity::WARNING,
            'low' => Severity::INFO,
            default => Severity::WARNING,
        };
        $codeQualityIssue->setSeverity($severity);
        $codeQualityIssue->setTitle('Bidirectional Association Inconsistency');
        $codeQualityIssue->setMessage($this->getInconsistencyMessage($entityClass, $fieldName, $targetEntity, $inverseField, $inconsistency));
        $codeQualityIssue->setSuggestion($this->createBidirectionalSuggestion($entityClass, $fieldName, $targetEntity, $inverseField, $inconsistency));

        return $codeQualityIssue;
    }

    /**
     * @param array{type: string, severity: string, inverse_field: string} $inconsistency
     */
    private function getInconsistencyMessage(
        string $entityClass,
        string $fieldName,
        string $targetEntity,
        string $inverseField,
        array $inconsistency,
    ): string {
        $type = $inconsistency['type'];

        return match ($type) {
            'orphan_removal_nullable_fk' => DescriptionHighlighter::highlight(
                "Field {field} in {class} has {orphan}, but {inverseField} in {target} has {nullable}. This is inconsistent: orphans should be deleted, not set to NULL.",
                [
                    'field' => $fieldName,
                    'class' => $entityClass,
                    'orphan' => 'orphanRemoval=true',
                    'inverseField' => $inverseField,
                    'target' => $targetEntity,
                    'nullable' => 'nullable=true',
                ],
            ),

            'cascade_remove_set_null' => DescriptionHighlighter::highlight(
                "Field {field} has {cascade}, but {inverseField} in {target} has {onDelete}. Behavior differs depending on how you delete (ORM vs direct SQL).",
                [
                    'field' => $fieldName,
                    'cascade' => 'cascade="remove"',
                    'inverseField' => $inverseField,
                    'target' => $targetEntity,
                    'onDelete' => 'onDelete="SET NULL"',
                ],
            ),

            'orphan_removal_no_persist' => DescriptionHighlighter::highlight(
                "Field {field} has {orphan} but no {cascade}. You can delete children but not automatically save new ones.",
                [
                    'field' => $fieldName,
                    'orphan' => 'orphanRemoval=true',
                    'cascade' => 'cascade="persist"',
                ],
            ),

            'ondelete_cascade_no_orm' => DescriptionHighlighter::highlight(
                "Field {field} in {target} has {onDelete} in database, but no {cascade} in ORM. Behavior differs between ORM and database deletes.",
                [
                    'field' => $inverseField,
                    'target' => $targetEntity,
                    'onDelete' => 'onDelete="CASCADE"',
                    'cascade' => 'cascade="remove"',
                ],
            ),

            default => DescriptionHighlighter::highlight(
                "Bidirectional inconsistency detected between {field} and {inverseField}.",
                [
                    'field' => $fieldName,
                    'inverseField' => $inverseField,
                ],
            ),
        };
    }

    /**
     * @param array{type: string, severity: string, inverse_field: string} $inconsistency
     */
    private function createBidirectionalSuggestion(
        string $entityClass,
        string $fieldName,
        string $targetEntity,
        string $inverseField,
        array $inconsistency,
    ): SuggestionInterface {
        $type            = $inconsistency['type'];
        $shortClassName  = $this->getShortClassName($entityClass);
        $shortTargetName = $this->getShortClassName($targetEntity);

        return match ($type) {
            'orphan_removal_nullable_fk' => $this->createOrphanRemovalNullableSuggestion(
                $shortClassName,
                $fieldName,
                $shortTargetName,
                $inverseField,
            ),
            // TODO: Implement these suggestion methods
            'cascade_remove_set_null',
            'orphan_removal_no_persist',
            'ondelete_cascade_no_orm' => $this->suggestionFactory->createFromTemplate(
                'bidirectional_inconsistency_generic',
                [
                    'title'       => 'Fix Bidirectional Inconsistency: ' . ucfirst(str_replace('_', ' ', $type)),
                    'description' => DescriptionHighlighter::highlight(
                        'The bidirectional association between {class}::{field} and {target}::{inverseField} has inconsistent configuration. Please review the cascade and orphanRemoval settings.',
                        [
                            'class' => $shortClassName,
                            'field' => '$' . $fieldName,
                            'target' => $shortTargetName,
                            'inverseField' => '$' . $inverseField,
                        ],
                    ),
                ],
                new SuggestionMetadata(
                    type: SuggestionType::codeQuality(),
                    severity: Severity::warning(),
                    title: 'Fix Bidirectional Inconsistency: ' . ucfirst(str_replace('_', ' ', $type)),
                ),
            ),
            default => $this->suggestionFactory->createFromTemplate(
                'bidirectional_inconsistency_generic',
                [
                    'title'       => 'Fix Bidirectional Inconsistency',
                    'description' => 'Fix the bidirectional inconsistency.',
                ],
                new SuggestionMetadata(
                    type: SuggestionType::codeQuality(),
                    severity: Severity::warning(),
                    title: 'Fix Bidirectional Inconsistency',
                ),
            ),
        };
    }

    private function createOrphanRemovalNullableSuggestion(
        string $parentClass,
        string $parentField,
        string $childClass,
        string $childField,
    ): SuggestionInterface {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'bidirectional_orphan_nullable',
            context: [
                'parent_class' => $parentClass,
                'parent_field' => $parentField,
                'child_class'  => $childClass,
                'child_field'  => $childField,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::codeQuality(),
                severity: Severity::critical(),
                title: 'orphanRemoval with nullable FK Inconsistency',
                tags: ['bidirectional', 'orphan-removal', 'nullable'],
            ),
        );
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
