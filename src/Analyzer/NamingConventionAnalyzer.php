<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\NamingConventionHelper;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Issue\CodeQualityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\CodeSuggestion;
use AhmedBhs\DoctrineDoctor\Template\Renderer\TemplateRendererInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;

/**
 * Detects violations of database naming conventions.
 * Standard Doctrine/Symfony conventions:
 * - Tables: snake_case, plural (users, order_items)
 * - Columns: snake_case (first_name, created_at)
 * - Foreign Keys: snake_case with _id suffix (user_id, product_id)
 * - Indexes: idx_ prefix (idx_email, idx_status_created_at)
 * - Unique constraints: uniq_ prefix (uniq_email)
 * Example violations:
 * Table: UserProfiles (should be user_profiles)
 * Column: firstName (should be first_name)
 * FK: userId (should be user_id)
 * Index: email_index (should be idx_email)
 */
class NamingConventionAnalyzer implements AnalyzerInterface
{
    /**
     * SQL reserved keywords that should be avoided or quoted.
     */
    private const SQL_RESERVED_KEYWORDS = [
        'user', 'order', 'group', 'table', 'key', 'index', 'select', 'insert',
        'update', 'delete', 'from', 'where', 'join', 'left', 'right', 'inner',
        'outer', 'on', 'as', 'and', 'or', 'not', 'null', 'like', 'in', 'between',
        'exists', 'all', 'any', 'some', 'union', 'intersect', 'except', 'case',
        'when', 'then', 'else', 'end', 'desc', 'asc', 'limit', 'offset', 'having',
        'distinct', 'values', 'default', 'constraint', 'primary', 'foreign',
        'references', 'check', 'unique', 'transaction', 'commit', 'rollback',
    ];

    /**
     * @readonly
     */
    private NamingConventionHelper $helper;

    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private TemplateRendererInterface $templateRenderer,
    ) {
        $this->helper = new NamingConventionHelper();
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                $classMetadataFactory = $this->entityManager->getMetadataFactory();
                $allMetadata          = $classMetadataFactory->getAllMetadata();

                assert(is_iterable($allMetadata), '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    // Check table naming
                    $tableIssues = $this->analyzeTableNaming($metadata);

                    assert(is_iterable($tableIssues), '$tableIssues must be iterable');

                    foreach ($tableIssues as $tableIssue) {
                        yield $tableIssue;
                    }

                    // Check column naming
                    $columnIssues = $this->analyzeColumnNaming($metadata);

                    assert(is_iterable($columnIssues), '$columnIssues must be iterable');

                    foreach ($columnIssues as $columnIssue) {
                        yield $columnIssue;
                    }

                    // Check foreign key naming
                    $fkIssues = $this->analyzeForeignKeyNaming($metadata);

                    assert(is_iterable($fkIssues), '$fkIssues must be iterable');

                    foreach ($fkIssues as $fkIssue) {
                        yield $fkIssue;
                    }

                    // Check index naming
                    $indexIssues = $this->analyzeIndexNaming($metadata);

                    assert(is_iterable($indexIssues), '$indexIssues must be iterable');

                    foreach ($indexIssues as $indexIssue) {
                        yield $indexIssue;
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Naming Convention Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects violations of database naming conventions (tables, columns, foreign keys, indexes should use snake_case)';
    }

    /**
     * Analyze table naming conventions.
     */
    private function analyzeTableNaming(ClassMetadata $classMetadata): array
    {

        $issues      = [];
        $tableName   = $classMetadata->getTableName();
        $entityClass = $classMetadata->getName();

        // Check 1: Should be snake_case
        if (!$this->helper->isSnakeCase($tableName)) {
            $issues[] = $this->createTableNamingIssue(
                $entityClass,
                $tableName,
                'not_snake_case',
                $this->helper->toSnakeCase($tableName),
            );
        }

        // Check 2: Should be SINGULAR (best practice)
        // ORM best practice: table names should be singular to match entity names
        // Example: Entity "User" → table "user" (NOT "users")
        if ($this->helper->isSnakeCase($tableName) && $this->helper->isPlural($tableName)) {
            $issues[] = $this->createTableNamingIssue(
                $entityClass,
                $tableName,
                'plural',  // Changed from 'singular' to 'plural'
                $this->helper->toSingular($tableName),  // Changed from toPlural() to toSingular()
                'info',
            );
        }

        // Check 3: SQL reserved keyword (INFO level - Doctrine quotes identifiers by default)
        if ($this->isSQLReservedKeyword($tableName)) {
            $issues[] = $this->createTableNamingIssue(
                $entityClass,
                $tableName,
                'reserved_keyword',
                $tableName . 's',
                'info',  // Changed from 'high' to 'info' - Doctrine handles this automatically
            );
        }

        // Check 4: Contains special characters (except underscore)
        if ($this->helper->hasSpecialCharacters($tableName)) {
            $issues[] = $this->createTableNamingIssue(
                $entityClass,
                $tableName,
                'special_characters',
                $this->helper->removeSpecialCharacters($tableName),
            );
        }

        return $issues;
    }

    /**
     * Analyze column naming conventions.
     */
    private function analyzeColumnNaming(ClassMetadata $classMetadata): array
    {

        $issues      = [];
        $entityClass = $classMetadata->getName();

        foreach ($classMetadata->getFieldNames() as $fieldName) {
            $columnName = $classMetadata->getColumnName($fieldName);

            // Check 1: Should be snake_case
            if (!$this->helper->isSnakeCase($columnName)) {
                $issues[] = $this->createColumnNamingIssue(
                    $entityClass,
                    $columnName,
                    $fieldName,
                    'not_snake_case',
                    $this->helper->toSnakeCase($columnName),
                );
            }

            // Check 2: SQL reserved keyword (INFO level - Doctrine quotes identifiers by default)
            if ($this->isSQLReservedKeyword($columnName)) {
                $issues[] = $this->createColumnNamingIssue(
                    $entityClass,
                    $columnName,
                    $fieldName,
                    'reserved_keyword',
                    $columnName . '_value',
                    'info',  // Changed from 'high' to 'info' - Doctrine handles this automatically
                );
            }

            // Check 3: Special characters
            if ($this->helper->hasSpecialCharacters($columnName)) {
                $issues[] = $this->createColumnNamingIssue(
                    $entityClass,
                    $columnName,
                    $fieldName,
                    'special_characters',
                    $this->helper->removeSpecialCharacters($columnName),
                );
            }
        }

        return $issues;
    }

    /**
     * Analyze foreign key naming conventions.
     */
    private function analyzeForeignKeyNaming(ClassMetadata $classMetadata): array
    {

        $issues      = [];
        $entityClass = $classMetadata->getName();

        foreach ($classMetadata->getAssociationMappings() as $assocName => $associationMapping) {
            // Only check owning side (ManyToOne, OneToOne with joinColumns)
            if (!isset($associationMapping['joinColumns'])) {
                continue;
            }

            assert(is_iterable($associationMapping['joinColumns']), 'joinColumns must be iterable');

            foreach ($associationMapping['joinColumns'] as $joinColumn) {
                $columnName = $joinColumn['name'] ?? null;

                if (null === $columnName) {
                    continue;
                }

                // Check 1: Should be snake_case
                if (!$this->helper->isSnakeCase($columnName)) {
                    $issues[] = $this->createForeignKeyNamingIssue(
                        $entityClass,
                        $columnName,
                        $assocName,
                        'not_snake_case',
                        $this->helper->toSnakeCase($columnName),
                    );
                }

                // Check 2: Should end with _id
                if (!str_ends_with((string) $columnName, '_id')) {
                    $expectedName = $this->helper->isSnakeCase($columnName)
                        ? $columnName . '_id'
                        : $this->helper->toSnakeCase($columnName) . '_id';

                    $issues[] = $this->createForeignKeyNamingIssue(
                        $entityClass,
                        $columnName,
                        $assocName,
                        'missing_id_suffix',
                        $expectedName,
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * Analyze index naming conventions.
     */
    private function analyzeIndexNaming(ClassMetadata $classMetadata): array
    {
        $issues      = [];
        $entityClass = $classMetadata->getName();
        $tableName   = $classMetadata->getTableName();

        $tableAttribute = $this->getTableAttribute($entityClass);
        if (null === $tableAttribute) {
            return $issues;
        }

        // Check regular indexes
        $indexIssues = $this->validateIndexes($tableAttribute, $entityClass, $tableName);
        $issues      = array_merge($issues, $indexIssues);

        // Check unique constraints
        $constraintIssues = $this->validateUniqueConstraints($tableAttribute, $entityClass, $tableName);
        $issues           = array_merge($issues, $constraintIssues);

        return $issues;
    }

    /**
     * Get table attribute from entity class.
     */
    private function getTableAttribute(string $entityClass): ?object
    {
        try {
            assert(class_exists($entityClass));
            $reflectionClass = new ReflectionClass($entityClass);
            $tableAttributes = $reflectionClass->getAttributes(\Doctrine\ORM\Mapping\Table::class);

            if (empty($tableAttributes)) {
                return null;
            }

            return $tableAttributes[0]->newInstance();
        } catch (\ReflectionException) {
            return null;
        }
    }

    /**
     * Validate index naming conventions.
     */
    private function validateIndexes(object $tableAttribute, string $entityClass, string $tableName): array
    {
        $issues = [];

        if (!property_exists($tableAttribute, 'indexes') || !isset($tableAttribute->indexes)) {
            return $issues;
        }

        foreach ($tableAttribute->indexes as $index) {
            $indexName = $index->name;

            // Skip auto-generated numeric indexes or empty names
            if (is_numeric($indexName) || empty($indexName)) {
                continue;
            }

            // Check 1: Should start with idx_
            if (!str_starts_with($indexName, 'idx_')) {
                $columns       = $index->columns ?? [];
                $suggestedName = 'idx_' . implode('_', $columns);

                $issues[] = $this->createIndexNamingIssue(
                    $entityClass,
                    $tableName,
                    $indexName,
                    'missing_idx_prefix',
                    $suggestedName,
                    'info',
                );
            }

            // Check 2: Should be snake_case
            if (!$this->helper->isSnakeCase($indexName)) {
                $issues[] = $this->createIndexNamingIssue(
                    $entityClass,
                    $tableName,
                    $indexName,
                    'not_snake_case',
                    $this->helper->toSnakeCase($indexName),
                    'info',
                );
            }
        }

        return $issues;
    }

    /**
     * Validate unique constraint naming conventions.
     */
    private function validateUniqueConstraints(object $tableAttribute, string $entityClass, string $tableName): array
    {
        $issues = [];

        if (!property_exists($tableAttribute, 'uniqueConstraints') || !isset($tableAttribute->uniqueConstraints)) {
            return $issues;
        }

        foreach ($tableAttribute->uniqueConstraints as $constraint) {
            $constraintName = $constraint->name;

            // Skip auto-generated numeric constraints or empty names
            if (is_numeric($constraintName) || empty($constraintName)) {
                continue;
            }

            // Check: Should start with uniq_
            if (!str_starts_with($constraintName, 'uniq_')) {
                $columns       = $constraint->columns ?? [];
                $suggestedName = 'uniq_' . implode('_', $columns);

                $issues[] = $this->createIndexNamingIssue(
                    $entityClass,
                    $tableName,
                    $constraintName,
                    'missing_uniq_prefix',
                    $suggestedName,
                    'info',
                );
            }
        }

        return $issues;
    }

    /**
     * Check if name is a SQL reserved keyword.
     * Only flags exact matches of reserved keywords.
     * Compound names with underscores (e.g., app_user, user_profile) are acceptable.
     */
    private function isSQLReservedKeyword(string $name): bool
    {
        // Check the exact name (lowercase comparison)
        $lowerName = strtolower($name);

        // If it contains underscores, it's a compound name - not a simple reserved keyword
        if (str_contains($name, '_')) {
            return false;
        }

        return in_array($lowerName, self::SQL_RESERVED_KEYWORDS, true);
    }

    private function createTableNamingIssue(
        string $entityClass,
        string $tableName,
        string $violationType,
        string $suggestedName,
        string $severity = 'warning',
    ): CodeQualityIssue {
        $codeQualityIssue = new CodeQualityIssue([
            'entity' => $entityClass,
            'table_name' => $tableName,
            'violation_type' => $violationType,
            'suggested_name' => $suggestedName,
        ]);

        $codeQualityIssue->setSeverity($severity);
        $codeQualityIssue->setTitle($this->getTableViolationTitle($violationType));
        $codeQualityIssue->setMessage($this->getTableViolationMessage($violationType, $tableName, $entityClass));
        $codeQualityIssue->setSuggestion($this->buildTableNamingSuggestion($tableName, $suggestedName, $entityClass));

        return $codeQualityIssue;
    }

    private function createColumnNamingIssue(
        string $entityClass,
        string $columnName,
        string $fieldName,
        string $violationType,
        string $suggestedName,
        string $severity = 'warning',
    ): CodeQualityIssue {
        $codeQualityIssue = new CodeQualityIssue([
            'entity' => $entityClass,
            'column_name' => $columnName,
            'field_name' => $fieldName,
            'violation_type' => $violationType,
            'suggested_name' => $suggestedName,
        ]);

        $codeQualityIssue->setSeverity($severity);
        $codeQualityIssue->setTitle($this->getColumnViolationTitle($violationType));
        $codeQualityIssue->setMessage($this->getColumnViolationMessage($violationType, $columnName, $fieldName, $entityClass));
        $codeQualityIssue->setSuggestion($this->buildColumnNamingSuggestion($columnName, $suggestedName, $fieldName, $entityClass));

        return $codeQualityIssue;
    }

    private function createForeignKeyNamingIssue(
        string $entityClass,
        string $columnName,
        string $assocName,
        string $violationType,
        string $suggestedName,
    ): CodeQualityIssue {
        $codeQualityIssue = new CodeQualityIssue([
            'entity' => $entityClass,
            'fk_column' => $columnName,
            'association' => $assocName,
            'violation_type' => $violationType,
            'suggested_name' => $suggestedName,
        ]);

        $codeQualityIssue->setSeverity('critical');
        $codeQualityIssue->setTitle($this->getFKViolationTitle($violationType));
        $codeQualityIssue->setMessage($this->getFKViolationMessage($violationType, $columnName, $assocName, $entityClass));
        $codeQualityIssue->setSuggestion($this->buildFKNamingSuggestion($columnName, $suggestedName, $assocName, $entityClass));

        return $codeQualityIssue;
    }

    private function createIndexNamingIssue(
        string $entityClass,
        string $tableName,
        string $indexName,
        string $violationType,
        string $suggestedName,
        string $severity = 'info',
    ): CodeQualityIssue {
        $codeQualityIssue = new CodeQualityIssue([
            'entity' => $entityClass,
            'table_name' => $tableName,
            'index_name' => $indexName,
            'violation_type' => $violationType,
            'suggested_name' => $suggestedName,
        ]);

        $codeQualityIssue->setSeverity($severity);
        $codeQualityIssue->setTitle($this->getIndexViolationTitle($violationType));
        $codeQualityIssue->setMessage($this->getIndexViolationMessage($violationType, $indexName, $tableName));
        $codeQualityIssue->setSuggestion($this->buildIndexNamingSuggestion($indexName, $suggestedName));

        return $codeQualityIssue;
    }

    private function getTableViolationTitle(string $type): string
    {
        return match ($type) {
            'not_snake_case' => 'Table Name Not in snake_case',
            'plural' => 'Table Name Should Be Singular',
            'singular' => 'Table Name Should Be Plural',  // Legacy (deprecated)
            'reserved_keyword' => 'Table Name is SQL Reserved Keyword',
            'special_characters' => 'Table Name Contains Special Characters',
            default => 'Table Naming Convention Violation',
        };
    }

    private function getTableViolationMessage(string $type, string $tableName, string $entityClass): string
    {
        return match ($type) {
            'not_snake_case' => DescriptionHighlighter::highlight(
                "Table {table} for entity {class} is not in snake_case format.",
                ['table' => $tableName, 'class' => $entityClass],
            ),
            'plural' => DescriptionHighlighter::highlight(
                "Table {table} should be singular to match entity naming. ORM best practice is to use singular table names (e.g., 'user' not 'users').",
                ['table' => $tableName],
            ),
            'singular' => DescriptionHighlighter::highlight(
                "Table {table} should be plural according to Symfony conventions.",
                ['table' => $tableName],
            ),
            'reserved_keyword' => DescriptionHighlighter::highlight(
                "Table {table} is a SQL reserved keyword. While Doctrine quotes identifiers automatically, consider using a different name (e.g., {suggested}) for better portability and readability.",
                ['table' => $tableName, 'suggested' => $tableName . 's'],
            ),
            'special_characters' => DescriptionHighlighter::highlight(
                "Table {table} contains special characters (only letters, numbers, and underscores allowed).",
                ['table' => $tableName],
            ),
            default => DescriptionHighlighter::highlight(
                "Table {table} violates naming conventions.",
                ['table' => $tableName],
            ),
        };
    }

    private function getColumnViolationTitle(string $type): string
    {
        return match ($type) {
            'not_snake_case' => 'Column Name Not in snake_case',
            'reserved_keyword' => 'Column Name is SQL Reserved Keyword',
            'special_characters' => 'Column Name Contains Special Characters',
            default => 'Column Naming Convention Violation',
        };
    }

    private function getColumnViolationMessage(string $type, string $columnName, string $fieldName, string $entityClass): string
    {
        return match ($type) {
            'not_snake_case' => DescriptionHighlighter::highlight(
                "Column {column} (field {field}) in entity {class} is not in snake_case format.",
                ['column' => $columnName, 'field' => $fieldName, 'class' => $entityClass],
            ),
            'reserved_keyword' => DescriptionHighlighter::highlight(
                "Column {column} is a SQL reserved keyword. While Doctrine quotes identifiers automatically, consider renaming it for better portability.",
                ['column' => $columnName],
            ),
            'special_characters' => DescriptionHighlighter::highlight(
                "Column {column} contains special characters.",
                ['column' => $columnName],
            ),
            default => DescriptionHighlighter::highlight(
                "Column {column} violates naming conventions.",
                ['column' => $columnName],
            ),
        };
    }

    private function getFKViolationTitle(string $type): string
    {
        return match ($type) {
            'not_snake_case' => 'Foreign Key Not in snake_case',
            'missing_id_suffix' => 'Foreign Key Missing _id Suffix',
            default => 'Foreign Key Naming Convention Violation',
        };
    }

    private function getFKViolationMessage(string $type, string $columnName, string $assocName, string $entityClass): string
    {
        return match ($type) {
            'not_snake_case' => sprintf("Foreign key '%s' (association '%s') in entity '%s' is not in snake_case format.", $columnName, $assocName, $entityClass),
            'missing_id_suffix' => sprintf("Foreign key '%s' should end with '_id' suffix according to Doctrine conventions.", $columnName),
            default => sprintf("Foreign key '%s' violates naming conventions.", $columnName),
        };
    }

    private function getIndexViolationTitle(string $type): string
    {
        return match ($type) {
            'missing_idx_prefix' => 'Index Missing idx_ Prefix',
            'missing_uniq_prefix' => 'Unique Constraint Missing uniq_ Prefix',
            'not_snake_case' => 'Index Name Not in snake_case',
            default => 'Index Naming Convention Violation',
        };
    }

    private function getIndexViolationMessage(string $type, string $indexName, string $tableName): string
    {
        return match ($type) {
            'missing_idx_prefix' => sprintf("Index '%s' on table '%s' should start with 'idx_' prefix.", $indexName, $tableName),
            'missing_uniq_prefix' => sprintf("Unique constraint '%s' should start with 'uniq_' prefix.", $indexName),
            'not_snake_case' => sprintf("Index '%s' is not in snake_case format.", $indexName),
            default => sprintf("Index '%s' violates naming conventions.", $indexName),
        };
    }

    private function buildTableNamingSuggestion(string $current, string $suggested, string $entityClass): CodeSuggestion
    {
        $shortClass = $this->getShortClassName($entityClass);

        $rendered = $this->templateRenderer->render('naming_convention_table', [
            'current' => $current,
            'suggested' => $suggested,
            'entity_class' => $shortClass,
        ]);

        return new CodeSuggestion($rendered);
    }

    private function buildColumnNamingSuggestion(string $current, string $suggested, string $fieldName, string $entityClass): CodeSuggestion
    {
        $shortClass = $this->getShortClassName($entityClass);

        $rendered = $this->templateRenderer->render('naming_convention_column', [
            'current' => $current,
            'suggested' => $suggested,
            'field_name' => $fieldName,
            'entity_class' => $shortClass,
        ]);

        return new CodeSuggestion($rendered);
    }

    private function buildFKNamingSuggestion(string $current, string $suggested, string $assocName, string $entityClass): CodeSuggestion
    {
        $shortClass = $this->getShortClassName($entityClass);

        $rendered = $this->templateRenderer->render('naming_convention_fk', [
            'current' => $current,
            'suggested' => $suggested,
            'assoc_name' => $assocName,
            'entity_class' => $shortClass,
        ]);

        return new CodeSuggestion($rendered);
    }

    private function buildIndexNamingSuggestion(string $current, string $suggested): CodeSuggestion
    {
        $rendered = $this->templateRenderer->render('naming_convention_index', [
            'current' => $current,
            'suggested' => $suggested,
        ]);

        return new CodeSuggestion($rendered);
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }
}
