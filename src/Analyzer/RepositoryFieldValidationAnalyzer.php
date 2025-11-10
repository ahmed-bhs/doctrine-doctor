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
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;

/**
 * Validates that fields used in repository methods (findBy, findOneBy, etc.) actually exist.
 * Inspired by PHPStan's RepositoryMethodCallRule.
 * Detects runtime calls to repository methods with invalid field names:
 * - $repo->findBy(['nom_invalide' => 'test'])
 * - $repo->findOneBy(['statut' => 'actif'])  // typo: should be 'status'
 * - $repo->count(['inexistent_field' => 1])
 */
class RepositoryFieldValidationAnalyzer implements AnalyzerInterface
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
        /**
         * @readonly
         */
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        //  Article pattern: Use generator instead of array
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $queryData) {
                    // Detect queries that look like they come from repository methods
                    $invalidFields = $this->detectInvalidFields($queryData);
                    if (null !== $invalidFields && [] !== $invalidFields) {
                        $issueData = new IssueData(
                            type: 'repository_invalid_field',
                            title: 'Invalid Field in Repository Method',
                            description: $this->generateDescription($invalidFields, $queryData),
                            severity: Severity::critical(),
                            suggestion: null,
                            queries: [$queryData],
                            backtrace: $queryData->backtrace,
                        );

                        yield $this->issueFactory->create($issueData);
                    }
                }
            },
        );
    }

    /**
     * Detect invalid fields used in findBy/findOneBy/count queries.
     * @return array{entity: string, invalidFields: string[], validFields: string[]}|null
     */
    private function detectInvalidFields(QueryData $queryData): ?array
    {
        $sql = $queryData->sql;

        // Pattern 1: Simple WHERE clauses from findBy/findOneBy
        // SELECT ... FROM table t0_ WHERE t0_.field1 = ? AND t0_.field2 = ?
        if (1 !== preg_match('/FROM\s+(\w+)\s+t\d+_/i', $sql, $tableMatch)) {
            return null;
        }

        $tableName = $tableMatch[1];

        // Find the entity for this table
        $entity = $this->findEntityByTable($tableName);

        if (null === $entity) {
            return null;
        }

        // Get metadata for validation
        try {
            /** @var class-string $entity */
            $metadata = $this->entityManager->getClassMetadata($entity);
        } catch (\Throwable $throwable) {
            $this->logger?->debug('Failed to load metadata for field validation', [
                'entity' => $entity,
                'exception' => $throwable::class,
            ]);
            return null;
        }

        // Extract column names from WHERE clause
        // Match patterns like: t0_.column_name = ?
        if (preg_match_all('/t\d+_\.(\w+)\s*(?:=|IN|LIKE|>|<|>=|<=|<>|!=)/i', $sql, $columnMatches) < 1) {
            return null;
        }

        $columnsInQuery = array_unique($columnMatches[1]);
        $invalidFields  = [];
        $validFields    = [];

        assert(is_iterable($columnsInQuery), '$columnsInQuery must be iterable');

        foreach ($columnsInQuery as $columnInQuery) {
            // Check if this column corresponds to a mapped field or association
            $fieldName = $this->columnToFieldName($columnInQuery, $metadata);

            if (!$metadata->hasField($fieldName) && !$metadata->hasAssociation($fieldName)) {
                $invalidFields[] = $fieldName;
            } else {
                $validFields[] = $fieldName;
            }
        }

        if ([] === $invalidFields) {
            return null;
        }

        return [
            'entity'        => $entity,
            'invalidFields' => $invalidFields,
            'validFields'   => $validFields,
        ];
    }

    /**
     * Find entity class by table name.
     */
    private function findEntityByTable(string $tableName): ?string
    {
        try {
            $metadatas = $this->entityManager->getMetadataFactory()->getAllMetadata();

            assert(is_iterable($metadatas), '$metadatas must be iterable');

            foreach ($metadatas as $metadata) {
                if ($metadata->getTableName() === $tableName) {
                    return $metadata->getName();
                }
            }
        } catch (\Throwable $throwable) {
            // Metadata loading failed - log for debugging
            $this->logger?->debug('Failed to find entity by table name', [
                'tableName' => $tableName,
                'exception' => $throwable::class,
            ]);
        }

        return null;
    }

    /**
     * Convert database column name to PHP field name.
     * Examples:
     * - user_id -> userId
     * - first_name -> firstName
     * - status -> status
     */
    private function columnToFieldName(string $columnName, ClassMetadata $classMetadata): string
    {
        // First, check if it's directly mapped
        $fieldNames = $classMetadata->getFieldNames();

        assert(is_iterable($fieldNames), '$fieldNames must be iterable');

        foreach ($fieldNames as $fieldName) {
            if ($classMetadata->getColumnName($fieldName) === $columnName) {
                return $fieldName;
            }
        }

        // Check associations (foreign keys)
        foreach ($classMetadata->getAssociationNames() as $assocName) {
            if ($classMetadata->isAssociationInverseSide($assocName)) {
                continue;
            }

            $mapping = $classMetadata->getAssociationMapping($assocName);

            if (isset($mapping['joinColumns'])) {
                assert(is_iterable($mapping['joinColumns']), 'joinColumns must be iterable');

                foreach ($mapping['joinColumns'] as $joinColumn) {
                    if ($joinColumn['name'] === $columnName) {
                        return $assocName;
                    }
                }
            }
        }

        // Fallback: convert snake_case to camelCase
        return lcfirst(str_replace('_', '', ucwords($columnName, '_')));
    }

    /**
     * @param array{entity: string, invalidFields: string[], validFields: string[]} $invalidFields
     */
    private function generateDescription(array $invalidFields, QueryData $queryData): string
    {
        $entity          = $invalidFields['entity'];
        $lastBackslashPos = strrpos($entity, '\\');
        $shortEntityName = substr($entity, false !== $lastBackslashPos ? $lastBackslashPos + 1 : 0);

        $description = sprintf(
            "Query uses invalid field(s) on entity %s:\n",
            $shortEntityName,
        );

        assert(is_iterable($invalidFields['invalidFields']), 'invalidFields must be iterable');

        foreach ($invalidFields['invalidFields'] as $field) {
            $description .= sprintf("  - '%s' does not exist\n", $field);
        }

        // Suggest similar fields if available
        try {
            /** @var class-string $entity */
            $metadata  = $this->entityManager->getClassMetadata($entity);
            $allFields = array_merge(
                $metadata->getFieldNames(),
                $metadata->getAssociationNames(),
            );

            $description .= "
Available fields:
";

            // Show only similar fields or first 10 if no match
            $suggestions = [];

            assert(is_iterable($invalidFields['invalidFields']), 'invalidFields must be iterable');

            foreach ($invalidFields['invalidFields'] as $invalidField) {
                $similar = $this->findSimilarFields($invalidField, $allFields);

                if ([] !== $similar) {
                    $suggestions[] = sprintf(
                        "  Did you mean: %s (instead of '%s')?",
                        implode(', ', array_map(fn (string $field): string => sprintf("'%s'", $field), $similar)),
                        $invalidField,
                    );
                }
            }

            if ([] !== $suggestions) {
                $description .= implode("
", $suggestions);
            } else {
                $description .= '  ' . implode(', ', array_slice($allFields, 0, 10));

                if (count($allFields) > 10) {
                    $description .= sprintf(' (and %d more)', count($allFields) - 10);
                }
            }
        } catch (\Throwable $throwable) {
            // Metadata error, skip suggestions - log for debugging
            $this->logger?->debug('Failed to generate field suggestions', [
                'entity' => $entity,
                'exception' => $throwable::class,
            ]);
        }

        $description .= "

Query: " . substr($queryData->sql, 0, 200);

        if (strlen($queryData->sql) > 200) {
            $description .= '...';
        }

        return $description;
    }

    /**
     * Find fields with similar names using Levenshtein distance.
     * @param string[] $availableFields
     * @return string[]
     */
    private function findSimilarFields(string $search, array $availableFields): array
    {

        $similar = [];
        $search  = strtolower($search);

        assert(is_iterable($availableFields), '$availableFields must be iterable');

        foreach ($availableFields as $availableField) {
            $fieldLower = strtolower($availableField);

            // Exact substring match
            if (str_contains($fieldLower, $search) || str_contains($search, $fieldLower)) {
                $similar[$availableField] = 0;
                continue;
            }

            // Levenshtein distance (only for similar length strings)
            $lenDiff = abs(strlen($search) - strlen($fieldLower));

            if ($lenDiff <= 3) {
                $distance = levenshtein($search, $fieldLower);

                if ($distance <= 3) {
                    $similar[$availableField] = $distance;
                }
            }
        }

        // Sort by similarity (lower distance = more similar)
        asort($similar);

        return array_slice(array_keys($similar), 0, 3);
    }
}
