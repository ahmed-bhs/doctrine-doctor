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
use ReflectionClass;

/**
 * Detects bad practices in SoftDeleteable implementations.
 * This is CRITICAL because soft delete mistakes can cause:
 * - Data loss (CASCADE DELETE when soft delete expected)
 * - Data leaks (soft deleted data still visible)
 * - Query errors (JOINs to soft-deleted records)
 * - Business logic bugs (processing "deleted" records)
 * Supports multiple implementations:
 * - gedmo/doctrine-extensions (Gedmo\SoftDeleteable annotation)
 * - knplabs/doctrine-behaviors (SoftDeletableEntity trait)
 * - stof/doctrine-extensions-bundle (Symfony integration)
 * - Manual implementations (deletedAt field)
 * Bad practices detected:
 * 1. deletedAt NOT nullable (MUST be nullable!)
 * 2. Missing soft delete filter configuration
 * 3. Public setters on deletedAt (breaks soft delete logic)
 * 4. CASCADE DELETE conflicts with soft delete
 * 5. Using DateTime instead of DateTimeImmutable
 * 6. Missing timezone on soft delete field
 * Best practices:
 * - deletedAt MUST be nullable (null = not deleted)
 * - Configure Doctrine filter to hide soft-deleted records
 * - Use CASCADE persist/remove carefully with soft deletes
 * - Use DateTimeImmutable for deletedAt
 * - No public setters on deletedAt
 * - Consider deletedBy field for audit trail
 */
class SoftDeleteableTraitAnalyzer implements AnalyzerInterface
{
    /**
     * Common field names for soft delete.
     */
    private const SOFT_DELETE_FIELD_PATTERNS = [
        'deletedAt',
        'deleted_at',
        'deleteDate',
        'delete_date',
        'removedAt',
        'removed_at',
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

                foreach ($classMetadataFactory->getAllMetadata() as $classMetadatum) {
                    if ($classMetadatum->isMappedSuperclass) {
                        continue;
                    }

                    if ($classMetadatum->isEmbeddedClass) {
                        continue;
                    }

                    $entityIssues = $this->analyzeEntity($classMetadatum);

                    assert(is_iterable($entityIssues), '$entityIssues must be iterable');

                    foreach ($entityIssues as $entityIssue) {
                        yield $entityIssue;
                    }
                }
            },
        );
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     * @return array<\AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {
        $issues = [];
        $softDeleteFields = $this->findSoftDeleteFields($classMetadata);

        if ([] === $softDeleteFields) {
            return $issues;
        }

        assert(is_iterable($softDeleteFields), '$softDeleteFields must be iterable');

        foreach ($softDeleteFields as $fieldName => $mapping) {
            // CRITICAL: Check if deletedAt is NOT nullable (must be nullable!)
            if ($this->isNotNullable($mapping)) {
                $issues[] = $this->createNotNullableIssue($classMetadata, $fieldName);
            }

            // Check for mutable DateTime
            if ($this->usesMutableDateTime($classMetadata, $fieldName)) {
                $issues[] = $this->createMutableDateTimeIssue($classMetadata, $fieldName);
            }

            // Check for public setters
            if ($this->hasPublicSetter($classMetadata, $fieldName)) {
                $issues[] = $this->createPublicSetterIssue($classMetadata, $fieldName);
            }

            // Check for missing timezone
            if ($this->isMissingTimezone($mapping)) {
                $issues[] = $this->createMissingTimezoneIssue($classMetadata, $fieldName);
            }
        }

        // Check for CASCADE DELETE on associations (conflicts with soft delete)
        $cascadeIssues = $this->checkCascadeDeleteConflicts($classMetadata);

        return array_merge($issues, $cascadeIssues);
    }

    /**
     * Find all soft delete fields.
     * @param ClassMetadata<object> $classMetadata
     * @return array<string, array<string, mixed>>
     */
    private function findSoftDeleteFields(ClassMetadata $classMetadata): array
    {
        $softDeleteFields = [];

        foreach ($classMetadata->fieldMappings as $fieldName => $mapping) {
            // Normalize mapping to array (Doctrine ORM 3.x returns FieldMapping objects)
            if (is_object($mapping)) {
                $mapping = (array) $mapping;
            }

            if ($this->isSoftDeleteField($fieldName, $mapping)) {
                $softDeleteFields[$fieldName] = $mapping;
            }
        }

        return $softDeleteFields;
    }

    /**
     * @param array<string, mixed>|object $mapping
     */
    private function isSoftDeleteField(string $fieldName, array|object $mapping): bool
    {
        $fieldLower = strtolower($fieldName);

        // Check by field name
        foreach (self::SOFT_DELETE_FIELD_PATTERNS as $pattern) {
            if (str_contains($fieldLower, strtolower($pattern))) {
                // Must be datetime type
                $type = MappingHelper::getString($mapping, 'type');

                return in_array($type, ['datetime', 'datetimetz', 'datetime_immutable', 'datetimetz_immutable'], true);
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|object $mapping
     */
    private function isNotNullable(array|object $mapping): bool
    {
        $nullable = (bool) (MappingHelper::getBool($mapping, 'nullable') ?? false);

        // CRITICAL: For soft delete, NOT nullable is WRONG!
        return !$nullable;
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function usesMutableDateTime(ClassMetadata $classMetadata, string $fieldName): bool
    {
        $className = $classMetadata->getName();
        assert(class_exists($className));
        $reflectionClass = new ReflectionClass($className);

        if (!$reflectionClass->hasProperty($fieldName)) {
            return false;
        }

        $reflectionProperty = $reflectionClass->getProperty($fieldName);
        $type = $reflectionProperty->getType();

        if (null === $type) {
            return false;
        }

        if ($type instanceof \ReflectionNamedType) {
            return 'DateTime' === $type->getName();
        }

        return false;
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function hasPublicSetter(ClassMetadata $classMetadata, string $fieldName): bool
    {
        $className = $classMetadata->getName();
        assert(class_exists($className));
        $reflectionClass = new ReflectionClass($className);
        $setterName = 'set' . ucfirst($fieldName);

        if (!$reflectionClass->hasMethod($setterName)) {
            return false;
        }

        $reflectionMethod = $reflectionClass->getMethod($setterName);

        return $reflectionMethod->isPublic();
    }

    /**
     * @param array<string, mixed>|object $mapping
     */
    private function isMissingTimezone(array|object $mapping): bool
    {
        $type = is_array($mapping) ? ($mapping['type'] ?? null) : null;

        return 'datetime' === $type || 'datetime_immutable' === $type;
    }

    /**
     * Check for CASCADE DELETE on associations (dangerous with soft delete).
     * @param ClassMetadata<object> $classMetadata
     * @return array<\AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function checkCascadeDeleteConflicts(ClassMetadata $classMetadata): array
    {
        $issues = [];

        foreach ($classMetadata->associationMappings as $fieldName => $mapping) {
            $joinColumns = MappingHelper::getArray($mapping, 'joinColumns');

            if (is_array($joinColumns)) {
                assert(is_iterable($joinColumns), '$joinColumns must be iterable');

                foreach ($joinColumns as $joinColumn) {
                    if (isset($joinColumn['onDelete']) && 'CASCADE' === strtoupper((string) $joinColumn['onDelete'])) {
                        $issues[] = $this->createCascadeDeleteConflictIssue($classMetadata, $fieldName);
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function createNotNullableIssue(ClassMetadata $classMetadata, string $fieldName): IssueInterface
    {
        $className = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $description = sprintf(
            'CRITICAL: Soft delete field %s::$%s is NOT nullable! ' .
            'For soft delete to work, deletedAt MUST be nullable. ' .
            'NULL = not deleted, DateTime = deleted. ' .
            'This is a critical misconfiguration that breaks soft delete functionality.',
            $shortClassName,
            $fieldName,
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'soft_delete_not_nullable',
            'title'       => sprintf('CRITICAL: deletedAt Not Nullable: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'critical',
            'category'    => 'configuration',
            'suggestion'  => $this->createMakeNullableSuggestion($shortClassName, $fieldName),
            'backtrace'   => [
                'entity' => $className,
                'field'  => $fieldName,
            ],
        ]);
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function createMutableDateTimeIssue(ClassMetadata $classMetadata, string $fieldName): IssueInterface
    {
        $className = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $description = sprintf(
            'Soft delete field %s::$%s uses mutable DateTime. ' .
            'Use DateTimeImmutable to prevent accidental modifications.',
            $shortClassName,
            $fieldName,
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'soft_delete_mutable_datetime',
            'title'       => sprintf('Mutable DateTime in SoftDelete: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'warning',
            'category'    => 'code_quality',
            'suggestion'  => $this->createImmutableDateTimeSuggestion($shortClassName, $fieldName),
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
            'Soft delete field %s::$%s has a public setter. ' .
            'Soft deletes should be managed through delete() method or Doctrine extensions, not manual setters.',
            $shortClassName,
            $fieldName,
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'soft_delete_public_setter',
            'title'       => sprintf('Public Setter on SoftDelete: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'warning',
            'category'    => 'code_quality',
            'suggestion'  => $this->createRemoveSetterSuggestion($shortClassName, $fieldName),
            'backtrace'   => [
                'entity' => $className,
                'field'  => $fieldName,
            ],
        ]);
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function createMissingTimezoneIssue(ClassMetadata $classMetadata, string $fieldName): IssueInterface
    {
        $className = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $description = sprintf(
            'Soft delete field %s::$%s is missing timezone information. ' .
            'Use datetimetz_immutable for consistent soft delete timestamps across timezones.',
            $shortClassName,
            $fieldName,
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'soft_delete_missing_timezone',
            'title'       => sprintf('Missing Timezone: %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'info',
            'category'    => 'configuration',
            'suggestion'  => $this->createTimezoneSuggestion($shortClassName, $fieldName),
            'backtrace'   => [
                'entity' => $className,
                'field'  => $fieldName,
            ],
        ]);
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function createCascadeDeleteConflictIssue(ClassMetadata $classMetadata, string $fieldName): IssueInterface
    {
        $className = $classMetadata->getName();
        $lastBackslashPos = strrpos($className, '\\');
        $shortClassName = substr($className, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $description = sprintf(
            'CRITICAL: Entity %s has CASCADE DELETE on association %s but uses soft delete! ' .
            'CASCADE DELETE will physically delete related records, bypassing soft delete. ' .
            'This can cause data loss. Consider removing CASCADE DELETE or use application-level cascade.',
            $shortClassName,
            $fieldName,
        );

        return $this->issueFactory->createFromArray([
            'type'        => 'soft_delete_cascade_conflict',
            'title'       => sprintf('CASCADE DELETE Conflict: %s::%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity'    => 'critical',
            'category'    => 'configuration',
            'suggestion'  => $this->createCascadeConflictSuggestion($shortClassName, $fieldName),
            'backtrace'   => [
                'entity' => $className,
                'field'  => $fieldName,
            ],
        ]);
    }

    private function createMakeNullableSuggestion(string $className, string $fieldName): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'soft_delete_nullable',
            context: [
                'entity_class' => $className,
                'field_name'   => $fieldName,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::configuration(),
                severity: Severity::critical(),
                title: sprintf('Make deletedAt Nullable: %s::$%s', $className, $fieldName),
                tags: ['critical', 'soft-delete', 'nullable', 'data-loss'],
            ),
        );
    }

    private function createImmutableDateTimeSuggestion(string $className, string $fieldName): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'soft_delete_immutable',
            context: [
                'entity_class' => $className,
                'field_name'   => $fieldName,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::codeQuality(),
                severity: Severity::warning(),
                title: sprintf('Use DateTimeImmutable: %s::$%s', $className, $fieldName),
                tags: ['soft-delete', 'datetime', 'immutable'],
            ),
        );
    }

    private function createRemoveSetterSuggestion(string $className, string $fieldName): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'soft_delete_setter',
            context: [
                'entity_class' => $className,
                'field_name'   => $fieldName,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::codeQuality(),
                severity: Severity::warning(),
                title: sprintf('Remove Public Setter: %s::$%s', $className, $fieldName),
                tags: ['soft-delete', 'encapsulation'],
            ),
        );
    }

    private function createTimezoneSuggestion(string $className, string $fieldName): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'soft_delete_timezone',
            context: [
                'entity_class' => $className,
                'field_name'   => $fieldName,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::configuration(),
                severity: Severity::info(),
                title: sprintf('Add Timezone Support: %s::$%s', $className, $fieldName),
                tags: ['soft-delete', 'timezone'],
            ),
        );
    }

    private function createCascadeConflictSuggestion(string $className, string $fieldName): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'soft_delete_cascade_conflict',
            context: [
                'entity_class' => $className,
                'field_name'   => $fieldName,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::configuration(),
                severity: Severity::critical(),
                title: sprintf('Fix CASCADE DELETE Conflict: %s::%s', $className, $fieldName),
                tags: ['critical', 'soft-delete', 'cascade', 'data-loss'],
            ),
        );
    }
}
