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
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface as ValidatorMetadataFactoryInterface;

/**
 * Detects entities using Symfony's #[UniqueEntity] constraint without a
 * corresponding UNIQUE index on the referenced columns in the database schema.
 *
 * The #[UniqueEntity] validator only checks uniqueness at the PHP level by
 * executing a SELECT query before persisting. Without a UNIQUE index in the
 * database, concurrent requests can bypass validation and insert duplicate rows.
 *
 * Supports constraints declared via PHP attributes, YAML, or XML by using
 * Symfony's ValidatorMetadataFactory when available.
 *
 * @see https://github.com/symfony/symfony-docs/issues/7305
 */
class UniqueEntityWithoutDatabaseIndexAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;
    use ShortClassNameTrait;

    private const string UNIQUE_ENTITY_CLASS = 'Symfony\\Bridge\\Doctrine\\Validator\\Constraints\\UniqueEntity';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly IssueFactoryInterface $issueFactory,
        private readonly ?ValidatorMetadataFactoryInterface $validatorMetadataFactory = null,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(
            function () {
                if (!class_exists(self::UNIQUE_ENTITY_CLASS)) {
                    return;
                }

                $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

                foreach ($allMetadata as $metadata) {
                    if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                        continue;
                    }

                    yield from $this->analyzeEntity($metadata);
                }
            },
        );
    }

    public function getName(): string
    {
        return 'UniqueEntity Without Database Index Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects #[UniqueEntity] constraints without corresponding database UNIQUE index';
    }

    /**
     * @return iterable<IntegrityIssue>
     */
    private function analyzeEntity(ClassMetadata $metadata): iterable
    {
        $entityClass = $metadata->getName();
        $shortName = $metadata->getReflectionClass()->getShortName();

        foreach ($this->getUniqueEntityConstraints($metadata) as $uniqueEntity) {
            $fields = $this->extractFields($uniqueEntity);

            if ([] === $fields) {
                continue;
            }

            $columns = $this->resolveColumns($metadata, $fields);

            if ([] === $columns) {
                continue;
            }

            if ($this->hasMatchingUniqueIndex($metadata, $columns)) {
                continue;
            }

            $description = DescriptionHighlighter::highlight(
                '{class} has a {constraint} on fields ({fields}) but no corresponding UNIQUE index '
                . 'exists in the database schema. The validator only checks uniqueness at the PHP level '
                . 'via a SELECT query before persisting. Under concurrent requests, two processes can both '
                . 'pass validation and insert duplicate rows. '
                . 'Add a UNIQUE constraint at the database level to guarantee data integrity.',
                [
                    'class' => $shortName,
                    'constraint' => 'UniqueEntity',
                    'fields' => implode(', ', $fields),
                ],
            );

            $issueData = new IssueData(
                type: IssueType::UNIQUE_ENTITY_WITHOUT_INDEX->value,
                title: sprintf('UniqueEntity Without Database Index: %s (%s)', $shortName, implode(', ', $fields)),
                description: $description,
                severity: Severity::warning(),
                suggestion: $this->createSuggestion($entityClass, $shortName, $fields, $columns),
                queries: [],
                backtrace: $this->createEntityBacktrace($metadata),
            );

            /** @var IntegrityIssue $issue */
            $issue = $this->issueFactory->createFromArray($issueData->toArray() + [
                'entity' => $entityClass,
            ]);

            yield $issue;
        }
    }

    /**
     * @return list<object>
     */
    private function getUniqueEntityConstraints(ClassMetadata $metadata): array
    {
        if (null !== $this->validatorMetadataFactory) {
            return $this->getConstraintsFromValidatorMetadata($metadata);
        }

        return $this->getConstraintsFromAttributes($metadata);
    }

    /**
     * @return list<object>
     */
    private function getConstraintsFromValidatorMetadata(ClassMetadata $metadata): array
    {
        $validatorMetadataFactory = $this->validatorMetadataFactory;

        if (null === $validatorMetadataFactory) {
            return [];
        }

        $entityClass = $metadata->getName();

        if (!$validatorMetadataFactory->hasMetadataFor($entityClass)) {
            return [];
        }

        $validatorMetadata = $validatorMetadataFactory->getMetadataFor($entityClass);

        if (!$validatorMetadata instanceof ClassMetadataInterface) {
            return [];
        }

        $constraints = [];

        foreach ($validatorMetadata->getConstraints() as $constraint) {
            if ($constraint instanceof \Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity) {
                $constraints[] = $constraint;
            }
        }

        return $constraints;
    }

    /**
     * @return list<object>
     */
    private function getConstraintsFromAttributes(ClassMetadata $metadata): array
    {
        $reflectionClass = $metadata->getReflectionClass();
        $attributes = $reflectionClass->getAttributes(self::UNIQUE_ENTITY_CLASS, \ReflectionAttribute::IS_INSTANCEOF);

        $constraints = [];

        foreach ($attributes as $attribute) {
            $constraints[] = $attribute->newInstance();
        }

        return $constraints;
    }

    /**
     * @return list<string>
     */
    private function extractFields(object $uniqueEntity): array
    {
        $fields = $uniqueEntity->fields ?? [];

        if (\is_string($fields)) {
            return [$fields];
        }

        if (\is_array($fields)) {
            return array_values(array_filter($fields, '\is_string'));
        }

        return [];
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    private function resolveColumns(ClassMetadata $metadata, array $fields): array
    {
        $columns = [];

        foreach ($fields as $field) {
            if ($metadata->hasField($field)) {
                $columns[] = $metadata->getColumnName($field);
            } elseif ($metadata->hasAssociation($field)) {
                $mapping = $metadata->getAssociationMapping($field);
                $joinColumns = MappingHelper::getArray($mapping, 'joinColumns');

                if ([] !== $joinColumns) {
                    $firstJoin = $joinColumns[0] ?? [];
                    $columnName = \is_array($firstJoin) ? ($firstJoin['name'] ?? null) : (MappingHelper::getString($firstJoin, 'name') ?? null);

                    if (\is_string($columnName)) {
                        $columns[] = $columnName;
                    }
                }
            }
        }

        return $columns;
    }

    /**
     * @param list<string> $columns
     */
    private function hasMatchingUniqueIndex(ClassMetadata $metadata, array $columns): bool
    {
        sort($columns);

        if (1 === \count($columns)) {
            $fieldName = $this->findFieldByColumn($metadata, $columns[0]);

            if (null !== $fieldName && $metadata->hasField($fieldName)) {
                $fieldMapping = $metadata->getFieldMapping($fieldName);
                if (isset($fieldMapping['unique']) && true === $fieldMapping['unique']) {
                    return true;
                }
            }
        }

        $table = $metadata->table ?? [];
        $uniqueConstraints = $table['uniqueConstraints'] ?? [];

        foreach ($uniqueConstraints as $constraint) {
            $constraintColumns = \is_array($constraint)
                ? ($constraint['columns'] ?? [])
                : (property_exists($constraint, 'columns') ? $constraint->columns ?? [] : []);

            $sorted = $constraintColumns;
            sort($sorted);

            if ($sorted === $columns) {
                return true;
            }
        }

        $indexes = $table['indexes'] ?? [];

        foreach ($indexes as $index) {
            $isUnique = \is_array($index)
                ? ($index['unique'] ?? false)
                : (property_exists($index, 'unique') ? $index->unique ?? false : false);

            if (!$isUnique) {
                continue;
            }

            $indexColumns = \is_array($index)
                ? ($index['columns'] ?? [])
                : (property_exists($index, 'columns') ? $index->columns ?? [] : []);

            $sorted = $indexColumns;
            sort($sorted);

            if ($sorted === $columns) {
                return true;
            }
        }

        return false;
    }

    private function findFieldByColumn(ClassMetadata $metadata, string $columnName): ?string
    {
        foreach ($metadata->getFieldNames() as $fieldName) {
            if ($metadata->getColumnName($fieldName) === $columnName) {
                return $fieldName;
            }
        }

        return null;
    }

    /**
     * @param list<string> $fields
     * @param list<string> $columns
     */
    private function createSuggestion(
        string $entityClass,
        string $shortName,
        array $fields,
        array $columns,
    ): \AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/unique_entity_without_database_index',
            context: [
                'entity_class' => $shortName,
                'entity_fqcn' => $entityClass,
                'fields' => $fields,
                'columns' => $columns,
                'is_single_column' => 1 === \count($columns),
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::warning(),
                title: 'Add Database UNIQUE Index for UniqueEntity Constraint',
                tags: ['unique-entity', 'race-condition', 'data-integrity', 'index'],
            ),
        );
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function createEntityBacktrace(ClassMetadata $classMetadata): ?array
    {
        try {
            $reflectionClass = $classMetadata->getReflectionClass();
            $fileName = $reflectionClass->getFileName();
            $startLine = $reflectionClass->getStartLine();

            if (false === $fileName || false === $startLine) {
                return null;
            }

            return [[
                'file' => $fileName,
                'line' => $startLine,
                'class' => $classMetadata->getName(),
                'function' => '__construct',
                'type' => '::',
            ]];
        } catch (\Exception) {
            return null;
        }
    }
}
