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
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Psr\Log\LoggerInterface;

/**
 * Detects ManyToMany associations whose join tables have extra columns beyond the two foreign keys.
 *
 * When a ManyToMany join table has extra columns (e.g., enrollment date, quantity), the association
 * should be refactored into two OneToMany relations with an explicit join entity. This is a costly
 * migration when done reactively — detecting it early prevents future rework.
 *
 * Example:
 * - Before: Student ManyToMany Course (join table has only FK columns)
 * - After: Student OneToMany Enrollment, Course OneToMany Enrollment (with enrollmentDate)
 */
class ManyToManyWithExtraColumnsAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;
    use ShortClassNameTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                try {
                    $schemaManager = $this->entityManager->getConnection()->createSchemaManager();
                    $tableNames = $schemaManager->listTableNames();

                    $metadataFactory = $this->entityManager->getMetadataFactory();
                    $allMetadata     = $metadataFactory->getAllMetadata();

                    foreach ($allMetadata as $metadata) {
                        if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                            continue;
                        }

                        $entityClass = $metadata->getName();

                        foreach ($metadata->getAssociationMappings() as $fieldName => $mapping) {
                            if (!$mapping instanceof ManyToManyOwningSideMapping) {
                                continue;
                            }

                            $joinTableName = $this->getJoinTableName($mapping);
                            if (null === $joinTableName) {
                                $this->logger?->debug('ManyToManyWithExtraColumnsAnalyzer: no join table name found', [
                                    'entity' => $entityClass,
                                    'field' => $fieldName,
                                ]);
                                continue;
                            }

                            if (!in_array($joinTableName, $tableNames, true)) {
                                $this->logger?->debug('ManyToManyWithExtraColumnsAnalyzer: join table does not exist', [
                                    'entity' => $entityClass,
                                    'field' => $fieldName,
                                    'table' => $joinTableName,
                                ]);
                                continue;
                            }

                            $extraColumns = $this->getExtraColumns($mapping, $schemaManager, $joinTableName);

                            $this->logger?->debug('ManyToManyWithExtraColumnsAnalyzer: analyzed join table', [
                                'entity' => $entityClass,
                                'field' => $fieldName,
                                'table' => $joinTableName,
                                'extraColumns' => $extraColumns,
                            ]);

                            if ([] !== $extraColumns) {
                                yield $this->createIssue($entityClass, $fieldName, $joinTableName, $extraColumns);
                            }
                        }
                    }
                } catch (\Throwable $throwable) {
                    $this->logger?->error('ManyToManyWithExtraColumnsAnalyzer failed', [
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                        'file' => $throwable->getFile(),
                        'line' => $throwable->getLine(),
                    ]);
                }
            },
        );
    }

    private function getJoinTableName(array|object $mapping): ?string
    {
        $joinTableDef = MappingHelper::getProperty($mapping, 'joinTable');

        if (null === $joinTableDef) {
            return null;
        }

        return MappingHelper::getString($joinTableDef, 'name');
    }

    /**
     * Get column names that exist in the join table but are not part of the expected foreign keys.
     *
     * @return array<string> Extra column names (lowercase)
     */
    private function getExtraColumns(
        array|object $mapping,
        AbstractSchemaManager $schemaManager,
        string $joinTableName,
    ): array {
        $joinTableDef = MappingHelper::getProperty($mapping, 'joinTable');

        $expectedNames = [];

        // Collect expected FK column names from joinColumns (FK to owning entity)
        $joinColumns = MappingHelper::getArray($joinTableDef, 'joinColumns');
        if (null !== $joinColumns) {
            foreach ($joinColumns as $column) {
                $columnName = $this->extractColumnName($column);
                if (null !== $columnName) {
                    $expectedNames[] = strtolower($columnName);
                }
            }
        }

        // Collect expected FK column names from inverseJoinColumns (FK to inverse entity)
        $inverseJoinColumns = MappingHelper::getArray($joinTableDef, 'inverseJoinColumns');
        if (null !== $inverseJoinColumns) {
            foreach ($inverseJoinColumns as $column) {
                $columnName = $this->extractColumnName($column);
                if (null !== $columnName) {
                    $expectedNames[] = strtolower($columnName);
                }
            }
        }

        $expectedNames = array_filter($expectedNames);

        // Get actual columns from the join table
        $columns = $schemaManager->listTableColumns($joinTableName);
        $actualColumnNames = array_map('strtolower', array_keys($columns));

        // Extra columns are those not in FK list
        $extraColumns = array_filter(
            $actualColumnNames,
            fn ($col) => !in_array($col, $expectedNames, true),
        );

        return array_values($extraColumns);
    }

    private function extractColumnName(mixed $column): ?string
    {
        if (is_array($column)) {
            return isset($column['name']) ? (string) $column['name'] : null;
        }

        return MappingHelper::getString($column, 'name');
    }

    private function createIssue(
        string $entityClass,
        string $fieldName,
        string $joinTableName,
        array $extraColumns,
    ): IntegrityIssue {
        $shortClassName = $this->shortClassName($entityClass);
        $columnsList = implode(', ', array_map(fn ($col) => "`{$col}`", $extraColumns));

        $description = DescriptionHighlighter::highlight(
            '{entity}::{field} is a {many_to_many} with a join table that has extra columns: {columns}. '
            . 'When the join table needs extra data, refactor to an explicit join entity with two {one_to_many} associations. '
            . 'This is a significant refactoring best done early.',
            [
                'entity' => $shortClassName,
                'field' => '$' . $fieldName,
                'many_to_many' => 'ManyToMany association',
                'columns' => $columnsList,
                'one_to_many' => 'OneToMany',
            ],
        );

        return new IntegrityIssue([
            'type' => IssueType::MANY_TO_MANY_EXTRA_COLUMNS->value,
            'title' => sprintf('%s::%s has a join table with extra columns', $shortClassName, $fieldName),
            'description' => $description,
            'severity' => Severity::warning(),
            'suggestion' => $this->suggestionFactory->createFromTemplate(
                templateName: 'Integrity/many_to_many_extra_columns',
                context: [
                    'entity_class' => $entityClass,
                    'field_name' => $fieldName,
                    'join_table' => $joinTableName,
                    'extra_columns' => $extraColumns,
                ],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::refactoring(),
                    severity: Severity::warning(),
                    title: 'Refactor ManyToMany to explicit join entity',
                    tags: ['refactoring', 'design'],
                ),
            ),
            'backtrace' => null,
            'queries' => [],
        ]);
    }
}
