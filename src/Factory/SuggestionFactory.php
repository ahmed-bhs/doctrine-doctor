<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Factory;

use AhmedBhs\DoctrineDoctor\Suggestion\ModernSuggestion;
use AhmedBhs\DoctrineDoctor\Suggestion\StructuredSuggestion;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionRendererInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionContent;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionContentBlock;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * Factory for creating suggestion instances.
 * Centralizes suggestion creation logic and provides type-safe factory methods.
 * Benefits:
 * - Single point of creation (easier to test and modify)
 * - Type-safe methods (no magic arrays)
 * - Encapsulates complexity of severity calculation
 * - Easy to extend with new suggestion types
 *
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
final class SuggestionFactory
{
    public function __construct(
        /**
         * @readonly
         */
        private SuggestionRendererInterface $suggestionRenderer,
    ) {
        Assert::isInstanceOf($suggestionRenderer, SuggestionRendererInterface::class);
    }

    /**
     * Create a "Flush in Loop" performance suggestion.
     */
    public function createFlushInLoop(
        int $flushCount,
        float $operationsBetweenFlush,
    ): SuggestionInterface {
        Assert::greaterThan($flushCount, 0, 'Flush count must be positive, got %s');
        Assert::greaterThan($operationsBetweenFlush, 0, 'Operations between flush must be positive, got %s');

        return new ModernSuggestion(
            templateName: 'Performance/flush_in_loop',
            context: [
                'flush_count'              => $flushCount,
                'operations_between_flush' => $operationsBetweenFlush,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $this->calculateFlushInLoopSeverity($flushCount),
                title: sprintf('Performance Anti-Pattern: %d flush() calls in loop', $flushCount),
                tags: ['performance', 'doctrine', 'flush', 'batch'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create an "Eager Loading" performance suggestion.
     */
    public function createEagerLoading(
        string $entity,
        string $relation,
        int $queryCount,
    ): SuggestionInterface {
        Assert::stringNotEmpty($entity, 'Entity name cannot be empty');
        Assert::stringNotEmpty($relation, 'Relation name cannot be empty');
        Assert::greaterThan($queryCount, 0, 'Query count must be positive, got %s');

        return new ModernSuggestion(
            templateName: 'Performance/eager_loading',
            context: [
                'entity'      => $entity,
                'relation'    => $relation,
                'query_count' => $queryCount,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $this->calculateEagerLoadingSeverity($queryCount),
                title: sprintf('N+1 Query Problem: %d queries for %s.%s', $queryCount, $entity, $relation),
                tags: ['performance', 'doctrine', 'eager-loading', 'n+1'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create a "Query Optimization" suggestion.
     */
    public function createQueryOptimization(
        string $code,
        string $optimization,
        float $executionTime,
        int $threshold,
    ): SuggestionInterface {
        return new ModernSuggestion(
            templateName: 'Performance/query_optimization',
            context: [
                'code'           => $code,
                'optimization'   => $optimization,
                'execution_time' => $executionTime,
                'threshold'      => $threshold,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $this->calculateSlowQuerySeverity($executionTime),
                title: sprintf('Slow Query: %.2fms', $executionTime),
                tags: ['performance', 'query', 'optimization'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create a "Hydration Optimization" suggestion.
     */
    public function createHydrationOptimization(
        string $code,
        string $optimization,
        int $rowCount,
        int $threshold,
    ): SuggestionInterface {
        return new ModernSuggestion(
            templateName: 'Performance/query_optimization',
            context: [
                'code'           => $code,
                'optimization'   => $optimization,
                'execution_time' => 0.0,
                'threshold'      => $threshold,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $rowCount > $threshold * 10 ? Severity::critical() : Severity::warning(),
                title: sprintf('Hydration Optimization: %d rows', $rowCount),
                tags: ['performance', 'hydration', 'optimization'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create an "Index" suggestion.
     */
    public function createIndex(
        string $table,
        array $columns,
        ?string $migrationCode = null,
    ): SuggestionInterface {
        $migrationCode ??= $this->generateMigrationCode($table, $columns);

        return new ModernSuggestion(
            templateName: 'Performance/index',
            context: [
                'table'          => $table,
                'columns'        => $columns,
                'migration_code' => $migrationCode,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::info(),
                title: sprintf('Missing Index: %s(%s)', $table, implode(', ', $columns)),
                tags: ['performance', 'database', 'index'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create a "Batch Operation" suggestion.
     */
    public function createBatchOperation(
        string $table,
        int $operationCount,
    ): SuggestionInterface {
        return new ModernSuggestion(
            templateName: 'Performance/batch_operation',
            context: [
                'table'           => $table,
                'operation_count' => $operationCount,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: sprintf('Memory Leak Risk: %d operations without clear()', $operationCount),
                tags: ['performance', 'memory', 'batch'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create a "DQL Injection" security suggestion.
     */
    public function createDQLInjection(
        string $query,
        array $vulnerableParameters,
        string $riskLevel = 'warning',
    ): SuggestionInterface {
        return new ModernSuggestion(
            templateName: 'Security/dql_injection',
            context: [
                'query'                 => $query,
                'vulnerable_parameters' => $vulnerableParameters,
                'risk_level'            => $riskLevel,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::security(),
                severity: Severity::critical(),
                title: 'DQL Injection Vulnerability Detected',
                tags: ['security', 'injection', 'dql'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create a "Configuration" suggestion.
     */
    public function createConfiguration(
        string $setting,
        string $currentValue,
        string $recommendedValue,
        ?string $description = null,
        ?string $fixCommand = null,
    ): SuggestionInterface {
        return new ModernSuggestion(
            templateName: 'Configuration/configuration',
            context: [
                'setting'           => $setting,
                'current_value'     => $currentValue,
                'recommended_value' => $recommendedValue,
                'description'       => $description,
                'fix_command'       => $fixCommand,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::configuration(),
                severity: Severity::info(),
                title: sprintf('Configuration Issue: %s', $setting),
                tags: ['configuration', 'settings'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create a "GetReference" suggestion.
     */
    public function createGetReference(
        string $entity,
        int $occurrences,
    ): SuggestionInterface {
        return new ModernSuggestion(
            templateName: 'Performance/get_reference',
            context: [
                'entity'      => $entity,
                'occurrences' => $occurrences,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::bestPractice(),
                severity: Severity::info(),
                title: sprintf('Use getReference() for %s (%d occurrences)', $entity, $occurrences),
                tags: ['best-practice', 'performance', 'doctrine'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create a "Pagination" suggestion.
     */
    public function createPagination(
        string $method,
        int $resultCount,
    ): SuggestionInterface {
        return new ModernSuggestion(
            templateName: 'Performance/pagination',
            context: [
                'method'       => $method,
                'result_count' => $resultCount,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: sprintf('Missing Pagination: %s returned %d results', $method, $resultCount),
                tags: ['performance', 'pagination', 'memory'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create a "Batch Fetch" suggestion for proxy N+1 queries.
     * Recommended for ManyToOne/OneToOne relations accessed in loops.
     */
    public function createBatchFetch(
        string $entity,
        string $relation,
        int $queryCount,
    ): SuggestionInterface {
        Assert::stringNotEmpty($entity, 'Entity name cannot be empty');
        Assert::stringNotEmpty($relation, 'Relation name cannot be empty');
        Assert::greaterThan($queryCount, 0, 'Query count must be positive, got %s');

        return new ModernSuggestion(
            templateName: 'Performance/batch_fetch',
            context: [
                'entity'      => $entity,
                'relation'    => $relation,
                'query_count' => $queryCount,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $this->calculateEagerLoadingSeverity($queryCount),
                title: sprintf('Proxy N+1 Query: %d queries for %s.%s', $queryCount, $entity, $relation),
                tags: ['performance', 'doctrine', 'batch-fetch', 'n+1', 'proxy'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create an "Extra Lazy" suggestion for collection N+1 queries.
     * Recommended for OneToMany/ManyToMany collections with partial access.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function createExtraLazy(
        string $entity,
        string $relation,
        int $queryCount,
        bool $hasLimit = false,
    ): SuggestionInterface {
        Assert::stringNotEmpty($entity, 'Entity name cannot be empty');
        Assert::stringNotEmpty($relation, 'Relation name cannot be empty');
        Assert::greaterThan($queryCount, 0, 'Query count must be positive, got %s');

        return new ModernSuggestion(
            templateName: 'Performance/extra_lazy',
            context: [
                'entity'      => $entity,
                'relation'    => $relation,
                'query_count' => $queryCount,
                'has_limit'   => $hasLimit,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $this->calculateEagerLoadingSeverity($queryCount),
                title: sprintf('Collection N+1 Query: %d queries for %s.%s', $queryCount, $entity, $relation),
                tags: ['performance', 'doctrine', 'extra-lazy', 'n+1', 'collection'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create a "Denormalization" suggestion for counter fields.
     * Recommended when count() is called frequently on collections.
     */
    public function createDenormalization(
        string $entity,
        string $relation,
        int $queryCount,
        ?string $counterField = null,
    ): SuggestionInterface {
        Assert::stringNotEmpty($entity, 'Entity name cannot be empty');
        Assert::stringNotEmpty($relation, 'Relation name cannot be empty');
        Assert::greaterThan($queryCount, 0, 'Query count must be positive, got %s');

        // Generate counter field name if not provided
        $counterField = $counterField ?? $relation . 'Count';

        return new ModernSuggestion(
            templateName: 'Performance/denormalization',
            context: [
                'entity'        => $entity,
                'relation'      => $relation,
                'query_count'   => $queryCount,
                'counter_field' => $counterField,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $this->calculateEagerLoadingSeverity($queryCount),
                title: sprintf('Denormalization Opportunity: %d count() queries for %s.%s', $queryCount, $entity, $relation),
                tags: ['performance', 'doctrine', 'denormalization', 'counter', 'aggregation'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create a "GROUP BY Aggregation" suggestion.
     * Recommended when loading relations just for aggregation (count, sum, etc.).
     */
    public function createGroupByAggregation(
        string $entity,
        string $relation,
        int $queryCount,
    ): SuggestionInterface {
        Assert::stringNotEmpty($entity, 'Entity name cannot be empty');
        Assert::stringNotEmpty($relation, 'Relation name cannot be empty');
        Assert::greaterThan($queryCount, 0, 'Query count must be positive, got %s');

        return new ModernSuggestion(
            templateName: 'Performance/group_by_aggregation',
            context: [
                'entity'      => $entity,
                'relation'    => $relation,
                'query_count' => $queryCount,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $this->calculateEagerLoadingSeverity($queryCount),
                title: sprintf('GROUP BY Opportunity: %d queries for %s.%s aggregation', $queryCount, $entity, $relation),
                tags: ['performance', 'doctrine', 'group-by', 'aggregation', 'n+1'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create an "Unused Eager Load" suggestion.
     * Recommended when JOINs are fetching data that is never accessed.
     *
     * @param array<string> $unusedTables
     * @param array<string> $unusedAliases
     */
    public function createUnusedEagerLoad(
        array $unusedTables,
        array $unusedAliases,
    ): SuggestionInterface {
        Assert::allStringNotEmpty($unusedTables, 'Unused table names cannot be empty');
        Assert::allStringNotEmpty($unusedAliases, 'Unused aliases cannot be empty');

        return new ModernSuggestion(
            templateName: 'Performance/unused_eager_load',
            context: [
                'unused_tables'  => $unusedTables,
                'unused_aliases' => $unusedAliases,
                'count'          => \count($unusedTables),
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $this->calculateUnusedEagerLoadSeverity(\count($unusedTables)),
                title: sprintf('Remove %d Unused Eager Load(s)', \count($unusedTables)),
                tags: ['performance', 'doctrine', 'eager-loading', 'memory', 'waste'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create an "Over-Eager Loading" suggestion.
     * Recommended when too many JOINs cause data duplication.
     */
    public function createOverEagerLoading(
        int $joinCount,
    ): SuggestionInterface {
        Assert::greaterThan($joinCount, 2, 'Join count must be greater than 2 for over-eager loading, got %s');

        return new ModernSuggestion(
            templateName: 'Performance/over_eager_loading',
            context: [
                'join_count' => $joinCount,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $this->calculateOverEagerSeverity($joinCount),
                title: sprintf('Over-Eager Loading: %d JOINs Cause Data Duplication', $joinCount),
                tags: ['performance', 'doctrine', 'eager-loading', 'data-duplication', 'memory'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create a "Nested Eager Loading" suggestion.
     * Recommended when nested relationships cause multi-level N+1 queries.
     *
     * @param array<string> $entities - Chain of entities (e.g., ['Article', 'User', 'Country'])
     */
    public function createNestedEagerLoading(
        array $entities,
        int $depth,
        int $queryCount,
    ): SuggestionInterface {
        Assert::allStringNotEmpty($entities, 'Entity names cannot be empty');
        Assert::greaterThanEq($depth, 2, 'Depth must be at least 2 for nested N+1, got %s');
        Assert::greaterThan($queryCount, 0, 'Query count must be positive, got %s');

        return new ModernSuggestion(
            templateName: 'Performance/nested_eager_loading',
            context: [
                'entities'    => $entities,
                'depth'       => $depth,
                'query_count' => $queryCount,
                'chain'       => implode(' → ', $entities),
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: $this->calculateNestedSeverity($depth, $queryCount),
                title: sprintf('Nested N+1: %d Queries Across %d-Level Chain', $queryCount, $depth),
                tags: ['performance', 'doctrine', 'n+1', 'nested', 'eager-loading'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create a "Join Fetch" suggestion.
     * @deprecated Use createEagerLoading() instead. This method is an alias for createEagerLoading().
     */
    public function createJoinFetch(
        string $entity,
        string $relation,
        int $queryCount,
    ): SuggestionInterface {
        trigger_error(
            'createJoinFetch() is deprecated. Use createEagerLoading() instead.',
            E_USER_DEPRECATED,
        );

        return $this->createEagerLoading($entity, $relation, $queryCount);
    }

    /**
     * Create a generic code suggestion.
     */
    public function createCodeSuggestion(
        string $description,
        string $code,
        ?string $filePath = null,
    ): SuggestionInterface {
        return new ModernSuggestion(
            templateName: 'Integrity/code_suggestion',
            context: [
                'description' => $description,
                'code'        => $code,
                'file_path'   => $filePath,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::info(),
                title: 'Code Quality Suggestion',
                tags: ['code-quality'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create a "Collection Initialization" suggestion.
     */
    public function createCollectionInitialization(
        string $entityClass,
        string $fieldName,
        bool $hasConstructor,
        ?string $backtrace = null,
    ): SuggestionInterface {
        return new ModernSuggestion(
            templateName: 'Integrity/collection_initialization',
            context: [
                'entity_class'    => $entityClass,
                'field_name'      => $fieldName,
                'has_constructor' => $hasConstructor,
                'backtrace'       => $backtrace,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::critical(),
                title: sprintf('Uninitialized Collection: %s::$%s', $entityClass, $fieldName),
                tags: ['code-quality', 'doctrine', 'collection'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create a "Sensitive Data Exposure" security suggestion.
     */
    public function createSensitiveDataExposure(
        string $entityClass,
        string $methodName,
        array $exposedFields,
        string $exposureType = 'serialization',
    ): SuggestionInterface {
        return new ModernSuggestion(
            templateName: 'Security/sensitive_data_exposure',
            context: [
                'entity_class'   => $entityClass,
                'method_name'    => $methodName,
                'exposed_fields' => $exposedFields,
                'exposure_type'  => $exposureType,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::security(),
                severity: Severity::critical(),
                title: sprintf('Sensitive Data Exposure in %s::%s()', $entityClass, $methodName),
                tags: ['security', 'data-exposure', 'serialization'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create an "Insecure Random" security suggestion.
     */
    public function createInsecureRandom(
        string $entityClass,
        string $methodName,
        string $insecureFunction,
    ): SuggestionInterface {
        return new ModernSuggestion(
            templateName: 'Security/insecure_random',
            context: [
                'entity_class'      => $entityClass,
                'method_name'       => $methodName,
                'insecure_function' => $insecureFunction,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::security(),
                severity: Severity::critical(),
                title: sprintf('Insecure Random: %s() in %s::%s()', $insecureFunction, $entityClass, $methodName),
                tags: ['security', 'random', 'crypto'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create an "SQL Injection" security suggestion.
     */
    public function createSQLInjection(
        string $className,
        string $methodName,
        string $vulnerabilityType = 'concatenation',
    ): SuggestionInterface {
        return new ModernSuggestion(
            templateName: 'Security/sql_injection',
            context: [
                'class_name'         => $className,
                'method_name'        => $methodName,
                'vulnerability_type' => $vulnerabilityType,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::security(),
                severity: Severity::critical(),
                title: sprintf('SQL Injection Risk in %s::%s()', $className, $methodName),
                tags: ['security', 'sql-injection', 'database'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    /**
     * Create a "Cascade Configuration" best practice suggestion for composition.
     */
    public function createCascadeConfigurationForComposition(
        string $entityClass,
        string $fieldName,
        string $issueType,
        string $targetEntity,
    ): SuggestionInterface {
        return $this->createCascadeConfigurationInternal($entityClass, $fieldName, $issueType, $targetEntity, true);
    }

    /**
     * Create a "Cascade Configuration" best practice suggestion for aggregation.
     */
    public function createCascadeConfigurationForAggregation(
        string $entityClass,
        string $fieldName,
        string $issueType,
        string $targetEntity,
    ): SuggestionInterface {
        return $this->createCascadeConfigurationInternal($entityClass, $fieldName, $issueType, $targetEntity, false);
    }

    /**
     * Create a structured suggestion with organized content blocks.
     * @deprecated Use createFromTemplate() instead. Create a template file in src/Template/Suggestions/
     * @param string                   $title   Suggestion title
     * @param SuggestionContentBlock[] $blocks  Content blocks
     * @param string|null $summary summary
     */
    public function createStructured(
        string $title,
        array $blocks,
        ?string $summary = null,
    ): SuggestionInterface {
        trigger_error(
            'createStructured() is deprecated. Create a template in src/Template/Suggestions/ and use createFromTemplate() instead.',
            E_USER_DEPRECATED,
        );

        return new StructuredSuggestion(
            title: $title,
            suggestionContent: new SuggestionContent($blocks),
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: $title,
            ),
            summary: $summary,
        );
    }

    /**
     * Create a structured suggestion with a comparison (bad vs good code).
     * @deprecated Use createFromTemplate() instead. Create a template file in src/Template/Suggestions/
     */
    public function createComparison(
        string $title,
        string $badCode,
        string $goodCode,
        string $description = '',
        string $language = 'php',
        array $additionalBlocks = [],
    ): SuggestionInterface {
        trigger_error(
            'createComparison() is deprecated. Create a template in src/Template/Suggestions/ and use createFromTemplate() instead.',
            E_USER_DEPRECATED,
        );
        $blocks = [];

        if ('' !== $description && '0' !== $description) {
            $blocks[] = SuggestionContentBlock::text($description);
        }

        $blocks[] = SuggestionContentBlock::comparison($badCode, $goodCode, $language);

        $blocks = array_merge($blocks, $additionalBlocks);

        return $this->createStructured($title, $blocks, $description);
    }

    /**
     * Create a structured suggestion with multiple options.
     * @deprecated Use createFromTemplate() instead. Create a template file in src/Template/Suggestions/
     */
    public function createWithOptions(
        string $title,
        string $description,
        array $options,
        array $additionalBlocks = [],
    ): SuggestionInterface {
        trigger_error(
            'createWithOptions() is deprecated. Create a template in src/Template/Suggestions/ and use createFromTemplate() instead.',
            E_USER_DEPRECATED,
        );
        $blocks = [
            SuggestionContentBlock::text($description),
            SuggestionContentBlock::heading('Available Solutions', 4),
        ];

        Assert::isIterable($options, '$options must be iterable');

        foreach ($options as $i => $option) {
            $optionNumber = (int) $i + 1;
            $blocks[]     = SuggestionContentBlock::heading(sprintf('Option %d: %s', $optionNumber, $option['title']), 5);

            if (isset($option['description'])) {
                $blocks[] = SuggestionContentBlock::text($option['description']);
            }

            if (isset($option['code'])) {
                $blocks[] = SuggestionContentBlock::code(
                    $option['code'],
                    $option['language'] ?? 'php',
                    'Option ' . $optionNumber,
                );
            }

            if (isset($option['pros'])) {
                $blocks[] = SuggestionContentBlock::info('Pros: ' . implode(', ', $option['pros']));
            }

            if (isset($option['cons'])) {
                $blocks[] = SuggestionContentBlock::warning('Cons: ' . implode(', ', $option['cons']));
            }
        }

        $blocks = array_merge($blocks, $additionalBlocks);

        return $this->createStructured($title, $blocks, $description);
    }

    /**
     * Create a suggestion with documentation links.
     * @deprecated Use createFromTemplate() instead. Create a template file in src/Template/Suggestions/
     */
    public function createWithDocs(
        string $title,
        string $description,
        string $code,
        array $docLinks,
        string $language = 'php',
    ): SuggestionInterface {
        trigger_error(
            'createWithDocs() is deprecated. Create a template in src/Template/Suggestions/ and use createFromTemplate() instead.',
            E_USER_DEPRECATED,
        );
        $blocks = [
            SuggestionContentBlock::text($description),
            SuggestionContentBlock::heading('Recommended Solution', 4),
            SuggestionContentBlock::code($code, $language, 'Good'),
        ];

        if ([] !== $docLinks) {
            $blocks[] = SuggestionContentBlock::heading('Documentation', 4);

            Assert::isIterable($docLinks, '$docLinks must be iterable');

            foreach ($docLinks as $docLink) {
                $blocks[] = SuggestionContentBlock::link($docLink['url'], $docLink['text']);
            }
        }

        return $this->createStructured($title, $blocks, $description);
    }

    /**
     * Create a ModernSuggestion from template name.
     * This is the RECOMMENDED way to create suggestions.
     * All suggestion templates must be in src/Template/Suggestions/{templateName}.php
     * @param array<mixed> $context
     * @throws \RuntimeException if template file does not exist
     */
    public function createFromTemplate(
        string $templateName,
        array $context,
        SuggestionMetadata $suggestionMetadata,
    ): SuggestionInterface {
        // Validate that template exists
        // First try direct path (for backward compatibility or when full path is provided)
        $templatePath = __DIR__ . '/../Template/Suggestions/' . $templateName . '.php';

        // If not found, search in category subdirectories
        if (!file_exists($templatePath)) {
            $categories = ['Performance', 'Security', 'Integrity', 'Configuration'];
            $found = false;

            foreach ($categories as $category) {
                $categoryPath = __DIR__ . '/../Template/Suggestions/' . $category . '/' . $templateName . '.php';
                if (file_exists($categoryPath)) {
                    $templatePath = $categoryPath;
                    $templateName = $category . '/' . $templateName;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                throw new RuntimeException(sprintf('Template file "%s.php" does not exist. Create it in src/Template/Suggestions/ or in a category subdirectory (Performance, Security, CodeQuality, Configuration)', $templateName));
            }
        }

        return new ModernSuggestion(
            $templateName,
            $context,
            $suggestionMetadata,
            $this->suggestionRenderer,
        );
    }

    /**
     * Internal method to create cascaconfiguration suggestion.
     */
    private function createCascadeConfigurationInternal(
        string $entityClass,
        string $fieldName,
        string $issueType,
        string $targetEntity,
        bool $isComposition,
    ): SuggestionInterface {
        $severity = ('dangerous_remove' === $issueType) ? Severity::critical() : Severity::warning();

        return new ModernSuggestion(
            templateName: 'Integrity/cascade_configuration',
            context: [
                'entity_class'   => $entityClass,
                'field_name'     => $fieldName,
                'issue_type'     => $issueType,
                'target_entity'  => $targetEntity,
                'is_composition' => $isComposition,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::bestPractice(),
                severity: $severity,
                title: sprintf('Cascade Issue in %s::$%s', $entityClass, $fieldName),
                tags: ['best-practice', 'doctrine', 'cascade'],
            ),
            suggestionRenderer: $this->suggestionRenderer,
        );
    }

    // ========== Private Helper Methods ==========

    private function calculateFlushInLoopSeverity(int $flushCount): Severity
    {
        if ($flushCount > 50) {
            return Severity::critical();
        }

        if ($flushCount > 20) {
            return Severity::warning();
        }

        return Severity::info();
    }

    private function calculateEagerLoadingSeverity(int $queryCount): Severity
    {
        if ($queryCount > 100) {
            return Severity::critical();
        }

        if ($queryCount > 20) {
            return Severity::warning();
        }

        return Severity::info();
    }

    private function calculateSlowQuerySeverity(float $executionTime): Severity
    {
        if ($executionTime > 1000) { // > 1 second
            return Severity::critical();
        }

        if ($executionTime > 500) { // > 500ms
            return Severity::warning();
        }

        return Severity::info();
    }

    private function calculateUnusedEagerLoadSeverity(int $unusedJoinCount): Severity
    {
        // More unused JOINs = more wasted resources
        if ($unusedJoinCount >= 3) {
            return Severity::critical();
        }

        if ($unusedJoinCount >= 2) {
            return Severity::warning();
        }

        return Severity::info();
    }

    private function calculateOverEagerSeverity(int $joinCount): Severity
    {
        // Many JOINs can cause exponential data duplication
        if ($joinCount >= 5) {
            return Severity::critical();
        }

        if ($joinCount >= 4) {
            return Severity::warning();
        }

        return Severity::info();
    }

    private function calculateNestedSeverity(int $depth, int $queryCount): Severity
    {
        // Nested N+1 is more severe because it multiplies queries
        $totalImpact = $depth * $queryCount;

        if ($totalImpact >= 50 || $depth >= 4) {
            return Severity::critical();
        }

        if ($totalImpact >= 20 || $depth >= 2) {
            return Severity::warning();
        }

        return Severity::info();
    }

    private function generateMigrationCode(string $table, array $columns): string
    {
        if ('' === $table || '0' === $table || [] === $columns) {
            return '// Unable to generate migration code: table or columns missing.';
        }

        $indexName = 'IDX_' . strtoupper($table) . '_' . implode('_', array_map(function ($column) {
            return strtoupper($column);
        }, $columns));
        $cols      = implode(', ', $columns);

        return sprintf('CREATE INDEX %s ON %s (%s);', $indexName, $table, $cols);
    }
}
