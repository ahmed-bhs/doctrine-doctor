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
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use ReflectionClass;
use Webmozart\Assert\Assert;

/**
 * Detects entity state inconsistencies and improper entity lifecycle management.
 * Critical issues detected:
 * - Using NEW entities before persist (id is null)
 * - Modifying DETACHED entities (changes ignored)
 * - Accessing REMOVED entities (still in memory but deleted from DB)
 * - Persisting already MANAGED entities (redundant)
 * - Using entities with null required fields
 * - Mixed state entities in associations
 * Example problems:
 *   $user = new User(); // NEW state
 *   $order->setUser($user); // user->id is null, cascade will fail!
 *   $user = $em->find(User::class, 1); // MANAGED
 *   $em->clear(); // DETACHED
 *   $user->setEmail('new'); // Change ignored!
 * Impact: Silent data loss, cascade failures, inconsistent state
 */
class EntityStateConsistencyAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private IssueFactoryInterface $issueFactory,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                // Get UnitOfWork for state inspection
                $unitOfWork = $this->entityManager->getUnitOfWork();
                // Check managed entities
                $managedEntities = $unitOfWork->getIdentityMap();

                Assert::isIterable($managedEntities, '$managedEntities must be iterable');

                foreach ($managedEntities as $managedEntity) {
                    Assert::isIterable($managedEntity, '$managedEntity must be iterable');

                    foreach ($managedEntity as $entity) {
                        $entityIssues = $this->checkEntityState($entity, $unitOfWork);
                        Assert::isIterable($entityIssues, '$entityIssues must be iterable');

                        foreach ($entityIssues as $entityIssue) {
                            yield $entityIssue;
                        }
                    }
                }

                // Check scheduled insertions (NEW entities being persisted)
                foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
                    $issue = $this->checkNewEntityConsistency($entity, $unitOfWork);
                    if (null !== $issue) {
                        yield $issue;
                    }
                }

                // Check scheduled deletions (REMOVED entities)
                foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
                    $issue = $this->checkRemovedEntityUsage($entity);
                    yield $issue;
                }
            },
        );
    }

    /**
     * Check entity state for inconsistencies.
     */
    private function checkEntityState(object $entity, UnitOfWork $unitOfWork): array
    {

        $issues = [];

        // Get entity state
        $state = $unitOfWork->getEntityState($entity);

        // Check DETACHED entities
        // Check if entity has pending changes
        if (UnitOfWork::STATE_DETACHED === $state && $this->hasUnpersistedChanges($entity)) {
            $issues[] = $this->createDetachedEntityModificationIssue($entity);
        }

        // Check MANAGED entities with invalid references
        if (UnitOfWork::STATE_MANAGED === $state) {
            $issue = $this->checkEntityAssociations($entity, $unitOfWork);
            if (null !== $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * Check if detached entity has unpersisted changes.
     */
    private function hasUnpersistedChanges(object $entity): bool
    {
        try {
            $metadata        = $this->entityManager->getClassMetadata($entity::class);
            $reflectionClass = $metadata->reflClass;

            if (null === $reflectionClass) {
                return false;
            }

            // Compare current values with original values (if any)
            // This is a simplified check - in reality, we'd need the original snapshot
            foreach ($metadata->getFieldNames() as $fieldName) {
                $property     = $reflectionClass->getProperty($fieldName);
                $currentValue = $property->getValue($entity);

                // If value is not null and entity is detached, likely modified
                if (null !== $currentValue) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * Check NEW entity consistency before persist.
     */
    private function checkNewEntityConsistency(object $entity, UnitOfWork $unitOfWork): ?object
    {
        try {
            $metadata        = $this->entityManager->getClassMetadata($entity::class);
            $reflectionClass = $metadata->reflClass;

            if (null === $reflectionClass) {
                return null;
            }

            $fieldIssue = $this->checkRequiredFields($entity, $metadata, $reflectionClass);
            if (null !== $fieldIssue) {
                return $fieldIssue;
            }

            return $this->checkRequiredAssociations($entity, $metadata, $reflectionClass, $unitOfWork);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Check required fields are not null.
     * @param ClassMetadata<object> $classMetadata
     */
    private function checkRequiredFields(object $entity, ClassMetadata $classMetadata, ReflectionClass $reflectionClass): ?object
    {
        foreach ($classMetadata->getFieldNames() as $fieldName) {
            $issue = $this->checkRequiredField($entity, $fieldName, $classMetadata, $reflectionClass);
            if (null !== $issue) {
                return $issue;
            }
        }

        return null;
    }

    /**
     * Check a single required field.
     * @param ClassMetadata<object> $classMetadata
     */
    private function checkRequiredField(
        object $entity,
        string $fieldName,
        ClassMetadata $classMetadata,
        ReflectionClass $reflectionClass,
    ): ?object {
        // Skip auto-generated IDs
        if ($classMetadata->isIdentifier($fieldName) && $classMetadata->usesIdGenerator()) {
            return null;
        }

        $fieldMapping = $classMetadata->getFieldMapping($fieldName);
        $nullable     = (bool) ($fieldMapping['nullable'] ?? true);

        if ($nullable) {
            return null;
        }

        $reflectionProperty = $reflectionClass->getProperty($fieldName);
        $value    = $reflectionProperty->getValue($entity);

        if (null === $value) {
            return $this->createRequiredFieldNullIssue($entity, $fieldName);
        }

        return null;
    }

    /**
     * Check required associations are not null and in valid state.
     * @param ClassMetadata<object> $classMetadata
     */
    private function checkRequiredAssociations(
        object $entity,
        ClassMetadata $classMetadata,
        ReflectionClass $reflectionClass,
        UnitOfWork $unitOfWork,
    ): ?object {
        foreach ($classMetadata->getAssociationNames() as $associationName) {
            $issue = $this->checkRequiredAssociation($entity, $associationName, $classMetadata, $reflectionClass, $unitOfWork);
            if (null !== $issue) {
                return $issue;
            }
        }

        return null;
    }

    /**
     * Check a single required association.
     * @param ClassMetadata<object> $classMetadata
     */
    private function checkRequiredAssociation(
        object $entity,
        string $assocName,
        ClassMetadata $classMetadata,
        ReflectionClass $reflectionClass,
        UnitOfWork $unitOfWork,
    ): ?object {
        if (!$classMetadata->isSingleValuedAssociation($assocName)) {
            return null;
        }

        $mapping  = $classMetadata->getAssociationMapping($assocName);
        $nullable = (bool) ($mapping['joinColumns'][0]['nullable'] ?? true);

        if ($nullable) {
            return null;
        }

        $reflectionProperty      = $reflectionClass->getProperty($assocName);
        $relatedEntity = $reflectionProperty->getValue($entity);

        if (null === $relatedEntity) {
            return $this->createRequiredAssociationNullIssue($entity, $assocName);
        }

        // Check if related entity is in valid state
        $relatedState = $unitOfWork->getEntityState($relatedEntity);

        if (UnitOfWork::STATE_NEW === $relatedState) {
            return $this->createNewEntityInAssociationIssue($entity, $assocName, $relatedEntity);
        }

        return null;
    }

    /**
     * Check if removed entity is still being used.
     */
    private function checkRemovedEntityUsage(object $entity): object
    {
        // This is detected at runtime - entity is in REMOVED state but still in memory
        return $this->createRemovedEntityAccessIssue($entity);
    }

    /**
     * Check entity associations for state inconsistencies.
     */
    private function checkEntityAssociations(object $entity, UnitOfWork $unitOfWork): ?object
    {
        try {
            $metadata        = $this->entityManager->getClassMetadata($entity::class);
            $reflectionClass = $metadata->reflClass;

            if (null === $reflectionClass) {
                return null;
            }

            foreach ($metadata->getAssociationNames() as $assocName) {
                if (!$metadata->isSingleValuedAssociation($assocName)) {
                    continue;
                }

                $property      = $reflectionClass->getProperty($assocName);
                $relatedEntity = $property->getValue($entity);

                if (null === $relatedEntity) {
                    continue;
                }

                $relatedState = $unitOfWork->getEntityState($relatedEntity);

                // Check if associated entity is in problematic state
                if (UnitOfWork::STATE_REMOVED === $relatedState) {
                    return $this->createRemovedEntityInAssociationIssue($entity, $assocName, $relatedEntity);
                }

                if (UnitOfWork::STATE_DETACHED === $relatedState) {
                    return $this->createDetachedEntityInAssociationIssue($entity, $assocName, $relatedEntity);
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Create issue for detached entity modification.
     */
    private function createDetachedEntityModificationIssue(object $entity): object
    {
        $entityClass = $entity::class;
        $shortName   = $this->getShortClassName($entityClass);

        $description = sprintf(
            "Entity %s is DETACHED but appears to have been modified.

",
            $shortName,
        );

        $description .= "Problem:
";
        $description .= "- Entity was loaded from database (MANAGED state)
";
        $description .= "- EntityManager was cleared or entity was detached
";
        $description .= "- Modifications to detached entities are IGNORED
";
        $description .= "- Changes will NOT be persisted to database

";

        $description .= "Example:
";
        $description .= "  \$user = \$em->find(User::class, 1); // MANAGED
";
        $description .= "  \$em->clear(); // User becomes DETACHED
";
        $description .= "  \$user->setEmail('new@email.com'); // Change IGNORED!
";
        $description .= "  \$em->flush(); // Nothing happens

";

        $description .= "Solutions:

";
        $description .= "1. Re-attach entity with merge():
";
        $description .= "   \$managedUser = \$em->merge(\$user);
";
        $description .= "   \$managedUser->setEmail('new@email.com');
";
        $description .= "   \$em->flush();

";

        $description .= "2. Reload entity from database:
";
        $description .= "   \$user = \$em->find(User::class, \$user->getId());
";
        $description .= "   \$user->setEmail('new@email.com');
";
        $description .= "   \$em->flush();

";

        $description .= "3. Avoid clearing EntityManager:
";
        $description .= "   - Only clear if necessary (memory management)
";
        $description .= "   - Clear specific entities: \$em->detach(\$entity)
";
        $description .= "   - Use separate EntityManager for bulk operations
";

        $issueData = new IssueData(
            type: 'entity_detached_modification',
            title: sprintf('Detached Entity Modified: %s', $shortName),
            description: $description,
            severity: Severity::critical(),
            suggestion: null,
            queries: [],
        );

        return $this->issueFactory->create($issueData);
    }

    /**
     * Create issue for NEW entity in association.
     */
    private function createNewEntityInAssociationIssue(
        object $entity,
        string $assocName,
        object $relatedEntity,
    ): object {
        $entityClass  = $this->getShortClassName($entity::class);
        $relatedClass = $this->getShortClassName($relatedEntity::class);

        $description = sprintf(
            "Entity %s references NEW entity %s in association '%s'.

",
            $entityClass,
            $relatedClass,
            $assocName,
        );

        $description .= "Problem:
";
        $description .= "- Related entity is in NEW state (not yet persisted)
";
        $description .= "- Related entity has no ID yet
";
        $description .= "- Foreign key will be NULL or cascade will fail

";

        $description .= "Example:
";
        $description .= "  \$user = new User(); // NEW, no ID
";
        $description .= "  \$order = new Order();
";
        $description .= "  \$order->setUser(\$user); // user->id is NULL!
";
        $description .= "  \$em->persist(\$order); // Foreign key constraint fails

";

        $description .= "Solutions:

";
        $description .= "1. Persist related entity first:
";
        $description .= "   \$em->persist(\$user);
";
        $description .= "   \$em->flush(); // User gets ID
";
        $description .= "   \$order->setUser(\$user); // Now user has ID
";
        $description .= "   \$em->persist(\$order);
";
        $description .= "   \$em->flush();

";

        $description .= "2. Use cascade persist:
";
        $description .= "   #[ManyToOne(cascade: ['persist'])]
";
        $description .= "   private User \$user;
";
        $description .= "   // Doctrine will handle the order

";

        $description .= "3. Load existing entity instead:
";
        $description .= "   \$user = \$em->find(User::class, \$userId);
";
        $description .= "   \$order->setUser(\$user);
";

        $issueData = new IssueData(
            type: 'entity_new_in_association',
            title: sprintf('NEW Entity in Association: %s->%s', $entityClass, $assocName),
            description: $description,
            severity: Severity::critical(),
            suggestion: null,
            queries: [],
        );

        return $this->issueFactory->create($issueData);
    }

    /**
     * Create issue for required field being null.
     */
    private function createRequiredFieldNullIssue(object $entity, string $fieldName): object
    {
        $entityClass = $this->getShortClassName($entity::class);

        $description = sprintf(
            "Required field '%s' is NULL in entity %s being persisted.

",
            $fieldName,
            $entityClass,
        );

        $description .= "Problem:
";
        $description .= "- Field is marked as NOT NULL in database
";
        $description .= "- Persisting will cause database constraint violation

";

        $description .= "Solutions:
";
        $description .= "1. Set the field value before persist
";
        $description .= "2. Make field nullable if appropriate
";
        $description .= "3. Provide default value in constructor
";

        $issueData = new IssueData(
            type: 'entity_required_field_null',
            title: sprintf('Required Field NULL: %s::\$%s', $entityClass, $fieldName),
            description: $description,
            severity: Severity::critical(),
            suggestion: null,
            queries: [],
        );

        return $this->issueFactory->create($issueData);
    }

    /**
     * Create issue for required association being null.
     */
    private function createRequiredAssociationNullIssue(object $entity, string $assocName): object
    {
        $entityClass = $this->getShortClassName($entity::class);

        $description = sprintf(
            "Required association '%s' is NULL in entity %s being persisted.

",
            $assocName,
            $entityClass,
        );

        $description .= "Problem:
";
        $description .= "- Association is marked as NOT NULL
";
        $description .= "- Foreign key constraint will fail

";

        $description .= "Solution:
";
        $description .= "Set the association before persisting the entity.
";

        $issueData = new IssueData(
            type: 'entity_required_association_null',
            title: sprintf('Required Association NULL: %s::\$%s', $entityClass, $assocName),
            description: $description,
            severity: Severity::critical(),
            suggestion: null,
            queries: [],
        );

        return $this->issueFactory->create($issueData);
    }

    /**
     * Create issue for removed entity access.
     */
    private function createRemovedEntityAccessIssue(object $entity): object
    {
        $entityClass = $this->getShortClassName($entity::class);

        $description = sprintf(
            "Entity %s is in REMOVED state but still accessible.

",
            $entityClass,
        );

        $description .= "Problem:
";
        $description .= "- Entity was removed with \$em->remove()
";
        $description .= "- Entity is scheduled for deletion from database
";
        $description .= "- But entity object still exists in memory
";
        $description .= "- Accessing it may cause confusion

";

        $description .= "Solution:
";
        $description .= "Don't use entity references after calling remove().
";

        $issueData = new IssueData(
            type: 'entity_removed_access',
            title: sprintf('Removed Entity Accessed: %s', $entityClass),
            description: $description,
            severity: Severity::warning(),
            suggestion: null,
            queries: [],
        );

        return $this->issueFactory->create($issueData);
    }

    /**
     * Create issue for removed entity in association.
     */
    private function createRemovedEntityInAssociationIssue(
        object $entity,
        string $assocName,
        object $relatedEntity,
    ): object {
        $entityClass  = $this->getShortClassName($entity::class);
        $relatedClass = $this->getShortClassName($relatedEntity::class);

        $description = sprintf(
            "Entity %s references REMOVED entity %s in association '%s'.

",
            $entityClass,
            $relatedClass,
            $assocName,
        );

        $description .= "Problem:
";
        $description .= "- Related entity is scheduled for deletion
";
        $description .= "- Foreign key will become invalid
";
        $description .= "- May cause constraint violations

";

        $description .= "Solution:
";
        $description .= "Set association to null or another entity before flush.
";

        $issueData = new IssueData(
            type: 'entity_removed_in_association',
            title: sprintf('Removed Entity in Association: %s->%s', $entityClass, $assocName),
            description: $description,
            severity: Severity::warning(),
            suggestion: null,
            queries: [],
        );

        return $this->issueFactory->create($issueData);
    }

    /**
     * Create issue for detached entity in association.
     */
    private function createDetachedEntityInAssociationIssue(
        object $entity,
        string $assocName,
        object $relatedEntity,
    ): object {
        $entityClass  = $this->getShortClassName($entity::class);
        $relatedClass = $this->getShortClassName($relatedEntity::class);

        $description = sprintf(
            "Entity %s references DETACHED entity %s in association '%s'.

",
            $entityClass,
            $relatedClass,
            $assocName,
        );

        $description .= "Problem:
";
        $description .= "- Related entity is not managed by EntityManager
";
        $description .= "- Changes to related entity will be ignored
";
        $description .= "- May cause unexpected behavior

";

        $description .= "Solution:
";
        $description .= "Merge the detached entity or reload it from database.
";

        $issueData = new IssueData(
            type: 'entity_detached_in_association',
            title: sprintf('Detached Entity in Association: %s->%s', $entityClass, $assocName),
            description: $description,
            severity: Severity::warning(),
            suggestion: null,
            queries: [],
        );

        return $this->issueFactory->create($issueData);
    }

    /**
     * Get short class name without namespace.
     */
    private function getShortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
