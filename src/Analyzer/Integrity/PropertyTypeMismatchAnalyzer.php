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
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionEnum;
use Webmozart\Assert\Assert;

/**
 * Detects type mismatches between entity property declarations and Doctrine mappings.
 * Inspired by PHPStan's EntityColumnRule.
 *
 * Static analysis version - checks metadata and reflection types without
 * accessing entity instances (no lazy loading triggered).
 *
 * Detects:
 * - PHP property type doesn't match Doctrine column type
 * - Non-nullable Doctrine column with nullable PHP property (or vice versa)
 * - Enum backing type mismatch with database column type
 */
class PropertyTypeMismatchAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
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
            function () {
                $metadataFactory = $this->entityManager->getMetadataFactory();
                $allMetadata = $metadataFactory->getAllMetadata();

                Assert::isIterable($allMetadata, '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    $entityIssues = $this->analyzeEntity($metadata);

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
        return 'Property Type Mismatch Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects type mismatches between entity property declarations and Doctrine mappings';
    }

    /**
     * @template T of object
     * @param ClassMetadata<T> $classMetadata
     * @return list<IssueInterface>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {
        $reflectionClass = $classMetadata->reflClass;

        if (null === $reflectionClass) {
            return [];
        }

        $issues = [];

        foreach ($classMetadata->getFieldNames() as $fieldName) {
            $issue = $this->checkFieldType($fieldName, $classMetadata, $reflectionClass);
            if (null !== $issue) {
                $issues[] = $issue;
            }
        }

        foreach ($classMetadata->getAssociationNames() as $assocName) {
            $issue = $this->checkAssociationType($assocName, $classMetadata, $reflectionClass);
            if (null !== $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    private function checkFieldType(
        string $fieldName,
        ClassMetadata $classMetadata,
        \ReflectionClass $reflectionClass,
    ): ?IssueInterface {
        if (!$reflectionClass->hasProperty($fieldName)) {
            return null;
        }

        $property = $reflectionClass->getProperty($fieldName);
        $propertyType = $property->getType();

        if (!$propertyType instanceof \ReflectionNamedType) {
            return null;
        }

        $fieldMapping = $classMetadata->getFieldMapping($fieldName);
        $doctrineType = MappingHelper::getString($fieldMapping, 'type');

        if (null === $doctrineType) {
            return null;
        }

        $nullable = (bool) (MappingHelper::getBool($fieldMapping, 'nullable') ?? false);
        $phpTypeName = $propertyType->getName();
        $isIdentifier = $classMetadata->isIdentifier($fieldName);

        $nullabilityIssue = $this->checkNullabilityMismatch(
            $classMetadata->getName(),
            $fieldName,
            $doctrineType,
            $phpTypeName,
            $nullable,
            $propertyType->allowsNull(),
            $isIdentifier,
        );

        if (null !== $nullabilityIssue) {
            return $nullabilityIssue;
        }

        $expectedPhpType = $this->doctrineTypeToPhpType($doctrineType);

        if ('mixed' !== $expectedPhpType && $phpTypeName !== $expectedPhpType && !$this->isTypeCompatible($phpTypeName, $expectedPhpType)) {
            return $this->createIssue(
                $classMetadata->getName(),
                $fieldName,
                sprintf('%s (PHP: %s)', $doctrineType, $expectedPhpType),
                $phpTypeName,
                Severity::warning(),
            );
        }

        $enumType = MappingHelper::getProperty($fieldMapping, 'enumType');
        if (null !== $enumType && \is_string($enumType) && enum_exists($enumType)) {
            return $this->checkEnumBackingType($classMetadata->getName(), $fieldName, $enumType, $doctrineType);
        }

        return null;
    }

    private function checkNullabilityMismatch(
        string $entityClass,
        string $fieldName,
        string $doctrineType,
        string $phpTypeName,
        bool $nullable,
        bool $phpAllowsNull,
        bool $isIdentifier,
    ): ?IssueInterface {
        if ($isIdentifier) {
            return null;
        }

        if (!$nullable && $phpAllowsNull) {
            return $this->createIssue(
                $entityClass,
                $fieldName,
                sprintf('%s (non-nullable)', $doctrineType),
                sprintf('?%s (nullable)', $phpTypeName),
                Severity::warning(),
            );
        }

        if ($nullable && !$phpAllowsNull) {
            return $this->createIssue(
                $entityClass,
                $fieldName,
                sprintf('%s (nullable)', $doctrineType),
                sprintf('%s (non-nullable)', $phpTypeName),
                Severity::warning(),
            );
        }

        return null;
    }

    private function checkAssociationType(
        string $assocName,
        ClassMetadata $classMetadata,
        \ReflectionClass $reflectionClass,
    ): ?IssueInterface {
        if (!$reflectionClass->hasProperty($assocName)) {
            return null;
        }

        if (!$classMetadata->isSingleValuedAssociation($assocName)) {
            return null;
        }

        $property = $reflectionClass->getProperty($assocName);
        $propertyType = $property->getType();

        if (!$propertyType instanceof \ReflectionNamedType) {
            return null;
        }

        $mapping = $classMetadata->getAssociationMapping($assocName);
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity');

        if (null === $targetEntity) {
            return null;
        }

        $joinColumns = $mapping['joinColumns'] ?? [];
        $nullable = true;
        if (\is_array($joinColumns) && isset($joinColumns[0]) && \is_array($joinColumns[0])) {
            $nullable = (bool) ($joinColumns[0]['nullable'] ?? true);
        }

        if (!$nullable && $propertyType->allowsNull()) {
            return $this->createIssue(
                $classMetadata->getName(),
                $assocName,
                sprintf('%s (non-nullable)', $this->getShortClassName($targetEntity)),
                sprintf('?%s (nullable)', $propertyType->getName()),
                Severity::warning(),
            );
        }

        return null;
    }

    private function checkEnumBackingType(
        string $entityClass,
        string $fieldName,
        string $enumClass,
        string $doctrineType,
    ): ?IssueInterface {
        /** @var class-string<\UnitEnum> $enumClass */
        $reflectionEnum = new ReflectionEnum($enumClass);

        if (!$reflectionEnum->isBacked()) {
            return null;
        }

        $backingType = $reflectionEnum->getBackingType();
        if (!$backingType instanceof \ReflectionNamedType) {
            return null;
        }

        $expectedPhpType = $this->doctrineTypeToPhpType($doctrineType);
        $backingTypeName = $backingType->getName();

        if ($backingTypeName !== $expectedPhpType && 'mixed' !== $expectedPhpType) {
            $shortEnum = $this->getShortClassName($enumClass);

            return $this->createIssue(
                $entityClass,
                $fieldName,
                sprintf('Enum %s backing type matching %s (%s)', $shortEnum, $doctrineType, $expectedPhpType),
                sprintf('Enum %s with backing type %s', $shortEnum, $backingTypeName),
                Severity::critical(),
            );
        }

        return null;
    }

    private function isTypeCompatible(string $phpType, string $expectedType): bool
    {
        if ($phpType === $expectedType) {
            return true;
        }

        return match ($expectedType) {
            'int' => 'float' === $phpType,
            'float' => 'int' === $phpType,
            'DateTime' => \in_array($phpType, ['DateTimeInterface', 'DateTimeImmutable', 'DateTime'], true),
            'DateTimeImmutable' => \in_array($phpType, ['DateTimeInterface', 'DateTime'], true),
            default => false,
        };
    }

    private function doctrineTypeToPhpType(string $doctrineType): string
    {
        return match ($doctrineType) {
            'integer', 'smallint' => 'int',
            'bigint', 'decimal' => 'string',
            'float' => 'float',
            'string', 'text', 'guid' => 'string',
            'boolean' => 'bool',
            'datetime', 'datetimetz' => 'DateTime',
            'datetime_immutable', 'datetimetz_immutable' => 'DateTimeImmutable',
            'date' => 'DateTime',
            'date_immutable' => 'DateTimeImmutable',
            'time' => 'DateTime',
            'time_immutable' => 'DateTimeImmutable',
            'json', 'simple_array' => 'array',
            default => 'mixed',
        };
    }

    private function createIssue(
        string $entityClass,
        string $fieldName,
        string $expectedType,
        string $actualType,
        Severity $severity,
    ): IssueInterface {
        $shortClassName = $this->getShortClassName($entityClass);

        $description = sprintf(
            "Property %s::\$%s has type mismatch:\n",
            $shortClassName,
            $fieldName,
        );
        $description .= sprintf("  Expected: %s\n", $expectedType);
        $description .= sprintf("  Actual:   %s\n\n", $actualType);

        $description .= "Possible causes:\n";
        $description .= "- Database column type doesn't match Doctrine mapping\n";
        $description .= "- Property type hint doesn't match mapping nullable setting\n";
        $description .= "- Migration changed database type without updating entity\n\n";

        $description .= "Solutions:\n";
        $description .= "1. Fix the property type annotation in the entity\n";
        $description .= "2. Update the Doctrine mapping to match the property type\n";
        $description .= '3. Create a migration to fix the database column type';

        $issueData = new IssueData(
            type: 'property_type_mismatch',
            title: sprintf('Type Mismatch: %s::\$%s', $shortClassName, $fieldName),
            description: $description,
            severity: $severity,
            suggestion: null,
            queries: [],
        );

        return $this->issueFactory->create($issueData);
    }

    private function getShortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
