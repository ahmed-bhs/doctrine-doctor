<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\DQLPatternMatcher;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\QueryException;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Validates DQL queries for syntax errors and semantic issues.
 * Inspired by PHPStan's DqlRule and QueryBuilderDqlRule.
 * Detects DQL issues at runtime:
 * - Invalid entity class names in FROM/JOIN
 * - Non-existent fields in SELECT/WHERE/ORDER BY
 * - Undefined aliases in queries
 * - Invalid associations in JOIN clauses
 * - Syntax errors in DQL
 * Advantage over static analysis: Can detect dynamically constructed DQL
 * that static analyzers cannot process.
 */
class DQLValidationAnalyzer implements AnalyzerInterface
{
    /** @var array<string, bool> Cache to avoid validating same DQL multiple times */
    /** @var array<mixed> */
    private array $validatedDQL = [];

    private DQLPatternMatcher $dqlMatcher;

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
        ?DQLPatternMatcher $dqlMatcher = null,
    ) {
        $this->dqlMatcher = $dqlMatcher ?? new DQLPatternMatcher();
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        //  Article pattern: Use generator instead of array
        return IssueCollection::fromGenerator(
            /** @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void> */
            function () use ($queryDataCollection) {
                $this->validatedDQL = [];

                Assert::isIterable($queryDataCollection, '$queryDataCollection must be iterable');

                foreach ($queryDataCollection as $queryData) {
                    // Try to extract DQL from SQL query
                    $dql = $this->extractPossibleDQL($queryData);
                    if (null === $dql) {
                        continue;
                    }

                    if ('' === $dql) {
                        continue;
                    }

                    $issue = $this->validateDQL($dql, $queryData);
                    if (null === $issue) {
                        continue;
                    }

                    yield $issue;
                }
            },
        );
    }

    /**
     * Try to extract DQL from query backtrace or detect if it's likely a DQL query.
     * DQL queries typically have specific patterns in their backtrace showing
     * usage of QueryBuilder or createQuery().
     */
    private function extractPossibleDQL(QueryData $queryData): ?string
    {
        if (null === $queryData->backtrace) {
            return null;
        }

        // Look for DQL in backtrace
        foreach ($queryData->backtrace as $trace) {
            // Check if this is from QueryBuilder or createQuery
            $class    = $trace['class'] ?? '';
            $function = $trace['function'] ?? '';

            // Skip if not Doctrine-related
            if (!str_contains($class, 'Doctrine\\')) {
                continue;
            }

            // Look for DQL query markers
            if (
                str_contains($class, 'QueryBuilder')
                || str_contains($class, 'Query')
                || 'createQuery' === $function
                || 'getDQL' === $function
                || 'getQuery' === $function
            ) {
                // Try to reconstruct DQL from SQL
                return $this->reconstructDQLFromSQL($queryData->sql);
            }
        }

        return null;
    }

    /**
     * Attempt to reconstruct DQL from SQL.
     * This is a best-effort approach since SQL is already processed by Doctrine.
     */
    private function reconstructDQLFromSQL(string $sql): ?string
    {
        // Check for Doctrine-generated SQL pattern
        if (!$this->dqlMatcher->hasDoctrineSQLPattern($sql)) {
            // Doesn't look like Doctrine-generated SQL
            return null;
        }

        // For now, we validate the SQL directly since perfect DQL reconstruction
        // is complex. We'll look for entity/field references in the SQL.
        return $sql;
    }

    /**
     * Validate DQL/SQL for Doctrine-specific issues.
     */
    private function validateDQL(string $dql, QueryData $queryData): ?IssueInterface
    {
        // Skip if already validated
        $dqlHash = md5($dql);

        if (isset($this->validatedDQL[$dqlHash])) {
            return null;
        }

        $this->validatedDQL[$dqlHash] = true;

        $errors = [];

        // Check 1: Validate entity references
        $entityErrors = $this->validateEntityReferences($dql);
        if ([] !== $entityErrors) {
            $errors = array_merge($errors, $entityErrors);
        }

        // Check 2: Validate field references
        $fieldErrors = $this->validateFieldReferences($dql);
        if ([] !== $fieldErrors) {
            $errors = array_merge($errors, $fieldErrors);
        }

        // Check 3: Validate JOIN associations
        $joinErrors = $this->validateJoinAssociations($dql);
        if ([] !== $joinErrors) {
            $errors = array_merge($errors, $joinErrors);
        }

        // Check 4: Try to parse as actual DQL (if it looks like DQL)
        if ($this->looksPureDQL($dql)) {
            $parseError = $this->validateDQLSyntax($dql);
            if (null !== $parseError) {
                $errors[] = $parseError;
            }
        }

        if ([] === $errors) {
            return null;
        }

        return $this->createDQLIssue($errors, $queryData);
    }

    /**
     * Check if the query string looks like pure DQL (vs compiled SQL).
     */
    private function looksPureDQL(string $query): bool
    {
        return $this->dqlMatcher->looksPureDQL($query);
    }

    /**
     * Validate DQL syntax by actually parsing it.
     */
    private function validateDQLSyntax(string $dql): ?string
    {
        try {
            $query = $this->entityManager->createQuery($dql);
            $query->getAST(); // Force parsing

            return null; // Valid DQL
        } catch (QueryException $e) {
            return sprintf('DQL Syntax Error: %s', $e->getMessage());
        } catch (\Throwable $e) {
            // Other parsing errors - log for debugging
            $this->logger?->debug('DQL parsing error encountered', [
                'dql' => substr($dql, 0, 200), // Limit DQL length in logs
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return sprintf('DQL Parsing Error: %s', $e->getMessage());
        }
    }

    /**
     * Validate entity class references in the query.
     * @return string[]
     */
    private function validateEntityReferences(string $query): array
    {
        $errors = [];

        // Extract and validate entity classes from FROM clause
        $fromEntities = $this->dqlMatcher->extractEntityClassesFromFrom($query);
        foreach ($fromEntities as $entityClass) {
            if (!$this->isValidEntity($entityClass)) {
                $errors[] = sprintf(
                    'Unknown entity class "%s" in FROM clause',
                    $entityClass,
                );
            }
        }

        // Extract and validate entity classes from JOIN clause
        $joinEntities = $this->dqlMatcher->extractEntityClassesFromJoin($query);
        foreach ($joinEntities as $entityClass) {
            if (!$this->isValidEntity($entityClass)) {
                $errors[] = sprintf(
                    'Unknown entity class "%s" in JOIN clause',
                    $entityClass,
                );
            }
        }

        return $errors;
    }

    /**
     * Validate field references in the query.
     * @return string[]
     */
    private function validateFieldReferences(string $query): array
    {
        $errors = [];

        // Try to find table name and validate columns
        $tableAndAlias = $this->dqlMatcher->extractTableAndAlias($query);

        if (null !== $tableAndAlias) {
            $tableName = $tableAndAlias['table'];
            $alias = $tableAndAlias['alias'];

            $entity = $this->findEntityByTableName($tableName);

            // Now check if columns exist
            if (null !== $entity) {
                $columnErrors = $this->validateColumns($query, $entity, $alias);
                if ([] !== $columnErrors) {
                    $errors = array_merge($errors, $columnErrors);
                }
            }
        }

        return $errors;
    }

    /**
     * Validate columns/fields for a specific entity.
     * @return string[]
     */
    private function validateColumns(string $query, string $entityClass, string $alias): array
    {
        $errors = [];

        try {
            Assert::classExists($entityClass);
            $metadata = $this->entityManager->getClassMetadata($entityClass);
        } catch (\Throwable $throwable) {
            $this->logger?->debug('Failed to load metadata for column validation', [
                'entityClass' => $entityClass,
                'exception' => $throwable::class,
            ]);
            return [];
        }

        // Extract field references using DQL matcher
        $fieldNames = $this->dqlMatcher->extractFieldReferences($query, $alias);

        foreach ($fieldNames as $fieldName) {
            if (!$metadata->hasField($fieldName) && !$metadata->hasAssociation($fieldName)) {
                $errors[] = sprintf(
                    'Field "%s" does not exist in entity %s',
                    $fieldName,
                    $this->getShortClassName($entityClass),
                );
            }
        }

        return $errors;
    }

    /**
     * Validate JOIN associations.
     *
     * Simplified validation covering most common cases:
     * - FROM Entity alias
     * - JOIN alias.association newAlias
     *
     * Limitation: Doesn't track chained JOINs (JOIN o.items i where o comes from u.orders)
     * This would require full query context tracking, which is complex.
     *
     * @return string[]
     */
    private function validateJoinAssociations(string $query): array
    {
        $errors = [];

        // Build alias map from FROM clause
        $aliasMap = $this->dqlMatcher->buildAliasMap($query);

        if ([] === $aliasMap) {
            // No entity aliases found - skip validation
            return [];
        }

        // Extract JOIN associations
        $joins = $this->dqlMatcher->extractJoinAssociations($query);

        foreach ($joins as $join) {
            $sourceAlias = $join['source'];
            $associationName = $join['association'];
            $newAlias = $join['alias'];

            // Check if source alias is in our map (FROM clause)
            if (!isset($aliasMap[$sourceAlias])) {
                // Source alias not from FROM clause - might be from previous JOIN
                // Skip for now (would need full context tracking)
                continue;
            }

            $entityClass = $aliasMap[$sourceAlias];

            // Validate that association exists
            if (!$this->isValidEntity($entityClass)) {
                continue; // Entity itself is invalid, already reported elsewhere
            }

            try {
                Assert::classExists($entityClass);
                $metadata = $this->entityManager->getClassMetadata($entityClass);

                if (!$metadata->hasAssociation($associationName)) {
                    $errors[] = sprintf(
                        'Association "%s" does not exist in entity %s (JOIN %s.%s %s)',
                        $associationName,
                        $this->getShortClassName($entityClass),
                        $sourceAlias,
                        $associationName,
                        $newAlias,
                    );
                } else {
                    // Association exists - add new alias to map for potential chained JOINs
                    $targetEntity = $metadata->getAssociationTargetClass($associationName);
                    $aliasMap[$newAlias] = $targetEntity;
                }
            } catch (\Throwable $throwable) {
                $this->logger?->debug('Failed to validate JOIN association', [
                    'entityClass' => $entityClass,
                    'association' => $associationName,
                    'exception' => $throwable::class,
                ]);
            }
        }

        return $errors;
    }

    /**
     * Check if a class name is a valid entity.
     */
    private function isValidEntity(string $className): bool
    {
        try {
            Assert::classExists($className);
            $metadata = $this->entityManager->getClassMetadata($className);

            return $metadata instanceof ClassMetadata;
        } catch (\Throwable $throwable) {
            $this->logger?->debug('Failed to check if class is valid entity', [
                'className' => $className,
                'exception' => $throwable::class,
            ]);
            return false;
        }
    }

    /**
     * Find entity class by table name.
     */
    private function findEntityByTableName(string $tableName): ?string
    {
        try {
            /** @var array<ClassMetadata<object>> $allMetadata */
            $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

            Assert::isIterable($allMetadata, '$allMetadata must be iterable');

            foreach ($allMetadata as $metadata) {
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
     * Create a DQL validation issue.
     * @param string[] $errors
     */
    private function createDQLIssue(array $errors, QueryData $queryData): IssueInterface
    {
        $description = "DQL/Query Validation Issues:

";

        Assert::isIterable($errors, '$errors must be iterable');

        foreach ($errors as $i => $error) {
            Assert::integer($i, 'Array key must be int');
            $description .= sprintf("%d. %s
", $i + 1, $error);
        }

        $description .= "
 Query:
" . $this->formatQuery($queryData->sql);

        $description .= "

Impact:
";
        $description .= "- Query may fail at runtime
";
        $description .= "- Unexpected results or empty result sets
";
        $description .= "- Potential SQL errors
";

        $description .= "
Solution:
";
        $description .= "1. Verify entity class names are correct and fully qualified
";
        $description .= "2. Check that all field names match entity property names
";
        $description .= "3. Ensure associations are properly defined in entity metadata
";
        $description .= '4. Test the query in isolation to identify the exact issue';

        $issueData = new IssueData(
            type: 'dql_validation',
            title: sprintf('DQL Validation Issue (%d errors)', count($errors)),
            description: $description,
            severity: Severity::critical(),
            suggestion: null,
            queries: [$queryData],
            backtrace: $queryData->backtrace,
        );

        return $this->issueFactory->create($issueData);
    }

    /**
     * Format query for display (truncate if too long).
     */
    private function formatQuery(string $query): string
    {
        return $this->dqlMatcher->formatQueryForDisplay($query, 500);
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
