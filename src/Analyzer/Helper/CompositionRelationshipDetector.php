<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Helper;

use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Detects composition relationships in Doctrine associations following SOLID principles.
 *
 * A composition relationship is when:
 * - Parent OWNS the child (child cannot exist without parent)
 * - Child lifecycle is managed by parent
 * - Deleting parent should delete children
 *
 * This class implements various heuristics to detect composition vs aggregation:
 *
 * 1. **OneToOne with orphanRemoval**: Strong composition indicator
 *    Example: AdminUser → AvatarImage
 *
 * 2. **OneToMany with orphanRemoval + cascade remove**: Composition
 *    Example: Order → OrderItems
 *
 * 3. **ManyToOne with unique FK**: Actually a 1:1 composition (technical ManyToOne)
 *    Example: PaymentMethod → GatewayConfig (unique constraint on FK)
 *
 * 4. **Non-nullable FK on child side**: Child cannot exist without parent
 *    Combined with orphanRemoval on parent = composition
 *
 * 5. **Exclusive ownership**: Child is referenced by only one parent entity type
 *    Example: OrderItem is only referenced by Order
 */
final class CompositionRelationshipDetector
{
    /**
     * @var array<string, array<string, bool>>|null Cache of target entity -> referencing entities
     * OPTIMIZATION: Builds this once instead of O(n²) scans
     */
    private ?array $exclusiveOwnershipCache = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Check if a OneToOne association is a composition relationship.
     *
     * OneToOne with orphanRemoval is typically a composition.
     * Example: User → Profile, AdminUser → AvatarImage
     *
     * @param array<string, mixed>|object $mapping The association mapping
     * @return bool True if this is a composition relationship
     */
    public function isOneToOneComposition(array|object $mapping): bool
    {
        // Check orphanRemoval (strongest indicator for OneToOne)
        $orphanRemoval = MappingHelper::getBool($mapping, 'orphanRemoval') ?? false;
        if ($orphanRemoval) {
            return true;
        }

        // Check cascade remove (also indicates composition)
        $cascade = MappingHelper::getArray($mapping, 'cascade') ?? [];
        $hasCascadeRemove = in_array('remove', $cascade, true) || in_array('all', $cascade, true);
        if ($hasCascadeRemove) {
            return true;
        }

        return false;
    }

    /**
     * Check if a OneToMany association is a composition relationship.
     *
     * Indicators:
     * - Has orphanRemoval=true
     * - Has cascade=remove
     * - Child entity name suggests composition (e.g., OrderItem, AddressLine)
     * - Child is exclusively owned by this parent
     *
     * @param ClassMetadata<object> $parentMetadata The parent entity metadata
     * @param array<string, mixed>|object $mapping The association mapping
     * @return bool True if this is a composition relationship
     */
    public function isOneToManyComposition(ClassMetadata $parentMetadata, array|object $mapping): bool
    {
        // Strong indicator: orphanRemoval=true
        $orphanRemoval = MappingHelper::getBool($mapping, 'orphanRemoval') ?? false;
        if ($orphanRemoval) {
            return true;
        }

        // Check cascade remove
        $cascade = MappingHelper::getArray($mapping, 'cascade') ?? [];
        $hasCascadeRemove = in_array('remove', $cascade, true) || in_array('all', $cascade, true);

        if (!$hasCascadeRemove) {
            // No cascade remove = likely not composition
            return false;
        }

        // Has cascade remove, but no orphanRemoval.
        // Check other indicators to determine if it's composition.

        $targetEntity = MappingHelper::getString($mapping, 'targetEntity');
        if (null === $targetEntity) {
            return false;
        }

        // Check if child entity name suggests composition
        if ($this->childNameSuggestsComposition($targetEntity)) {
            return true;
        }

        // Check if child is exclusively owned by this parent
        if ($this->isExclusivelyOwned($targetEntity, $parentMetadata->getName())) {
            return true;
        }

        return false;
    }

    /**
     * Check if a ManyToOne with cascade remove is actually a 1:1 composition.
     *
     * Sometimes developers use ManyToOne for technical reasons even though
     * it's semantically a 1:1 composition relationship.
     *
     * Indicators:
     * - FK has UNIQUE constraint (enforces 1:1 at DB level)
     * - Inverse side is OneToOne (not OneToMany)
     * - Target is exclusively owned by this entity
     * - Target name doesn't match independent entity patterns
     *
     * @param ClassMetadata<object> $metadata The entity metadata
     * @param array<string, mixed>|object $association The association mapping
     * @return bool True if this ManyToOne is actually a 1:1 composition
     */
    public function isManyToOneActuallyOneToOneComposition(
        ClassMetadata $metadata,
        array|object $association,
    ): bool {
        // Check for UNIQUE constraint on FK column
        if ($this->hasUniqueConstraintOnFK($metadata, $association)) {
            return true;
        }

        // Check if inverse side is OneToOne (not OneToMany)
        if ($this->hasOneToOneInverseMapping($association)) {
            return true;
        }

        // Check if target is exclusively owned AND doesn't match independent patterns
        $targetEntity = MappingHelper::getString($association, 'targetEntity');
        if (null !== $targetEntity
            && $this->isExclusivelyOwned($targetEntity, $metadata->getName())
            && !$this->matchesIndependentPattern($targetEntity)) {
            return true;
        }

        return false;
    }

    /**
     * Pre-warm the exclusive ownership cache to avoid O(n²) scans.
     * This builds a complete map of which entities reference which targets.
     */
    private function warmUpExclusiveOwnershipCache(): void
    {
        if (null !== $this->exclusiveOwnershipCache) {
            return; // Already cached
        }

        $this->exclusiveOwnershipCache = [];

        try {
            $metadataFactory = $this->entityManager->getMetadataFactory();
            $allMetadata = $metadataFactory->getAllMetadata();

            // Build complete mapping in single pass: O(n*m) instead of O(n²*m)
            foreach ($allMetadata as $metadata) {
                $entityClass = $metadata->getName();

                foreach ($metadata->getAssociationMappings() as $association) {
                    $assocTarget = MappingHelper::getString($association, 'targetEntity');

                    if (null !== $assocTarget) {
                        if (!isset($this->exclusiveOwnershipCache[$assocTarget])) {
                            $this->exclusiveOwnershipCache[$assocTarget] = [];
                        }
                        $this->exclusiveOwnershipCache[$assocTarget][$entityClass] = true;
                    }
                }
            }
        } catch (\Throwable) {
            // On failure, cache will be empty and lookups will be conservative
        }
    }

    /**
     * Check if FK column has UNIQUE constraint, making it effectively 1:1.
     *
     * @param ClassMetadata<object> $metadata The entity metadata
     * @param array<string, mixed>|object $association The association mapping
     * @return bool True if FK has unique constraint
     */
    private function hasUniqueConstraintOnFK(ClassMetadata $metadata, array|object $association): bool
    {
        $joinColumns = MappingHelper::getArray($association, 'joinColumns') ?? [];
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
            // If FK is the ONLY column in unique constraint, it's 1:1
            if (1 === count($columns) && in_array($fkColumnName, $columns, true)) {
                return true;
            }
        }

        // Check for unique index on single FK column
        $indexes = $table['indexes'] ?? [];
        foreach ($indexes as $index) {
            $columns = $index['columns'] ?? [];
            $unique = $index['unique'] ?? false;

            if ($unique && 1 === count($columns) && in_array($fkColumnName, $columns, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if target entity has OneToOne inverse mapping (not OneToMany).
     *
     * @param array<string, mixed>|object $association The association mapping
     * @return bool True if inverse is OneToOne
     */
    private function hasOneToOneInverseMapping(array|object $association): bool
    {
        try {
            $targetEntity = MappingHelper::getString($association, 'targetEntity');
            $inversedBy = MappingHelper::getString($association, 'inversedBy');

            if (null === $targetEntity || null === $inversedBy) {
                return false;
            }

            /** @var class-string $targetEntity */
            $targetMetadata = $this->entityManager->getMetadataFactory()->getMetadataFor($targetEntity);
            $inverseMappings = $targetMetadata->getAssociationMappings();
            $inverseMapping = $inverseMappings[$inversedBy] ?? null;

            if (null === $inverseMapping) {
                return false;
            }

            $inverseType = $this->getAssociationTypeConstant($inverseMapping);

            return ClassMetadata::ONE_TO_ONE === $inverseType;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if child entity name matches composition patterns.
     *
     * Composition patterns: OrderItem, AddressLine, CartEntry, etc.
     *
     * @param string $entityClass The child entity class name
     * @return bool True if name suggests composition
     */
    private function childNameSuggestsComposition(string $entityClass): bool
    {
        $compositionPatterns = [
            'Item', 'Line', 'Entry', 'Detail', 'Part', 'Component',
            'Element', 'Record', 'Row', 'Member', 'Piece',
        ];
        return array_any($compositionPatterns, fn ($pattern) => str_ends_with($entityClass, (string) $pattern));
    }

    /**
     * Check if entity name matches independent entity patterns.
     *
     * Independent entities: User, Customer, Product, Category, etc.
     *
     * @param string $entityClass The entity class name
     * @return bool True if name matches independent pattern
     */
    private function matchesIndependentPattern(string $entityClass): bool
    {
        $independentPatterns = [
            'User', 'Customer', 'Account', 'Member', 'Client',
            'Company', 'Organization', 'Team', 'Department',
            'Product', 'Category', 'Brand', 'Tag',
            'Author', 'Editor', 'Publisher',
            'Country', 'City', 'Region', 'Address',
        ];
        return array_any($independentPatterns, fn ($pattern) => str_contains($entityClass, (string) $pattern));
    }

    /**
     * Check if target entity is exclusively owned by one parent entity type.
     *
     * If only ONE entity type references this target, it's exclusively owned.
     *
     * OPTIMIZED: Uses cached mapping instead of O(n²) scan.
     * Cache is built once in warmUpExclusiveOwnershipCache() and reused.
     *
     * @param string $targetEntity The target entity class
     * @param string $excludeParent The parent entity to exclude from count
     * @return bool True if target is exclusively owned
     */
    private function isExclusivelyOwned(string $targetEntity, string $excludeParent): bool
    {
        // OPTIMIZED: Build cache once, then O(1) lookups
        $this->warmUpExclusiveOwnershipCache();

        // Fast O(1) lookup in cached mapping
        $referencingEntities = $this->exclusiveOwnershipCache[$targetEntity] ?? [];

        // If target is referenced by exactly ONE entity type (the parent), it's exclusively owned
        return 1 === count($referencingEntities) && isset($referencingEntities[$excludeParent]);
    }

    /**
     * Get association type constant in a version-agnostic way.
     *
     * @param array<string, mixed>|object $mapping The association mapping
     * @return int The association type constant
     */
    private function getAssociationTypeConstant(array|object $mapping): int
    {
        $type = MappingHelper::getInt($mapping, 'type');
        if (null !== $type) {
            return $type;
        }

        if (is_object($mapping)) {
            $className = $mapping::class;

            if (str_contains($className, 'ManyToOne')) {
                return ClassMetadata::MANY_TO_ONE;
            }

            if (str_contains($className, 'OneToMany')) {
                return ClassMetadata::ONE_TO_MANY;
            }

            if (str_contains($className, 'ManyToMany')) {
                return ClassMetadata::MANY_TO_MANY;
            }

            if (str_contains($className, 'OneToOne')) {
                return ClassMetadata::ONE_TO_ONE;
            }
        }

        return 0;
    }
}
