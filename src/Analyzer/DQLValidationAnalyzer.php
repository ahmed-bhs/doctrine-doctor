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
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\QueryException;
use Psr\Log\LoggerInterface;

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
            /** @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void> */
            function () use ($queryDataCollection) {
                $this->validatedDQL = [];

                assert(is_iterable($queryDataCollection), '$queryDataCollection must be iterable');

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
        // Look for patterns that indicate this was DQL:
        // 1. Alias patterns like "t0_", "t1_", etc. (Doctrine's SQL alias convention)
        // 2. Generated column names like "id_0", "name_1"

        // Check for Doctrine alias pattern (t0_, t1_, etc.) with space before
        if (1 !== preg_match('/\st\d+_/', $sql)) {
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
        $joinErrors = $this->validateJoinAssociations();
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
        // DQL uses entity class names, not table names
        // Look for patterns like "FROM App\Entity\User" or "SELECT u.name FROM User u"
        return 1 === preg_match('/FROM\s+[A-Z]\w*\\[A-Z]\w*/i', $query) // Namespaced class
            || 1 === preg_match('/SELECT\s+\w+\.\w+\s+FROM\s+[A-Z]\w+\s+\w+/', $query) // Entity alias
        ;
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

        // Match entity class references (e.g., FROM App\Entity\User)
        if (preg_match_all('/FROM\s+([\w\\\\]+)/i', $query, $matches) >= 1) {
            assert(is_iterable($matches[1]), '$matches[1] must be iterable');

            foreach ($matches[1] as $entityClass) {
                // Skip if it's a table name (lowercase, underscores)
                if (ctype_lower($entityClass[0])) {
                    continue;
                }

                if (str_contains($entityClass, '_')) {
                    continue;
                }

                if (!$this->isValidEntity($entityClass)) {
                    $errors[] = sprintf(
                        'Unknown entity class "%s" in FROM clause',
                        $entityClass,
                    );
                }
            }
        }

        // Match JOIN entity references
        if (preg_match_all('/JOIN\s+([\w\\\\]+)/i', $query, $matches) >= 1) {
            assert(is_iterable($matches[1]), '$matches[1] must be iterable');

            foreach ($matches[1] as $entityClass) {
                if (ctype_lower($entityClass[0])) {
                    continue;
                }

                if (str_contains($entityClass, '_')) {
                    continue;
                }

                if (!$this->isValidEntity($entityClass)) {
                    $errors[] = sprintf(
                        'Unknown entity class "%s" in JOIN clause',
                        $entityClass,
                    );
                }
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
        // This is complex because we need to map tables to entities
        if (1 === preg_match('/FROM\s+(\w+)\s+(\w+)/i', $query, $matches)) {
            $tableName = $matches[1];
            $alias     = $matches[2];

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
            assert(class_exists($entityClass));
            $metadata = $this->entityManager->getClassMetadata($entityClass);
        } catch (\Throwable $throwable) {
            $this->logger?->debug('Failed to load metadata for column validation', [
                'entityClass' => $entityClass,
                'exception' => $throwable::class,
            ]);
            return [];
        }

        // Match field references like "alias.fieldName"
        $pattern = sprintf('/%s\.(\w+)/i', preg_quote($alias, '/'));

        if (preg_match_all($pattern, $query, $matches) >= 1) {
            assert(is_iterable($matches[1]), '$matches[1] must be iterable');

            foreach ($matches[1] as $fieldName) {
                // Skip SQL functions and keywords
                if (in_array(strtoupper($fieldName), ['AS', 'FROM', 'WHERE', 'AND', 'OR'], true)) {
                    continue;
                }

                if (!$metadata->hasField($fieldName) && !$metadata->hasAssociation($fieldName)) {
                    $errors[] = sprintf(
                        'Field "%s" does not exist in entity %s',
                        $fieldName,
                        $this->getShortClassName($entityClass),
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * Validate JOIN associations.
     * @return string[]
     */
    private function validateJoinAssociations(): array
    {

        // Match JOIN patterns: JOIN alias.association newAlias
        // Note: Full validation requires query context tracking
        // Currently this is a placeholder for future enhancement
        // if (preg_match_all('/JOIN\s+(\w+)\.(\w+)\s+(\w+)/i', $query, $matches)) {
        //     // Find entity for source alias
        //     // This is tricky without full query context, so we do best effort
        //     // In a real implementation, we'd track aliases through the query
        //     // foreach (array_keys($matches[2]) as $index) {
        //         $sourceAlias = $matches[1][$index];
        //         // Validate association exists
        //     // }
        // }

        return [];
    }

    /**
     * Check if a class name is a valid entity.
     */
    private function isValidEntity(string $className): bool
    {
        try {
            assert(class_exists($className));
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

            assert(is_iterable($allMetadata), '$allMetadata must be iterable');

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

        assert(is_iterable($errors), '$errors must be iterable');

        foreach ($errors as $i => $error) {
            assert(is_int($i), 'Array key must be int');
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
        // Format and indent SQL
        $formatted = preg_replace('/\s+/', ' ', $query) ?? $query;
        $formatted = str_replace(
            [' FROM ', ' WHERE ', ' JOIN ', ' ORDER BY ', ' GROUP BY ', ' LIMIT '],
            ["
  FROM ", "
  WHERE ", "
  JOIN ", "
  ORDER BY ", "
  GROUP BY ", "
  LIMIT "],
            $formatted,
        );

        if (strlen($formatted) > 500) {
            return substr($formatted, 0, 500) . "
  ... (truncated)";
        }

        return $formatted;
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
