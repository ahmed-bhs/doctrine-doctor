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
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionEnum;

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
class PropertyTypeMismatchAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;
    use ShortClassNameTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IssueFactoryInterface $issueFactory,
        private readonly ?SuggestionFactoryInterface $suggestionFactory = null,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(
            function () {
                $metadataFactory = $this->entityManager->getMetadataFactory();
                $allMetadata = $metadataFactory->getAllMetadata();

                foreach ($allMetadata as $metadata) {
                    $entityIssues = $this->analyzeEntity($metadata);

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
                sprintf('%s (non-nullable)', $this->shortClassName($targetEntity)),
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
            $shortEnum = $this->shortClassName($enumClass);

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
        $shortClassName = $this->shortClassName($entityClass);

        $description = sprintf(
            "Property %s::\$%s has a type mismatch.\n",
            $shortClassName,
            $fieldName,
        );
        $description .= sprintf("Impact: Expected %s, actual %s.\n", $expectedType, $actualType);
        $description .= "Impact: Doctrine may detect false changes and issue unnecessary UPDATE queries.\n";
        $description .= "Impact: Persisted values can diverge from PHP expectations at runtime.";

        $suggestion = $this->buildSuggestion($shortClassName, $fieldName, $expectedType, $actualType, $severity);

        $issueData = new IssueData(
            type: IssueType::PROPERTY_TYPE_MISMATCH->value,
            title: sprintf('Type Mismatch: %s::\$%s', $shortClassName, $fieldName),
            description: $description,
            severity: $severity,
            suggestion: $suggestion,
            queries: [],
        );

        return $this->issueFactory->create($issueData);
    }

    private function buildSuggestion(
        string $shortClassName,
        string $fieldName,
        string $expectedType,
        string $actualType,
        Severity $severity,
    ): ?\AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface {
        if (null === $this->suggestionFactory) {
            return null;
        }

        $isNullabilityMismatch = str_contains($expectedType, 'nullable');

        if ($isNullabilityMismatch && str_contains($expectedType, 'non-nullable') && str_contains($actualType, 'nullable')) {
            $baseType = preg_replace('/^\?/', '', preg_replace('/\s*\(nullable\)/', '', $actualType));
            $badCode = sprintf("#[ORM\\Column(type: '...')]  // nullable not set (defaults to false)\nprivate ?%s \$%s = null;", $baseType, $fieldName);
            $goodCode = sprintf(
                "// Option 1: Make the column nullable\n#[ORM\\Column(type: '...', nullable: true)]\nprivate ?%s \$%s = null;\n\n// Option 2: Make the PHP property non-nullable\n#[ORM\\Column(type: '...')]\nprivate %s \$%s;",
                $baseType,
                $fieldName,
                $baseType,
                $fieldName,
            );
            $descriptionText = sprintf(
                'Property %s::$%s is declared as nullable in PHP (?%s) but the Doctrine column is non-nullable. Either add nullable: true to the mapping or remove the ? from the type hint.',
                $shortClassName,
                $fieldName,
                $baseType,
            );
        } elseif ($isNullabilityMismatch && str_contains($expectedType, 'nullable') && str_contains($actualType, 'non-nullable')) {
            $baseType = preg_replace('/\s*\(non-nullable\)/', '', $actualType);
            $badCode = sprintf("#[ORM\\Column(type: '...', nullable: true)]\nprivate %s \$%s;", $baseType, $fieldName);
            $goodCode = sprintf(
                "// Option 1: Make the PHP property nullable\n#[ORM\\Column(type: '...', nullable: true)]\nprivate ?%s \$%s = null;\n\n// Option 2: Remove nullable from the column\n#[ORM\\Column(type: '...')]\nprivate %s \$%s;",
                $baseType,
                $fieldName,
                $baseType,
                $fieldName,
            );
            $descriptionText = sprintf(
                'Property %s::$%s is declared as non-nullable in PHP (%s) but the Doctrine column is nullable. Either add ? to the type hint or remove nullable: true from the mapping.',
                $shortClassName,
                $fieldName,
                $baseType,
            );
        } else {
            $badCode = sprintf("private %s \$%s;  // Doctrine expects: %s", $actualType, $fieldName, $expectedType);
            $goodCode = sprintf("private %s \$%s;", preg_replace('/\s*\(.*\)/', '', $expectedType), $fieldName);
            $descriptionText = sprintf(
                'Property %s::$%s has type %s but Doctrine expects %s.',
                $shortClassName,
                $fieldName,
                $actualType,
                $expectedType,
            );
        }

        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/type_hint_mismatch',
            context: [
                'bad_code' => $badCode,
                'good_code' => $goodCode,
                'description' => $descriptionText,
                'performance_impact' => [
                    'Unnecessary UPDATE queries executed on every flush',
                    'Increased database load from phantom changes',
                    'Potential runtime errors from null/non-null mismatch',
                ],
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: $severity,
                title: sprintf('Fix type mismatch on %s::$%s', $shortClassName, $fieldName),
                tags: ['integrity', 'doctrine', 'type-mismatch', 'property'],
            ),
        );
    }
}
