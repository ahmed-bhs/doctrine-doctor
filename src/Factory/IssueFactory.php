<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Factory;

use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Issue\AbstractIssue;
use AhmedBhs\DoctrineDoctor\Issue\BulkOperationIssue;
use AhmedBhs\DoctrineDoctor\Issue\CollectionEmptyAccessIssue;
use AhmedBhs\DoctrineDoctor\Issue\CollectionUninitializedIssue;
use AhmedBhs\DoctrineDoctor\Issue\ConfigurationIssue;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Issue\DQLInjectionIssue;
use AhmedBhs\DoctrineDoctor\Issue\DQLValidationIssue;
use AhmedBhs\DoctrineDoctor\Issue\EagerLoadingIssue;
use AhmedBhs\DoctrineDoctor\Issue\EntityManagerClearIssue;
use AhmedBhs\DoctrineDoctor\Issue\EntityStateIssue;
use AhmedBhs\DoctrineDoctor\Issue\FinalEntityIssue;
use AhmedBhs\DoctrineDoctor\Issue\FindAllIssue;
use AhmedBhs\DoctrineDoctor\Issue\FlushInLoopIssue;
use AhmedBhs\DoctrineDoctor\Issue\GetReferenceIssue;
use AhmedBhs\DoctrineDoctor\Issue\HydrationIssue;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Issue\LazyLoadingIssue;
use AhmedBhs\DoctrineDoctor\Issue\MissingIndexIssue;
use AhmedBhs\DoctrineDoctor\Issue\NPlusOneIssue;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\Issue\PropertyTypeMismatchIssue;
use AhmedBhs\DoctrineDoctor\Issue\RepositoryFieldValidationIssue;
use AhmedBhs\DoctrineDoctor\Issue\SlowQueryIssue;
use AhmedBhs\DoctrineDoctor\Issue\TransactionIssue;
use InvalidArgumentException;

/**
 * Concrete Factory for creating Issue instances.
 * Implements Factory Pattern for flexible object creation.
 * SOLID Principles respected:
 */
class IssueFactory implements IssueFactoryInterface
{
    /**
     * Map of issue types to their concrete classes.
     * @var array<string, class-string<AbstractIssue>>
     */
    private const array TYPE_MAP = [
        'n_plus_one'                         => NPlusOneIssue::class,
        'N+1 Query'                          => NPlusOneIssue::class,
        'missing_index'                      => MissingIndexIssue::class,
        'Missing Index'                      => MissingIndexIssue::class,
        'slow_query'                         => SlowQueryIssue::class,
        'Slow Query'                         => SlowQueryIssue::class,
        'hydration'                          => HydrationIssue::class,
        'Excessive Hydration'                => HydrationIssue::class,
        'eager_loading'                      => EagerLoadingIssue::class,
        'Excessive Eager Loading'            => EagerLoadingIssue::class,
        'find_all'                           => FindAllIssue::class,
        'findAll() Usage'                    => FindAllIssue::class,
        'entity_manager_clear'               => EntityManagerClearIssue::class,
        'Memory Leak Risk'                   => EntityManagerClearIssue::class,
        'get_reference'                      => GetReferenceIssue::class,
        'Inefficient Entity Loading'         => GetReferenceIssue::class,
        'flush_in_loop'                      => FlushInLoopIssue::class,
        'Performance Anti-Pattern'           => FlushInLoopIssue::class,
        'lazy_loading'                       => LazyLoadingIssue::class,
        'Lazy Loading in Loop'               => LazyLoadingIssue::class,
        'dql_injection'                      => DQLInjectionIssue::class,
        'Security Vulnerability'             => DQLInjectionIssue::class,
        'bulk_operation'                     => BulkOperationIssue::class,
        'Inefficient Bulk Operations'        => BulkOperationIssue::class,
        'configuration'                      => DatabaseConfigIssue::class,
        'Database Configuration Issue'       => DatabaseConfigIssue::class,
        'repository_invalid_field'           => RepositoryFieldValidationIssue::class,
        'Invalid Field in Repository Method' => RepositoryFieldValidationIssue::class,
        'final_entity'                       => FinalEntityIssue::class,
        'Final Entity Class'                 => FinalEntityIssue::class,
        'property_type_mismatch'             => PropertyTypeMismatchIssue::class,
        'Property Type Mismatch'             => PropertyTypeMismatchIssue::class,
        'dql_validation'                     => DQLValidationIssue::class,
        'DQL Validation Error'               => DQLValidationIssue::class,
        'collection_empty_access'            => CollectionEmptyAccessIssue::class,
        'Unsafe Collection Access'           => CollectionEmptyAccessIssue::class,
        'collection_uninitialized'           => CollectionUninitializedIssue::class,
        'Uninitialized Collection'           => CollectionUninitializedIssue::class,
        // Transaction issues
        'transaction_nested'         => TransactionIssue::class,
        'transaction_multiple_flush' => TransactionIssue::class,
        'transaction_unclosed'       => TransactionIssue::class,
        'transaction_too_long'       => TransactionIssue::class,
        'Transaction Boundary Issue' => TransactionIssue::class,
        // Entity state issues
        'entity_detached_modification'     => EntityStateIssue::class,
        'entity_new_in_association'        => EntityStateIssue::class,
        'entity_required_field_null'       => EntityStateIssue::class,
        'entity_required_association_null' => EntityStateIssue::class,
        'entity_removed_access'            => EntityStateIssue::class,
        'entity_removed_in_association'    => EntityStateIssue::class,
        'entity_detached_in_association'   => EntityStateIssue::class,
        'Entity State Issue'               => EntityStateIssue::class,
        // Code quality issues
        'float_for_money'                => IntegrityIssue::class,
        'Float for Money'                => IntegrityIssue::class,
        'type_hint_mismatch'             => IntegrityIssue::class,
        'Type Hint Mismatch'             => IntegrityIssue::class,
        'decimal_missing_precision'      => ConfigurationIssue::class,
        'decimal_insufficient_precision' => ConfigurationIssue::class,
        'decimal_excessive_precision'    => ConfigurationIssue::class,
        'decimal_unusual_scale'          => ConfigurationIssue::class,
        'cascade_remove_set_null'        => IntegrityIssue::class,
        'ondelete_cascade_no_orm'        => IntegrityIssue::class,
        'orphan_removal_no_persist'      => IntegrityIssue::class,
        'orphan_removal_nullable_fk'     => IntegrityIssue::class,
        // Security issues
        'query_builder_sql_injection' => DQLInjectionIssue::class,
        'unescaped_like'              => DQLInjectionIssue::class,
        'incorrect_null_comparison'   => DQLInjectionIssue::class,
        'empty_in_clause'             => DQLInjectionIssue::class,
        'missing_parameters'          => DQLInjectionIssue::class,
        // Performance issues
        'setMaxResults_with_collection_join' => PerformanceIssue::class,
        'setMaxResults with Collection Join' => PerformanceIssue::class,
        'cartesian_product'                  => PerformanceIssue::class,
        'Cartesian Product'                  => PerformanceIssue::class,
        'cartesian_product_risk'             => PerformanceIssue::class,
        'Cartesian Product Risk'             => PerformanceIssue::class,
        'unused_eager_load'                  => PerformanceIssue::class,
        'Unused Eager Load'                  => PerformanceIssue::class,
        'nested_n_plus_one'                  => NPlusOneIssue::class,
        'Nested N+1'                         => NPlusOneIssue::class,
        // Embeddable issues
        'missing_embeddable_opportunity'              => IntegrityIssue::class,
        'embeddable_mutability'                       => IntegrityIssue::class,
        'embeddable_without_value_object_methods'     => IntegrityIssue::class,
        'float_in_money_embeddable'                   => IntegrityIssue::class,
        // Doctrine Extensions issues (Timestampable, Blameable, SoftDeleteable, etc.)
        'missing_blameable_trait_opportunity'         => IntegrityIssue::class,
        'timestampable_mutable_datetime'              => IntegrityIssue::class,
        'timestampable_missing_timezone'              => ConfigurationIssue::class,
        'timestampable_missing_timezone_global'       => ConfigurationIssue::class,
        'timestampable_timezone_inconsistency'        => ConfigurationIssue::class,
        'timestampable_nullable_created_at'           => IntegrityIssue::class,
        'timestampable_public_setter'                 => IntegrityIssue::class,
        'blameable_nullable_created_by'               => IntegrityIssue::class,
        'blameable_public_setter'                     => IntegrityIssue::class,
        'blameable_wrong_target'                      => ConfigurationIssue::class,
        'soft_delete_not_nullable'                    => ConfigurationIssue::class,
        'soft_delete_mutable_datetime'                => IntegrityIssue::class,
        'soft_delete_public_setter'                   => IntegrityIssue::class,
        'soft_delete_missing_timezone'                => ConfigurationIssue::class,
        'soft_delete_cascade_conflict'                => ConfigurationIssue::class,
    ];

    public function create(IssueData $issueData): IssueInterface
    {
        return $this->createFromArray($issueData->toArray());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createFromArray(array $data): IssueInterface
    {
        $rawType = $data['type'] ?? 'unknown';
        $type = is_string($rawType) ? $rawType : 'unknown';

        // Find the concrete class for this issue type
        $issueClass = self::TYPE_MAP[$type] ?? null;

        if (null === $issueClass) {
            throw new InvalidArgumentException(sprintf('Unknown issue type "%s". Available types: %s', $type, implode(', ', array_keys(self::TYPE_MAP))));
        }

        return new $issueClass($data);
    }

    /**
     * Check if a type is supported.
     */
    public function supports(string $type): bool
    {
        return isset(self::TYPE_MAP[$type]);
    }

    /**
     * Get all supported issue types.
     * @return string[]
     */
    public function getSupportedTypes(): array
    {
        return array_keys(self::TYPE_MAP);
    }
}
