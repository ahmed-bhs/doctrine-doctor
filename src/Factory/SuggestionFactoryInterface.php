<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Factory;

use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;

interface SuggestionFactoryInterface
{
    public function createFlushInLoop(
        int $flushCount,
        float $operationsBetweenFlush,
    ): SuggestionInterface;

    public function createEagerLoading(
        string $entity,
        string $relation,
        int $queryCount,
        ?string $triggerLocation = null,
    ): SuggestionInterface;

    public function createQueryOptimization(
        string $code,
        string $optimization,
        float $executionTime,
        int $threshold,
    ): SuggestionInterface;

    public function createHydrationOptimization(
        string $code,
        string $optimization,
        int $rowCount,
        int $threshold,
    ): SuggestionInterface;

    public function createIndex(
        string $table,
        array $columns,
        ?string $migrationCode = null,
    ): SuggestionInterface;

    public function createBatchOperation(
        string $table,
        int $operationCount,
    ): SuggestionInterface;

    public function createDQLInjection(
        string $query,
        array $vulnerableParameters,
        string $riskLevel = 'warning',
    ): SuggestionInterface;

    public function createConfiguration(
        string $setting,
        string $currentValue,
        string $recommendedValue,
        ?string $description = null,
        ?string $fixCommand = null,
    ): SuggestionInterface;

    public function createGetReference(
        string $entity,
        int $occurrences,
    ): SuggestionInterface;

    public function createPagination(
        string $method,
        int $resultCount,
    ): SuggestionInterface;

    public function createBatchFetch(
        string $entity,
        string $relation,
        int $queryCount,
        ?string $triggerLocation = null,
    ): SuggestionInterface;

    public function createExtraLazy(
        string $entity,
        string $relation,
        int $queryCount,
        bool $hasLimit = false,
        ?string $triggerLocation = null,
    ): SuggestionInterface;

    public function createCollectionEagerLoading(
        string $parentEntity,
        string $collectionField,
        string $childEntity,
        int $queryCount,
        ?string $triggerLocation = null,
    ): SuggestionInterface;

    public function createDenormalization(
        string $entity,
        string $relation,
        int $queryCount,
        ?string $counterField = null,
    ): SuggestionInterface;

    public function createGroupByAggregation(
        string $entity,
        string $relation,
        int $queryCount,
    ): SuggestionInterface;

    /**
     * @param array<string> $unusedTables
     * @param array<string> $unusedAliases
     */
    public function createUnusedEagerLoad(
        array $unusedTables,
        array $unusedAliases,
    ): SuggestionInterface;

    public function createOverEagerLoading(
        int $joinCount,
    ): SuggestionInterface;

    /**
     * @param array<string> $entities
     */
    public function createNestedEagerLoading(
        array $entities,
        int $depth,
        int $queryCount,
    ): SuggestionInterface;

    public function createCodeSuggestion(
        string $description,
        string $code,
        ?string $filePath = null,
    ): SuggestionInterface;

    public function createCollectionInitialization(
        string $entityClass,
        string $fieldName,
        bool $hasConstructor,
        ?string $backtrace = null,
    ): SuggestionInterface;

    public function createSensitiveDataExposure(
        string $entityClass,
        string $methodName,
        array $exposedFields,
        string $exposureType = 'serialization',
    ): SuggestionInterface;

    public function createInsecureRandom(
        string $entityClass,
        string $methodName,
        string $insecureFunction,
    ): SuggestionInterface;

    public function createSQLInjection(
        string $className,
        string $methodName,
        string $vulnerabilityType = 'concatenation',
    ): SuggestionInterface;

    public function createCascadeConfigurationForComposition(
        string $entityClass,
        string $fieldName,
        string $issueType,
        string $targetEntity,
    ): SuggestionInterface;

    public function createCascadeConfigurationForAggregation(
        string $entityClass,
        string $fieldName,
        string $issueType,
        string $targetEntity,
    ): SuggestionInterface;

    /**
     * @param array<mixed> $context
     */
    public function createFromTemplate(
        string $templateName,
        array $context,
        SuggestionMetadata $suggestionMetadata,
    ): SuggestionInterface;

    public function createArrayCacheProduction(
        string $cacheType,
        string $currentConfig,
    ): SuggestionInterface;

    public function createProxyAutoGenerate(): SuggestionInterface;
}
