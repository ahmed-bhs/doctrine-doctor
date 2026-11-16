<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Concern\MetadataAnalyzerTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\Concern\ShortClassNameTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\CompositionRelationshipDetector;
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Detects cascade="remove" on associations to independent entities.
 *
 * Example:
 * class Order {
 *     #[ORM\ManyToOne(targetEntity: Customer::class, cascade: ['remove'])]
 *     private Customer $customer;
 * }
 * $em->remove($order);
 * $em->flush();
 * // This will delete the Customer and all related Orders
 */
class CascadeRemoveOnIndependentEntityAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;
    use ShortClassNameTrait;

    /**
     * Entity patterns that are typically independent.
     */
    private const array INDEPENDENT_PATTERNS = [
        'User', 'Customer', 'Account', 'Member', 'Client',
        'Company', 'Organization', 'Team', 'Department',
        'Product', 'Category', 'Brand', 'Tag',
        'Author', 'Editor', 'Publisher',
        'Country', 'City', 'Region',
    ];

    private readonly CompositionRelationshipDetector $compositionDetector;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IssueFactoryInterface $issueFactory,
        ?CompositionRelationshipDetector $compositionDetector = null,
        private readonly ?SuggestionFactoryInterface $suggestionFactory = null,
    ) {
        // Keep detector overrideable in tests while defaulting to concrete implementation.
        $this->compositionDetector = $compositionDetector ?? new CompositionRelationshipDetector($entityManager);
    }

    public function analyzeMetadata(): IssueCollection
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

                foreach ($allMetadata as $metadata) {
                    assert([] === $referenceCountMap || is_array($referenceCountMap));
                    $entityIssues = $this->analyzeEntity($metadata, $referenceCountMap);

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
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    private function analyzeEntity(ClassMetadata $classMetadata, array $referenceCountMap): array
    {

        $issues      = [];
        $entityClass = $classMetadata->getName();

        foreach ($classMetadata->getAssociationMappings() as $fieldName => $associationMapping) {
            $cascade      = MappingHelper::getArray($associationMapping, 'cascade') ?? [];
            $targetEntity = MappingHelper::getString($associationMapping, 'targetEntity');

            // Check if has cascade remove
            if (!in_array('remove', $cascade, true) && !in_array('all', $cascade, true)) {
                continue;
            }

            $type = MappingHelper::getAssociationType($associationMapping);

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
                    $issue    = $this->createHighIssue($entityClass, $fieldName, $associationMapping, $referenceCountMap, 'OneToMany');
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
                    $issue    = $this->createHighIssue($entityClass, $fieldName, $associationMapping, $referenceCountMap, 'OneToOne');
                    $issues[] = $issue;
                }

                continue;
            }

            // HIGH: cascade="remove" on ManyToMany to independent entity
            if (null !== $targetEntity && ClassMetadata::MANY_TO_MANY === $type && $this->isIndependentEntity($targetEntity, $referenceCountMap)) {
                $issue    = $this->createHighIssue($entityClass, $fieldName, $associationMapping, $referenceCountMap, 'ManyToMany');
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
                $type = MappingHelper::getAssociationType($association);

                // Only check ManyToOne (the "many" side that points to parent)
                if (ClassMetadata::MANY_TO_ONE !== $type) {
                    continue;
                }

                // Check if this ManyToOne is a composition relationship
                if ($this->isCompositionRelationship($association)) {
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
    private function isCompositionRelationship(array|object $association): bool
    {
        // Indicator 1: Check if FK is NOT NULL
        $joinColumns = MappingHelper::getArray($association, 'joinColumns') ?? [];
        if ([] !== $joinColumns) {
            $firstJoinColumn = reset($joinColumns);
            $nullable = is_array($firstJoinColumn)
                ? ($firstJoinColumn['nullable'] ?? false)
                : ($firstJoinColumn->nullable ?? false);

            if (!$nullable) {
                return true;
            }
        }

        // Indicator 2: Check if inverse side has orphanRemoval=true
        $targetEntity = MappingHelper::getString($association, 'targetEntity');
        $mappedBy = MappingHelper::getString($association, 'inversedBy');

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

    private function createCriticalIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        array $referenceCountMap,
    ): IntegrityIssue {
        $targetEntity   = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $cascade        = MappingHelper::getArray($mapping, 'cascade') ?? [];
        $referenceCount = $referenceCountMap[$targetEntity] ?? 0;

        /** @var IntegrityIssue $codeQualityIssue */
        $codeQualityIssue = $this->issueFactory->createFromArray(['type' => IssueType::INTEGRITY_GENERIC->value,
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
            "Field {field} in entity {class} has cascade {cascade} on a {type} relation to {target}, which {refCount} entities reference. Deleting a {shortClass} also deletes its {target}, even while other records still point to it.",
            [
                'field' => $fieldName,
                'class' => $entityClass,
                'cascade' => '"remove"',
                'type' => 'ManyToOne',
                'target' => $targetEntity,
                'shortClass' => $this->shortClassName($entityClass),
                'refCount' => (string) $referenceCount,
            ],
        );
        $codeQualityIssue->setMessage($message);
        $codeQualityIssue->setSuggestion($this->manyToOneSuggestion($entityClass, $fieldName, $targetEntity));

        return $codeQualityIssue;
    }

    private function manyToOneSuggestion(string $entityClass, string $fieldName, string $targetEntity): ?SuggestionInterface
    {
        if (!$this->suggestionFactory instanceof SuggestionFactoryInterface) {
            return null;
        }

        $owner = $this->shortClassName($entityClass);
        $target = $this->shortClassName($targetEntity);
        $code = <<<PHP
            // In {$owner}
            // Before: deleting a {$owner} also deletes the {$target} it points to
            #[ORM\\ManyToOne(targetEntity: {$target}::class, cascade: ['remove'])]
            private {$target} \${$fieldName};

            // After: deleting a {$owner} deletes only the {$owner}
            // and when a {$target} is deleted, the database deletes its {$owner} rows
            #[ORM\\ManyToOne(targetEntity: {$target}::class)]
            #[ORM\\JoinColumn(onDelete: 'CASCADE')]
            private {$target} \${$fieldName};
            PHP;

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/code_suggestion',
            context: [
                'title' => 'Remove cascade remove from the ManyToOne',
                'description' => sprintf('A cascade follows the relation from %s to %s, the wrong way for a many-to-one: one %s is shared by many %s rows. Cascade from the parent instead, or let the database delete the children with onDelete.', $owner, $target, $target, $owner),
                'code' => $code,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::critical(),
                title: sprintf('Remove cascade remove from %s::$%s', $owner, $fieldName),
                tags: ['cascade', 'data-loss'],
            ),
        );
    }

    private function createHighIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        array $referenceCountMap,
        string $associationType,
    ): IntegrityIssue {
        $targetEntity   = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $cascade        = MappingHelper::getArray($mapping, 'cascade') ?? [];
        $referenceCount = $referenceCountMap[$targetEntity] ?? 0;

        /** @var IntegrityIssue $codeQualityIssue */
        $codeQualityIssue = $this->issueFactory->createFromArray(['type' => IssueType::INTEGRITY_GENERIC->value,
            'entity'           => $entityClass,
            'field'            => $fieldName,
            'association_type' => $associationType,
            'target_entity'    => $targetEntity,
            'cascade'          => $cascade,
            'reference_count'  => $referenceCount,
        ]);

        $codeQualityIssue->setSeverity('critical');
        $codeQualityIssue->setTitle(sprintf('cascade="remove" on %s to Independent Entity', $associationType));

        $message = DescriptionHighlighter::highlight(
            "Field {field} in entity {class} has cascade {cascade} on a {type} relation to independent entity {target}, which {refCount} entities reference. Deleting a {shortClass} also deletes all its related {target} rows, even those other records still use.",
            [
                'field' => $fieldName,
                'class' => $entityClass,
                'cascade' => '"remove"',
                'type' => $associationType,
                'target' => $targetEntity,
                'shortClass' => $this->shortClassName($entityClass),
                'refCount' => (string) $referenceCount,
            ],
        );
        $codeQualityIssue->setMessage($message);

        return $codeQualityIssue;
    }
}
