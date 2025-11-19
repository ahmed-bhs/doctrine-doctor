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
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use Webmozart\Assert\Assert;

/**
 * Detects type mismatches between entity properties and their database values.
 * Inspired by PHPStan's EntityColumnRule.
 * Detects runtime type issues:
 * - Property declared as 'int' receiving string "25" from database
 * - Enum property with mismatched backing type
 * - Non-nullable property receiving NULL from database
 * - DateTime property receiving invalid date string
 * This analyzer checks entities after hydration to detect real-world type issues
 * that static analysis cannot catch (dynamic queries, custom types, etc.).
 */
class PropertyTypeMismatchAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /** @var array<string, bool> Cache to avoid checking same entity multiple times */
    /** @var array<mixed> */
    private array $checkedEntities = [];

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
                $this->checkedEntities = [];

                // Get Unit of Work to access managed entities
                $unitOfWork = $this->entityManager->getUnitOfWork();

                // Get all managed entities (those loaded during request)
                $managedEntities = $unitOfWork->getIdentityMap();

                // Check each entity type
                Assert::isIterable($managedEntities, '$managedEntities must be iterable');

                foreach ($managedEntities as $entityClass => $entities) {
                    if (isset($this->checkedEntities[$entityClass])) {
                        continue;
                    }

                    $this->checkedEntities[$entityClass] = true;

                    try {
                        $metadata = $this->entityManager->getClassMetadata($entityClass);
                    } catch (\Throwable) {
                        continue;
                    }

                    // Check all entities of this class
                    Assert::isIterable($entities, '$entities must be iterable');

                    foreach ($entities as $entity) {
                        $entityIssues = $this->checkEntityProperties($entity, $metadata);
                        Assert::isIterable($entityIssues, '$entityIssues must be iterable');

                        foreach ($entityIssues as $entityIssue) {
                            yield $entityIssue;
                        }
                    }
                }
            },
        );
    }

    /**
     * Check all properties of an entity for type mismatches.
     */
    private function checkEntityProperties(object $entity, ClassMetadata $classMetadata): array
    {

        $issues = [];

        // Use cached ReflectionClass from Doctrine's ClassMetadata
        $reflectionClass = $classMetadata->reflClass;

        if (null === $reflectionClass) {
            return [];
        }

        // Check each mapped field
        foreach ($classMetadata->getFieldNames() as $fieldName) {
            $issue = $this->checkFieldType($entity, $fieldName, $classMetadata, $reflectionClass);
            if (null !== $issue) {
                $issues[] = $issue;
            }
        }

        // Check associations
        foreach ($classMetadata->getAssociationNames() as $assocName) {
            $issue = $this->checkAssociationType($entity, $assocName, $classMetadata, $reflectionClass);
            if (null !== $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * Check if a field value matches its declared type.
     */
    private function checkFieldType(
        object $entity,
        string $fieldName,
        ClassMetadata $classMetadata,
        \ReflectionClass $reflectionClass,
    ): ?object {
        $valueData = $this->getFieldValueAndMapping($entity, $fieldName, $classMetadata, $reflectionClass);
        if (null === $valueData) {
            return null;
        }

        ['value' => $value, 'fieldMapping' => $fieldMapping, 'doctrineType' => $doctrineType] = $valueData;

        // Check NULL values
        $nullIssue = $this->validateNullValue($entity, $fieldName, $value, $fieldMapping, $doctrineType);
        if (null !== $nullIssue) {
            return $nullIssue;
        }

        if (null === $value) {
            return null;
        }

        // Check type match
        $typeIssue = $this->validateTypeMatch($entity, $fieldName, $value, $doctrineType);
        if (null !== $typeIssue) {
            return $typeIssue;
        }

        // Check enum backing type (PHP 8.1+)
        return $this->validateEnumBackingType($value, $fieldMapping, $entity, $fieldName);
    }

    /**
     * Get field value and mapping information.
     * @return array{value: mixed, fieldMapping: array<string, mixed>|object, doctrineType: string}|null
     */
    private function getFieldValueAndMapping(
        object $entity,
        string $fieldName,
        ClassMetadata $classMetadata,
        \ReflectionClass $reflectionClass,
    ): ?array {
        if (!$reflectionClass->hasProperty($fieldName)) {
            return null;
        }

        $reflectionProperty = $reflectionClass->getProperty($fieldName);

        try {
            if (!$reflectionProperty->isInitialized($entity)) {
                return null;
            }
            $value = $reflectionProperty->getValue($entity);
        } catch (\Error) {
            return null;
        }

        $fieldMapping = $classMetadata->getFieldMapping($fieldName);
        $doctrineType = MappingHelper::getString($fieldMapping, 'type');

        if (null === $doctrineType) {
            return null;
        }

        return ['value' => $value, 'fieldMapping' => $fieldMapping, 'doctrineType' => $doctrineType];
    }

    /**
     * Validate NULL value constraints.
     * @param array<string, mixed>|object $fieldMapping
     */
    private function validateNullValue(
        object $entity,
        string $fieldName,
        mixed $value,
        array|object $fieldMapping,
        string $doctrineType,
    ): ?object {
        if (null !== $value) {
            return null;
        }

        $nullable = (bool) (MappingHelper::getBool($fieldMapping, 'nullable') ?? false);
        if ($nullable) {
            return null;
        }

        // Use cached ReflectionClass from Doctrine's ClassMetadata
        $metadata = $this->entityManager->getClassMetadata($entity::class);
        $reflectionProperty = $metadata->reflClass->getProperty($fieldName);

        if ($this->isPropertyNullable($reflectionProperty)) {
            return null;
        }

        return $this->createTypeMismatchIssue(
            entity: $entity,
            fieldName: $fieldName,
            expectedType: $doctrineType . ' (non-nullable)',
            actualType: 'NULL',
            value: $value,
            severity: Severity::critical(),
        );
    }

    /**
     * Validate type compatibility.
     */
    private function validateTypeMatch(
        object $entity,
        string $fieldName,
        mixed $value,
        string $doctrineType,
    ): ?object {
        $expectedPhpType = $this->doctrineTypeToPhpType($doctrineType);
        $actualPhpType   = get_debug_type($value);

        if ($this->isTypeCompatible($value, $expectedPhpType)) {
            return null;
        }

        return $this->createTypeMismatchIssue(
            entity: $entity,
            fieldName: $fieldName,
            expectedType: $expectedPhpType,
            actualType: $actualPhpType,
            value: $value,
            severity: Severity::warning(),
        );
    }

    /**
     * Validate enum backing type.
     * @param array<string, mixed>|object $fieldMapping
     */
    private function validateEnumBackingType(
        mixed $value,
        array|object $fieldMapping,
        object $entity,
        string $fieldName,
    ): ?object {
        if (null === MappingHelper::getProperty($fieldMapping, 'enumType')) {
            return null;
        }

        return $this->checkEnumBackingType($value, $fieldMapping, $entity, $fieldName);
    }

    /**
     * Check if an association value matches its declared type.
     */
    private function checkAssociationType(
        object $entity,
        string $assocName,
        ClassMetadata $classMetadata,
        \ReflectionClass $reflectionClass,
    ): ?object {
        $reflectionProperty = $this->getInitializedProperty($entity, $assocName, $reflectionClass);
        if (!$reflectionProperty instanceof \ReflectionProperty) {
            return null;
        }

        $value              = $reflectionProperty->getValue($entity);
        $associationMapping = $classMetadata->getAssociationMapping($assocName);
        $targetEntity       = MappingHelper::getString($associationMapping, 'targetEntity');

        // Skip if no target entity information
        if (null === $targetEntity) {
            return null;
        }

        // Check ToOne associations
        if ($classMetadata->isSingleValuedAssociation($assocName)) {
            return $this->checkToOneAssociation(
                entity: $entity,
                assocName: $assocName,
                value: $value,
                targetEntity: $targetEntity,
                associationMapping: $associationMapping,
                reflectionProperty: $reflectionProperty,
            );
        }

        // Check ToMany associations (collections)
        if ($classMetadata->isCollectionValuedAssociation($assocName)) {
            return $this->checkToManyAssociation(
                entity: $entity,
                assocName: $assocName,
                value: $value,
                targetEntity: $targetEntity,
            );
        }

        return null;
    }

    /**
     * Get initialized property or null if not available.
     */
    private function getInitializedProperty(
        object $entity,
        string $propertyName,
        \ReflectionClass $reflectionClass,
    ): ?\ReflectionProperty {
        if (!$reflectionClass->hasProperty($propertyName)) {
            return null;
        }

        $reflectionProperty = $reflectionClass->getProperty($propertyName);

        try {
            if (!$reflectionProperty->isInitialized($entity)) {
                return null;
            }
        } catch (\Error) {
            return null;
        }

        return $reflectionProperty;
    }

    /**
     * Check ToOne association type.
     * @param array<string, mixed>|object $associationMapping
     */
    private function checkToOneAssociation(
        object $entity,
        string $assocName,
        mixed $value,
        string $targetEntity,
        array|object $associationMapping,
        \ReflectionProperty $reflectionProperty,
    ): ?object {
        if (null === $value) {
            $nullable = (bool) (MappingHelper::getArray($associationMapping, 'joinColumns')[0]['nullable'] ?? true);

            if (!$nullable && !$this->isPropertyNullable($reflectionProperty)) {
                return $this->createTypeMismatchIssue(
                    entity: $entity,
                    fieldName: $assocName,
                    expectedType: $this->getShortClassName($targetEntity),
                    actualType: 'NULL',
                    value: null,
                    severity: Severity::critical(),
                );
            }

            return null;
        }

        if (!$value instanceof $targetEntity) {
            return $this->createTypeMismatchIssue(
                entity: $entity,
                fieldName: $assocName,
                expectedType: $this->getShortClassName($targetEntity),
                actualType: get_debug_type($value),
                value: $value,
                severity: Severity::critical(),
            );
        }

        return null;
    }

    /**
     * Check ToMany association type (collections).
     */
    private function checkToManyAssociation(
        object $entity,
        string $assocName,
        mixed $value,
        string $targetEntity,
    ): ?object {
        if (null === $value || (!is_iterable($value) && !$value instanceof \Countable)) {
            return $this->createTypeMismatchIssue(
                entity: $entity,
                fieldName: $assocName,
                expectedType: sprintf('Collection<%s>', $this->getShortClassName($targetEntity)),
                actualType: get_debug_type($value),
                value: $value,
                severity: Severity::warning(),
            );
        }

        return null;
    }

    /**
     * Check if enum has correct backing type.
     * @param array<string, mixed> $fieldMapping
     */
    private function checkEnumBackingType(mixed $value, array|object $fieldMapping, object $entity, string $fieldName): ?object
    {
        if (!is_object($value) || !class_exists($value::class)) {
            return null;
        }

        $enumClass      = $value::class;

        if (!enum_exists($enumClass)) {
            return null;
        }

        $reflectionEnum = new ReflectionEnum($enumClass);

        if (!$reflectionEnum->isBacked()) {
            return null;
        }

        $backingType = $reflectionEnum->getBackingType();

        if (!$backingType instanceof ReflectionNamedType) {
            return null;
        }

        $doctrineType    = MappingHelper::getString($fieldMapping, 'type');

        // Skip if no type information
        if (null === $doctrineType) {
            return null;
        }

        $expectedPhpType = $this->doctrineTypeToPhpType($doctrineType);

        if ($backingType->getName() !== $expectedPhpType) {
            return $this->createTypeMismatchIssue(
                entity: $entity,
                fieldName: $fieldName,
                expectedType: sprintf(
                    'Enum %s with backing type matching database type %s (%s)',
                    $this->getShortClassName($enumClass),
                    $doctrineType,
                    $expectedPhpType,
                ),
                actualType: sprintf(
                    'Enum %s with backing type %s',
                    $this->getShortClassName($enumClass),
                    $backingType->getName(),
                ),
                value: $value,
                severity: Severity::critical(),
            );
        }

        return null;
    }

    /**
     * Check if property is declared as nullable in PHP.
     */
    private function isPropertyNullable(\ReflectionProperty $reflectionProperty): bool
    {
        $type = $reflectionProperty->getType();

        if (null === $type) {
            return true; // No type hint = accepts null
        }

        return $type->allowsNull();
    }

    /**
     * Check if value is compatible with expected PHP type.
     */
    private function isTypeCompatible(mixed $value, string $expectedPhpType): bool
    {
        // Exact match
        if (get_debug_type($value) === $expectedPhpType) {
            return true;
        }

        // Special cases
        return match ($expectedPhpType) {
            'int'    => is_int($value) || (is_string($value) && ctype_digit($value)),
            'float'  => is_float($value) || is_int($value) || is_numeric($value),
            'string' => is_string($value) || is_numeric($value),
            'bool'   => is_bool($value) || 0 === $value || 1 === $value || '0' === $value || '1' === $value,
            'array'  => is_array($value),
            'DateTime', 'DateTimeImmutable' => $value instanceof \DateTimeInterface,
            default => true, // Unknown types, allow
        };
    }

    /**
     * Convert Doctrine type to PHP type.
     */
    private function doctrineTypeToPhpType(string $doctrineType): string
    {
        return match ($doctrineType) {
            'integer', 'smallint', 'bigint' => 'int',
            'decimal', 'float' => 'float',
            'string', 'text', 'guid' => 'string',
            'boolean' => 'bool',
            'datetime', 'datetime_immutable', 'datetimetz', 'datetimetz_immutable' => 'DateTime',
            'date', 'date_immutable' => 'DateTime',
            'time', 'time_immutable' => 'DateTime',
            'json', 'simple_array' => 'array',
            default => 'mixed',
        };
    }

    /**
     * Create a type mismatch issue.
     */
    private function createTypeMismatchIssue(
        object $entity,
        string $fieldName,
        string $expectedType,
        string $actualType,
        mixed $value,
        Severity $severity,
    ): object {
        $entityClass    = $entity::class;
        $shortClassName = $this->getShortClassName($entityClass);

        $description = sprintf(
            "Property %s::\$%s has type mismatch:
",
            $shortClassName,
            $fieldName,
        );
        $description .= sprintf("  Expected: %s
", $expectedType);
        $description .= sprintf("  Actual:   %s

", $actualType);

        if (null !== $value && !is_object($value)) {
            $description .= sprintf("Value: %s

", var_export($value, true));
        }

        $description .= "Possible causes:
";
        $description .= "- Database column type doesn't match Doctrine mapping
";
        $description .= "- Custom type converter returning wrong type
";
        $description .= "- Manual property assignment without type checking
";
        $description .= "- Migration changed database type without updating entity

";

        $description .= "Solutions:
";
        $description .= "1. Fix the property type annotation in the entity
";
        $description .= "2. Update the Doctrine mapping to match database column type
";
        $description .= '3. Create a migration to fix the database column type';

        $issueData = new IssueData(
            type: 'property_type_mismatch',
            title: sprintf(
                'Type Mismatch: %s::\$%s',
                $shortClassName,
                $fieldName,
            ),
            description: $description,
            severity: $severity,
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
