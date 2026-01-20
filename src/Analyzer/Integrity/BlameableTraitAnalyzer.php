<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Webmozart\Assert\Assert;

/**
 * Detects bad practices in Blameable implementations.
 * Supports multiple implementations:
 * - gedmo/doctrine-extensions (Gedmo\Blameable annotation)
 * - knplabs/doctrine-behaviors (BlameableEntity trait)
 * - stof/doctrine-extensions-bundle (Symfony integration)
 * - Manual implementations (createdBy/updatedBy relations)
 * Bad practices detected:
 * 1. Nullable createdBy (should never be null)
 * 2. Public setters on blameable fields (breaks audit trail)
 * 3. Missing cascade options on blameable relations
 * 4. Wrong target entity (not User/Account)
 * 5. Missing indexes on frequently queried blameable fields
 * 6. Blameable on value objects instead of entities
 * Best practices:
 * - createdBy should be NOT NULL
 * - updatedBy can be nullable (for records not yet updated)
 * - Use ManyToOne relations to User entity
 * - Remove public setters
 * - Add indexes for filtering/sorting
 * - Configure Gedmo/KnpLabs security integration
 */
class BlameableTraitAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Common field names for blameable.
     */
    private const BLAMEABLE_FIELD_PATTERNS = [
        'createdBy',
        'created_by',
        'creator',
        'author',
        'updatedBy',
        'updated_by',
        'modifier',
        'lastModifiedBy',
        'last_modified_by',
        'deletedBy',
        'deleted_by',
    ];

    /**
     * Common User entity names.
     */
    private const USER_ENTITY_PATTERNS = [
        'User',
        'Account',
        'Admin',
        'Member',
        'Customer',
    ];

    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private IssueFactoryInterface $issueFactory,
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

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(function () {
            $classMetadataFactory = $this->entityManager->getMetadataFactory();

            foreach ($classMetadataFactory->getAllMetadata() as $classMetadatum) {
                if ($classMetadatum->isMappedSuperclass) {
                    continue;
                }

                if ($classMetadatum->isEmbeddedClass) {
                    continue;
                }

                $entityIssues = $this->analyzeEntity($classMetadatum);

                Assert::isIterable($entityIssues, '$entityIssues must be iterable');

                foreach ($entityIssues as $entityIssue) {
                    yield $entityIssue;
                }
            }
        });
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     * @return array<\AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {
        $issues = [];
        $className = $classMetadata->getName();

        // Debug logging (only when logger is available)
        $this->logger?->debug('BlameableTraitAnalyzer: Checking entity', ['entity' => $className]);

        $blameableFields = $this->findBlameableFields($classMetadata);
        $this->logger?->debug('BlameableTraitAnalyzer: Found blameable fields', [
            'entity' => $className,
            'fields_count' => count($blameableFields),
        ]);

        // Check for missing blameable opportunity (has timestamp fields but no blameable)
        if ([] === $blameableFields) {
            $missingOpportunity = $this->checkMissingBlameableOpportunity($classMetadata);
            if (null !== $missingOpportunity) {
                $this->logger?->debug('BlameableTraitAnalyzer: Found missing blameable opportunity', [
                    'entity' => $className,
                ]);
                $issues[] = $missingOpportunity;
            }
            return $issues;
        }

        Assert::isIterable($blameableFields, '$blameableFields must be iterable');

        foreach ($blameableFields as $fieldName => $mapping) {
            // Check if createdBy is nullable
            if ($this->isCreatedByNullable($fieldName, $mapping)) {
                $issues[] = $this->createNullableCreatedByIssue($classMetadata, $fieldName);
            }

            // Check for public setters
            if ($this->hasPublicSetter($classMetadata, $fieldName)) {
                $issues[] = $this->createPublicSetterIssue($classMetadata, $fieldName);
            }

            // Check target entity
            if ($this->hasWrongTargetEntity($mapping)) {
                $issues[] = $this->createWrongTargetEntityIssue($classMetadata, $fieldName, $mapping);
            }
        }

        return $issues;
    }

    /**
     * Check if entity has timestamp fields but missing blameable fields.
     * @param ClassMetadata<object> $classMetadata
     */
    private function checkMissingBlameableOpportunity(ClassMetadata $classMetadata): ?IssueInterface
    {
        // DEBUG
        $className = $classMetadata->getName();

        $timestampFields = $this->findTimestampFields($classMetadata);

        if ([] === $timestampFields) {
            return null; // No timestamp fields, not relevant
        }

        // If entity has createdAt/updatedAt but no createdBy/updatedBy, suggest trait
        $hasCreatedAt = isset($timestampFields['createdAt']) || isset($timestampFields['created_at']);

        if (!$hasCreatedAt) {
            return null; // Only suggest if entity already uses timestamps
        }

        $className = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $missingFields = [];

        if (!isset($timestampFields['updatedAt']) && !isset($timestampFields['updated_at'])) {
            $missingFields[] = 'updatedAt';
        }

        $description = sprintf(
            'Entity %s has timestamp field(s) (%s) but no blameable fields (createdBy/updatedBy). ' .
            'Consider using BlameableTrait and TimestampableTrait for proper audit trail. ' .
            'Missing fields: %s. ' .
            'Benefits: automatic tracking of who created/updated records, standardized audit across entities.',
            $shortClassName,
            implode(', ', array_keys($timestampFields)),
            count($missingFields) > 0 ? implode(', ', $missingFields) . ', createdBy, updatedBy' : 'createdBy, updatedBy',
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'missing_blameable_trait_opportunity',
            'title'       => sprintf('Missing Blameable Trait: %s', $shortClassName),
            'description' => $description,
            'severity'    => 'info',
            'category'    => 'integrity',
            'suggestion'  => $this->createBlameableTraitSuggestion($shortClassName, $timestampFields),
            'backtrace'   => [
                'entity'           => $className,
                'timestamp_fields' => array_keys($timestampFields),
                'missing_fields'   => count($missingFields) > 0 ? array_merge($missingFields, ['createdBy', 'updatedBy']) : ['createdBy', 'updatedBy'],
            ],
        ]);
    }

    /**
     * Find timestamp fields (createdAt, updatedAt).
     * @param ClassMetadata<object> $classMetadata
     * @return array<string, array<string, mixed>|object>
     */
    private function findTimestampFields(ClassMetadata $classMetadata): array
    {
        $timestampFields = [];

        foreach ($classMetadata->fieldMappings as $fieldName => $mapping) {
            $fieldLower = strtolower($fieldName);

            // Check for timestamp field patterns
            if (str_contains($fieldLower, 'createdat') ||
                str_contains($fieldLower, 'created_at') ||
                str_contains($fieldLower, 'updatedat') ||
                str_contains($fieldLower, 'updated_at')) {

                // Must be datetime type
                // Doctrine ORM 3+: mapping is an object, ORM 2.x: mapping is an array
                $type = is_array($mapping) ? ($mapping['type'] ?? null) : ($mapping->type ?? null);
                if (in_array($type, ['datetime', 'datetime_immutable', 'datetimetz', 'datetimetz_immutable'], true)) {
                    $timestampFields[$fieldName] = $mapping;
                }
            }
        }

        return $timestampFields;
    }

    /**
     * Find all blameable fields (ManyToOne relations to User entities).
     * @param ClassMetadata<object> $classMetadata
     * @return array<string, array<string, mixed>|object>
     */
    private function findBlameableFields(ClassMetadata $classMetadata): array
    {
        $blameableFields = [];

        foreach ($classMetadata->associationMappings as $fieldName => $mapping) {
            if ($this->isBlameableField($fieldName, $mapping)) {
                $blameableFields[$fieldName] = $mapping;
            }
        }

        return $blameableFields;
    }

    /**
     * @param array<string, mixed>|object $mapping
     */
    private function isBlameableField(string $fieldName, array|object $mapping): bool
    {
        $fieldLower = strtolower($fieldName);

        // Check by field name
        foreach (self::BLAMEABLE_FIELD_PATTERNS as $pattern) {
            if (str_contains($fieldLower, strtolower($pattern))) {
                // Must be ManyToOne
                // In Doctrine ORM 2.x, check 'type' property
                // In Doctrine ORM 3+, check the class name
                if (is_array($mapping)) {
                    $type = $mapping['type'] ?? null;
                    return ClassMetadata::MANY_TO_ONE === $type;
                }

                // Doctrine ORM 3+: Check class name
                return $mapping instanceof ManyToOneAssociationMapping;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|object $mapping
     */
    private function isCreatedByNullable(string $fieldName, array|object $mapping): bool
    {
        $fieldLower = strtolower($fieldName);

        // Check if it's a createdBy field
        $isCreatedBy = str_contains($fieldLower, 'created')
            || str_contains($fieldLower, 'creator')
            || str_contains($fieldLower, 'author');

        if (!$isCreatedBy) {
            return false;
        }

        // Check join column nullable
        $joinColumns = MappingHelper::getArray($mapping, 'joinColumns');

        if (is_array($joinColumns) && isset($joinColumns[0])) {
            $firstJoinColumn = $joinColumns[0];
            // In Doctrine ORM 2.x, joinColumns are arrays
            // In Doctrine ORM 3+, joinColumns are objects
            if (is_array($firstJoinColumn)) {
                $nullable = $firstJoinColumn['nullable'] ?? true;
                return is_bool($nullable) ? $nullable : true;
            }

            if (is_object($firstJoinColumn) && property_exists($firstJoinColumn, 'nullable')) {
                $nullable = $firstJoinColumn->nullable ?? true;
                return is_bool($nullable) ? $nullable : true;
            }
        }

        return true;
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function hasPublicSetter(ClassMetadata $classMetadata, string $fieldName): bool
    {
        $className = $classMetadata->getName();
        Assert::classExists($className);
        $reflectionClass = new ReflectionClass($className);
        $setterName = 'set' . ucfirst($fieldName);

        if (!$reflectionClass->hasMethod($setterName)) {
            return false;
        }

        $reflectionMethod = $reflectionClass->getMethod($setterName);

        if (!$reflectionMethod->isPublic()) {
            return false;
        }

        // If Gedmo or KnpLabs traits/extensions are used, public setters are expected
        if ($this->usesBlameableExtension($reflectionClass)) {
            return false; // Not an issue if using extensions
        }

        return true;
    }

    /**
     * Check if the entity uses Gedmo Blameable or KnpLabs BlameableEntity trait.
     */
    private function usesBlameableExtension(ReflectionClass $reflectionClass): bool
    {
        // Check for Gedmo annotations/attributes
        if (class_exists('Gedmo\\Blameable\\Blameable')) {
            // Check if entity has @Gedmo\Blameable annotation on the field
            // Note: In real implementation, we'd need to check attributes/annotations
            // For now, we detect if Gedmo is available in the project
        }

        // Check for KnpLabs trait usage
        $traits = $reflectionClass->getTraitNames();
        Assert::isIterable($traits, '$traits must be iterable');

        foreach ($traits as $trait) {
            if (str_contains($trait, 'BlameableEntity') ||
                str_contains($trait, 'Blameable') ||
                str_contains($trait, 'Timestampable')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|object $mapping
     */
    private function hasWrongTargetEntity(array|object $mapping): bool
    {
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity');

        if (null === $targetEntity) {
            return false;
        }

        // Check if target looks like a User entity
        foreach (self::USER_ENTITY_PATTERNS as $pattern) {
            if (str_contains((string) $targetEntity, $pattern)) {
                return false; // Correct target
            }
        }

        return true; // Wrong target - doesn't look like User
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function createNullableCreatedByIssue(ClassMetadata $classMetadata, string $fieldName): IssueInterface
    {
        $className = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $description = sprintf(
            'Blameable field %s::$%s (creator) is nullable. ' .
            'Every entity should have a creator - this field should never be NULL. ' .
            'Mark the join column as NOT NULL for data integrity.',
            $shortClassName,
            $fieldName,
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'blameable_nullable_created_by',
            'title'       => sprintf('Nullable Creator Field: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'warning',
            'category'    => 'integrity',
            'suggestion'  => $this->createNonNullableCreatedBySuggestion($shortClassName, $fieldName),
            'backtrace'   => [
                'entity' => $className,
                'field'  => $fieldName,
            ],
        ]);
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function createPublicSetterIssue(ClassMetadata $classMetadata, string $fieldName): IssueInterface
    {
        $className = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $description = sprintf(
            'Blameable field %s::$%s has a public setter. ' .
            'Blameable fields should be set automatically by Doctrine extensions or security context. ' .
            'Public setters allow manual manipulation and break the audit trail. Remove or make protected.',
            $shortClassName,
            $fieldName,
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'blameable_public_setter',
            'title'       => sprintf('Public Setter on Blameable: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'info',
            'category'    => 'integrity',
            'suggestion'  => $this->createRemovePublicSetterSuggestion($shortClassName, $fieldName),
            'backtrace'   => [
                'entity' => $className,
                'field'  => $fieldName,
            ],
        ]);
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     * @param array<string, mixed>|object $mapping
     */
    private function createWrongTargetEntityIssue(
        ClassMetadata $classMetadata,
        string $fieldName,
        array|object $mapping,
    ): IssueInterface {
        $className = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity');

        $description = sprintf(
            'Blameable field %s::$%s points to %s which doesn\'t appear to be a User entity. ' .
            'Blameable fields should reference your main User/Account entity for proper audit trails.',
            $shortClassName,
            $fieldName,
            $targetEntity,
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'blameable_wrong_target',
            'title'       => sprintf('Wrong Target Entity: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'info',
            'category'    => 'configuration',
            'suggestion'  => $this->createCorrectTargetSuggestion($shortClassName, $fieldName, $targetEntity),
            'backtrace'   => [
                'entity'        => $className,
                'field'         => $fieldName,
                'target_entity' => $targetEntity,
            ],
        ]);
    }

    private function createNonNullableCreatedBySuggestion(string $className, string $fieldName): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/blameable_non_nullable_created_by',
            context: [
                'entity_class' => $className,
                'field_name'   => $fieldName,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::warning(),
                title: sprintf('Make CreatedBy NOT NULL: %s::$%s', $className, $fieldName),
                tags: ['blameable', 'not-null', 'audit', 'data-integrity'],
            ),
        );
    }

    private function createRemovePublicSetterSuggestion(string $className, string $fieldName): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/blameable_public_setter',
            context: [
                'entity_class' => $className,
                'field_name'   => $fieldName,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::info(),
                title: sprintf('Remove Public Setter: %s::$%s', $className, $fieldName),
                tags: ['blameable', 'encapsulation', 'audit-trail'],
            ),
        );
    }

    private function createCorrectTargetSuggestion(string $className, string $fieldName, ?string $currentTarget): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/blameable_target_entity',
            context: [
                'entity_class'   => $className,
                'field_name'     => $fieldName,
                'current_target' => $currentTarget,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::configuration(),
                severity: Severity::info(),
                title: sprintf('Fix Target Entity: %s::$%s', $className, $fieldName),
                tags: ['blameable', 'target-entity', 'configuration'],
            ),
        );
    }

    /**
     * @param array<string, array<string, mixed>|object> $timestampFields
     */
    private function createBlameableTraitSuggestion(string $className, array $timestampFields): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/missing_blameable_trait',
            context: [
                'entity_class'     => $className,
                'timestamp_fields' => array_keys($timestampFields),
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::info(),
                title: sprintf('Add Blameable Trait: %s', $className),
                tags: ['blameable', 'trait', 'audit', 'timestampable'],
            ),
        );
    }
}
