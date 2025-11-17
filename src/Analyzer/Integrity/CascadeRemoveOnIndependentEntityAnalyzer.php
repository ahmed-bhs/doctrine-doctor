<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\CompositionRelationshipDetector;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Webmozart\Assert\Assert;

/**
 * Detects cascade="remove" on associations to independent entities.
 *
 * Example:
 * class Order {
 *     @ManyToOne(targetEntity="Customer", cascade={"remove"})
 *     private Customer $customer;
 * }
 * $em->remove($order);
 * $em->flush();
 * // This will delete the Customer and all related Orders
 */
class CascadeRemoveOnIndependentEntityAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Entity patterns that are typically independent.
     */
    private const INDEPENDENT_PATTERNS = [
        'User', 'Customer', 'Account', 'Member', 'Client',
        'Company', 'Organization', 'Team', 'Department',
        'Product', 'Category', 'Brand', 'Tag',
        'Author', 'Editor', 'Publisher',
        'Country', 'City', 'Region',
    ];

    private readonly CompositionRelationshipDetector $compositionDetector;

    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        ?CompositionRelationshipDetector $compositionDetector = null,
    ) {
        // Dependency Injection with fallback for backwards compatibility
        $this->compositionDetector = $compositionDetector ?? new CompositionRelationshipDetector($entityManager);
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

                // Build reference count map
                $referenceCountMap = $this->buildReferenceCountMap($allMetadata);

                Assert::isIterable($allMetadata, '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    assert([] === $referenceCountMap || is_array($referenceCountMap));
                    $entityIssues = $this->analyzeEntity($metadata, $referenceCountMap);

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
        return 'Cascade Remove on Independent Entity Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects cascade="remove" on ManyToOne/ManyToMany to independent entities';
    }

    /**
     * @return array<string, int>
     */
    private function buildReferenceCountMap(array $allMetadata): array
    {
        /** @var array<string, int> $map */
        $map = [];

        Assert::isIterable($allMetadata, '$allMetadata must be iterable');

        foreach ($allMetadata as $metadata) {
            foreach ($metadata->getAssociationMappings() as $mapping) {
                $targetEntity = $mapping['targetEntity'] ?? null;

                if ($targetEntity) {
                    $map[$targetEntity] = ($map[$targetEntity] ?? 0) + 1;
                }
            }
        }

        return $map;
    }

    /**
     * @param ClassMetadata<object>    $classMetadata
     * @param array<string, float|int> $referenceCountMap
     * @return array<\AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function analyzeEntity(ClassMetadata $classMetadata, array $referenceCountMap): array
    {

        $issues      = [];
        $entityClass = $classMetadata->getName();

        foreach ($classMetadata->getAssociationMappings() as $fieldName => $associationMapping) {
            $cascade      = MappingHelper::getArray($associationMapping, 'cascade') ?? [];
            $targetEntity = MappingHelper::getString($associationMapping, 'targetEntity') ?? null;

            // Check if has cascade remove
            if (!in_array('remove', $cascade, true) && !in_array('all', $cascade, true)) {
                continue;
            }

            $type = $this->getAssociationTypeConstant($associationMapping);

            // CRITICAL: cascade="remove" on ManyToOne
            if (ClassMetadata::MANY_TO_ONE === $type) {
                // Check if this is actually a 1:1 composition relationship
                // (ManyToOne used for technical reasons but semantically 1:1)
                // Use our SOLID composition detector
                if ($this->compositionDetector->isManyToOneActuallyOneToOneComposition($classMetadata, $associationMapping)) {
                    // This is acceptable - it's a composition relationship
                    continue;
                }

                $issue    = $this->createCriticalIssue($entityClass, $fieldName, $associationMapping, $referenceCountMap);
                $issues[] = $issue;

                continue;
            }

            // Check OneToMany - use composition detector
            if (ClassMetadata::ONE_TO_MANY === $type) {
                // OneToMany with cascade remove is usually fine if it's composition
                if ($this->compositionDetector->isOneToManyComposition($classMetadata, $associationMapping)) {
                    continue;
                }

                // Has cascade remove but not a clear composition - might be problematic
                if (null !== $targetEntity && $this->isIndependentEntity($targetEntity, $referenceCountMap)) {
                    $issue    = $this->createHighIssue($entityClass, $fieldName, $associationMapping, $referenceCountMap);
                    $issues[] = $issue;
                }

                continue;
            }

            // Check OneToOne - use composition detector
            if (ClassMetadata::ONE_TO_ONE === $type) {
                // OneToOne with orphanRemoval is typically composition
                if ($this->compositionDetector->isOneToOneComposition($associationMapping)) {
                    continue;
                }

                // Has cascade remove but no orphanRemoval - check if independent
                if (null !== $targetEntity && $this->isIndependentEntity($targetEntity, $referenceCountMap)) {
                    $issue    = $this->createHighIssue($entityClass, $fieldName, $associationMapping, $referenceCountMap);
                    $issues[] = $issue;
                }

                continue;
            }

            // HIGH: cascade="remove" on ManyToMany to independent entity
            if (null !== $targetEntity && ClassMetadata::MANY_TO_MANY === $type && $this->isIndependentEntity($targetEntity, $referenceCountMap)) {
                $issue    = $this->createHighIssue($entityClass, $fieldName, $associationMapping, $referenceCountMap);
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    private function isIndependentEntity(string $entityClass, array $referenceCountMap): bool
    {
        // First, check if it's a DEPENDENT entity (value object)
        // If so, it's NOT independent, so return false
        if ($this->isDependentEntity($entityClass)) {
            return false;
        }

        // Check name patterns for clearly independent entities
        foreach (self::INDEPENDENT_PATTERNS as $pattern) {
            if (str_contains($entityClass, $pattern)) {
                return true;
            }
        }

        // Check reference count: if referenced by multiple entities, likely independent
        $referenceCount = $referenceCountMap[$entityClass] ?? 0;

        return $referenceCount > 1;
    }

    /**
     * Detect if an entity is dependent (value object/composition) based on STRUCTURAL analysis.
     *
     * A dependent entity is characterized by:
     * 1. Foreign key to parent is NOT NULL (cannot exist without parent)
     * 2. Has unique constraint involving the parent FK (e.g., user_id + provider UNIQUE)
     * 3. Is referenced by only ONE other entity (not shared)
     * 4. Has orphanRemoval=true on the inverse side
     *
     * This is GENERIC and works regardless of naming conventions.
     */
    private function isDependentEntity(string $entityClass): bool
    {
        try {
            /** @var class-string $entityClass */
            $classMetadataFactory = $this->entityManager->getMetadataFactory();
            $metadata = $classMetadataFactory->getMetadataFor($entityClass);

            // Analyze ALL ManyToOne associations to find parent relationships
            foreach ($metadata->getAssociationMappings() as $association) {
                $type = $this->getAssociationTypeConstant($association);

                // Only check ManyToOne (the "many" side that points to parent)
                if (ClassMetadata::MANY_TO_ONE !== $type) {
                    continue;
                }

                // Check if this ManyToOne is a composition relationship
                if ($this->isCompositionRelationship($metadata, $association)) {
                    return true; // This entity is dependent on its parent
                }
            }

            return false;
        } catch (\Throwable) {
            // If we can't analyze the entity, fall back to conservative approach
            return false;
        }
    }

    /**
     * Check if a ManyToOne relationship indicates composition (dependent entity).
     *
     * Indicators:
     * 1. FK is NOT NULL (entity cannot exist without parent)
     * 2. Unique constraint with FK (e.g., user_id + provider = one token per provider per user)
     * 3. Inverse side has orphanRemoval=true
     */
    private function isCompositionRelationship(ClassMetadata $metadata, array|object $association): bool
    {
        // Indicator 1: Check if FK is NOT NULL
        $joinColumns = MappingHelper::getArray($association, 'joinColumns') ?? [];
        if ([] !== $joinColumns) {
            $firstJoinColumn = reset($joinColumns);
            $nullable = is_array($firstJoinColumn)
                ? ($firstJoinColumn['nullable'] ?? false)
                : ($firstJoinColumn->nullable ?? false);

            // If FK is NOT NULL, it's a strong indicator of composition
            if (!$nullable) {
                // Additional check: look for unique constraints involving this FK
                if ($this->hasUniqueConstraintWithFK($metadata, $joinColumns)) {
                    return true; // Strong composition indicator
                }
            }
        }

        // Indicator 2: Check if inverse side has orphanRemoval=true
        $targetEntity = MappingHelper::getString($association, 'targetEntity');
        $mappedBy = MappingHelper::getString($association, 'inversedBy') ?? null;

        if (null !== $targetEntity && null !== $mappedBy) {
            try {
                /** @var class-string $targetEntity */
                $targetMetadata = $this->entityManager->getMetadataFactory()->getMetadataFor($targetEntity);
                $inverseMappings = $targetMetadata->getAssociationMappings();
                $inverseMapping = $inverseMappings[$mappedBy] ?? null;

                if (null !== $inverseMapping) {
                    $orphanRemoval = MappingHelper::getBool($inverseMapping, 'orphanRemoval') ?? false;
                    if ($orphanRemoval) {
                        return true; // Parent explicitly manages lifecycle
                    }
                }
            } catch (\Throwable) {
                // Cannot check inverse, continue
            }
        }

        return false;
    }

    /**
     * Check if entity has a unique constraint that includes the FK column.
     * Example: user_id + provider UNIQUE (one OAuth account per provider per user)
     */
    private function hasUniqueConstraintWithFK(ClassMetadata $metadata, array $joinColumns): bool
    {
        if ([] === $joinColumns) {
            return false;
        }

        $firstJoinColumn = reset($joinColumns);
        $fkColumnName = is_array($firstJoinColumn)
            ? ($firstJoinColumn['name'] ?? null)
            : ($firstJoinColumn->name ?? null);

        if (null === $fkColumnName) {
            return false;
        }

        // Check table unique constraints
        $table = $metadata->table ?? [];
        $uniqueConstraints = $table['uniqueConstraints'] ?? [];

        foreach ($uniqueConstraints as $constraint) {
            $columns = $constraint['columns'] ?? [];
            if (in_array($fkColumnName, $columns, true)) {
                return true; // FK is part of a unique constraint
            }
        }

        return false;
    }

    private function createCriticalIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        array $referenceCountMap,
    ): IntegrityIssue {
        $targetEntity   = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $cascade        = MappingHelper::getArray($mapping, 'cascade') ?? [];
        $referenceCount = $referenceCountMap[$targetEntity] ?? 0;

        $codeQualityIssue = new IntegrityIssue([
            'entity'           => $entityClass,
            'field'            => $fieldName,
            'association_type' => 'ManyToOne',
            'target_entity'    => $targetEntity,
            'cascade'          => $cascade,
            'reference_count'  => $referenceCount,
        ]);

        $codeQualityIssue->setSeverity('critical');
        $codeQualityIssue->setTitle('cascade="remove" on ManyToOne (Data Loss Risk)');

        $message = DescriptionHighlighter::highlight(
            "Field {field} in entity {class} has {cascade} on {type} relation to {target}. Deleting a {shortClass} will also delete the {target}, which may be referenced by other entities. {target} is referenced by {refCount} entities.",
            [
                'field' => $fieldName,
                'class' => $entityClass,
                'cascade' => '"remove"',
                'type' => 'ManyToOne',
                'target' => $targetEntity,
                'shortClass' => $this->getShortClassName($entityClass),
                'refCount' => (string) $referenceCount,
            ],
        );
        $codeQualityIssue->setMessage($message);

        return $codeQualityIssue;
    }

    private function createHighIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        array $referenceCountMap,
    ): IntegrityIssue {
        $targetEntity   = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $cascade        = MappingHelper::getArray($mapping, 'cascade') ?? [];
        $referenceCount = $referenceCountMap[$targetEntity] ?? 0;

        $codeQualityIssue = new IntegrityIssue([
            'entity'           => $entityClass,
            'field'            => $fieldName,
            'association_type' => 'ManyToMany',
            'target_entity'    => $targetEntity,
            'cascade'          => $cascade,
            'reference_count'  => $referenceCount,
        ]);

        $codeQualityIssue->setSeverity('critical');
        $codeQualityIssue->setTitle('cascade="remove" on ManyToMany to Independent Entity');

        $message = DescriptionHighlighter::highlight(
            "Field {field} in entity {class} has {cascade} on {type} relation to independent entity {target}. Deleting a {shortClass} will delete all related {target}s, even if other entities reference them. {target} is referenced by {refCount} entities.",
            [
                'field' => $fieldName,
                'class' => $entityClass,
                'cascade' => '"remove"',
                'type' => 'ManyToMany',
                'target' => $targetEntity,
                'shortClass' => $this->getShortClassName($entityClass),
                'refCount' => (string) $referenceCount,
            ],
        );
        $codeQualityIssue->setMessage($message);

        return $codeQualityIssue;
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }

    /**
     * Get association type constant in a version-agnostic way.
     * Doctrine ORM 2.x uses 'type' field, 3.x/4.x uses specific mapping classes.
     */
    private function getAssociationTypeConstant(array|object $mapping): int
    {
        // Try to get type from array (Doctrine ORM 2.x)
        $type = MappingHelper::getInt($mapping, 'type');
        if (null !== $type) {
            return $type;
        }

        // Doctrine ORM 3.x/4.x: determine from class name
        if (is_object($mapping)) {
            $className = $mapping::class;

            if (str_contains($className, 'ManyToOne')) {
                return (int) ClassMetadata::MANY_TO_ONE;
            }

            if (str_contains($className, 'OneToMany')) {
                return (int) ClassMetadata::ONE_TO_MANY;
            }

            if (str_contains($className, 'ManyToMany')) {
                return (int) ClassMetadata::MANY_TO_MANY;
            }

            if (str_contains($className, 'OneToOne')) {
                return (int) ClassMetadata::ONE_TO_ONE;
            }
        }

        return 0; // Unknown
    }
}
