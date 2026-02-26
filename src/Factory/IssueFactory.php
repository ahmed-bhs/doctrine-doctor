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
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
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
        IssueType::N_PLUS_ONE->value         => NPlusOneIssue::class,
        'N+1 Query'                          => NPlusOneIssue::class,
        IssueType::MISSING_INDEX->value      => MissingIndexIssue::class,
        'Missing Index'                      => MissingIndexIssue::class,
        IssueType::SLOW_QUERY->value         => SlowQueryIssue::class,
        'Slow Query'                         => SlowQueryIssue::class,
        IssueType::HYDRATION->value          => HydrationIssue::class,
        'Excessive Hydration'                => HydrationIssue::class,
        IssueType::EAGER_LOADING->value      => EagerLoadingIssue::class,
        'Excessive Eager Loading'            => EagerLoadingIssue::class,
        IssueType::FIND_ALL->value           => FindAllIssue::class,
        'findAll() Usage'                    => FindAllIssue::class,
        IssueType::ENTITY_MANAGER_CLEAR->value => EntityManagerClearIssue::class,
        'Memory Leak Risk'                   => EntityManagerClearIssue::class,
        IssueType::GET_REFERENCE->value      => GetReferenceIssue::class,
        'Inefficient Entity Loading'         => GetReferenceIssue::class,
        IssueType::FLUSH_IN_LOOP->value      => FlushInLoopIssue::class,
        'Performance Anti-Pattern'           => FlushInLoopIssue::class,
        IssueType::LAZY_LOADING->value       => LazyLoadingIssue::class,
        'Lazy Loading in Loop'               => LazyLoadingIssue::class,
        IssueType::DQL_INJECTION->value      => DQLInjectionIssue::class,
        'Security Vulnerability'             => DQLInjectionIssue::class,
        IssueType::BULK_OPERATION->value     => BulkOperationIssue::class,
        'Inefficient Bulk Operations'        => BulkOperationIssue::class,
        IssueType::CONFIGURATION->value      => DatabaseConfigIssue::class,
        'Database Configuration Issue'       => DatabaseConfigIssue::class,
        IssueType::REPOSITORY_INVALID_FIELD->value => RepositoryFieldValidationIssue::class,
        'Invalid Field in Repository Method' => RepositoryFieldValidationIssue::class,
        IssueType::FINAL_ENTITY->value       => FinalEntityIssue::class,
        'Final Entity Class'                 => FinalEntityIssue::class,
        IssueType::PROPERTY_TYPE_MISMATCH->value => PropertyTypeMismatchIssue::class,
        'Property Type Mismatch'             => PropertyTypeMismatchIssue::class,
        IssueType::DQL_VALIDATION->value     => DQLValidationIssue::class,
        'DQL Validation Error'               => DQLValidationIssue::class,
        IssueType::COLLECTION_EMPTY_ACCESS->value => CollectionEmptyAccessIssue::class,
        'Unsafe Collection Access'           => CollectionEmptyAccessIssue::class,
        IssueType::COLLECTION_UNINITIALIZED->value => CollectionUninitializedIssue::class,
        'Uninitialized Collection'           => CollectionUninitializedIssue::class,
        // Transaction issues
        IssueType::TRANSACTION_NESTED->value         => TransactionIssue::class,
        IssueType::TRANSACTION_MULTIPLE_FLUSH->value => TransactionIssue::class,
        IssueType::TRANSACTION_UNCLOSED->value       => TransactionIssue::class,
        IssueType::TRANSACTION_TOO_LONG->value       => TransactionIssue::class,
        'Transaction Boundary Issue' => TransactionIssue::class,
        // Entity state issues
        IssueType::ENTITY_DETACHED_MODIFICATION->value     => EntityStateIssue::class,
        IssueType::ENTITY_NEW_IN_ASSOCIATION->value        => EntityStateIssue::class,
        IssueType::ENTITY_REQUIRED_FIELD_NULL->value       => EntityStateIssue::class,
        IssueType::ENTITY_REQUIRED_ASSOCIATION_NULL->value => EntityStateIssue::class,
        IssueType::ENTITY_REMOVED_ACCESS->value            => EntityStateIssue::class,
        IssueType::ENTITY_REMOVED_IN_ASSOCIATION->value    => EntityStateIssue::class,
        IssueType::ENTITY_DETACHED_IN_ASSOCIATION->value   => EntityStateIssue::class,
        'Entity State Issue'               => EntityStateIssue::class,
        // Code quality issues
        IssueType::FLOAT_FOR_MONEY->value                => IntegrityIssue::class,
        'Float for Money'                => IntegrityIssue::class,
        IssueType::INTEGRITY_GENERIC->value              => IntegrityIssue::class,
        IssueType::TYPE_HINT_MISMATCH->value             => IntegrityIssue::class,
        'Type Hint Mismatch'             => IntegrityIssue::class,
        IssueType::DECIMAL_MISSING_PRECISION->value      => ConfigurationIssue::class,
        IssueType::DECIMAL_INSUFFICIENT_PRECISION->value => ConfigurationIssue::class,
        IssueType::DECIMAL_EXCESSIVE_PRECISION->value    => ConfigurationIssue::class,
        IssueType::DECIMAL_UNUSUAL_SCALE->value          => ConfigurationIssue::class,
        IssueType::CASCADE_REMOVE_SET_NULL->value        => IntegrityIssue::class,
        IssueType::ONDELETE_CASCADE_NO_ORM->value        => IntegrityIssue::class,
        IssueType::ORPHAN_REMOVAL_NO_PERSIST->value      => IntegrityIssue::class,
        IssueType::ORPHAN_REMOVAL_NULLABLE_FK->value     => IntegrityIssue::class,
        // Security issues
        IssueType::QUERY_BUILDER_SQL_INJECTION->value => DQLInjectionIssue::class,
        IssueType::UNESCAPED_LIKE->value              => DQLInjectionIssue::class,
        IssueType::INCORRECT_NULL_COMPARISON->value   => DQLInjectionIssue::class,
        IssueType::INTEGRITY_INCORRECT_NULL_COMPARISON->value => IntegrityIssue::class,
        IssueType::EMPTY_IN_CLAUSE->value             => DQLInjectionIssue::class,
        IssueType::MISSING_PARAMETERS->value          => DQLInjectionIssue::class,
        // Performance issues
        'setMaxResults_with_collection_join' => PerformanceIssue::class,
        'setMaxResults with Collection Join' => PerformanceIssue::class,
        IssueType::CARTESIAN_PRODUCT->value                  => PerformanceIssue::class,
        'Cartesian Product'                  => PerformanceIssue::class,
        IssueType::CARTESIAN_PRODUCT_RISK->value             => PerformanceIssue::class,
        'Cartesian Product Risk'             => PerformanceIssue::class,
        IssueType::UNUSED_EAGER_LOAD->value                  => PerformanceIssue::class,
        'Unused Eager Load'                  => PerformanceIssue::class,
        IssueType::NESTED_N_PLUS_ONE->value                  => NPlusOneIssue::class,
        'Nested N+1'                         => NPlusOneIssue::class,
        IssueType::LEFT_JOIN_WITH_NOT_NULL->value            => IntegrityIssue::class,
        IssueType::AGGREGATION_WITH_INNER_JOIN->value        => PerformanceIssue::class,
        // Embeddable issues
        IssueType::MISSING_EMBEDDABLE_OPPORTUNITY->value              => IntegrityIssue::class,
        IssueType::EMBEDDABLE_MUTABILITY->value                       => IntegrityIssue::class,
        IssueType::EMBEDDABLE_WITHOUT_VALUE_OBJECT_METHODS->value     => IntegrityIssue::class,
        IssueType::FLOAT_IN_MONEY_EMBEDDABLE->value                   => IntegrityIssue::class,
        // Doctrine Extensions issues (Timestampable, Blameable, SoftDeleteable, etc.)
        IssueType::MISSING_BLAMEABLE_TRAIT_OPPORTUNITY->value         => IntegrityIssue::class,
        IssueType::TIMESTAMPABLE_MUTABLE_DATETIME->value              => IntegrityIssue::class,
        IssueType::TIMESTAMPABLE_MISSING_TIMEZONE->value              => ConfigurationIssue::class,
        IssueType::TIMESTAMPABLE_MISSING_TIMEZONE_GLOBAL->value       => ConfigurationIssue::class,
        IssueType::TIMESTAMPABLE_TIMEZONE_INCONSISTENCY->value        => ConfigurationIssue::class,
        IssueType::TIMESTAMPABLE_NULLABLE_CREATED_AT->value           => IntegrityIssue::class,
        IssueType::TIMESTAMPABLE_PUBLIC_SETTER->value                 => IntegrityIssue::class,
        IssueType::BLAMEABLE_NULLABLE_CREATED_BY->value               => IntegrityIssue::class,
        IssueType::BLAMEABLE_PUBLIC_SETTER->value                     => IntegrityIssue::class,
        IssueType::BLAMEABLE_WRONG_TARGET->value                      => ConfigurationIssue::class,
        IssueType::SOFT_DELETE_NOT_NULLABLE->value                    => ConfigurationIssue::class,
        IssueType::SOFT_DELETE_MUTABLE_DATETIME->value                => IntegrityIssue::class,
        IssueType::SOFT_DELETE_PUBLIC_SETTER->value                   => IntegrityIssue::class,
        IssueType::SOFT_DELETE_MISSING_TIMEZONE->value                => ConfigurationIssue::class,
        IssueType::SOFT_DELETE_CASCADE_CONFLICT->value                => ConfigurationIssue::class,
        // Primary key strategy advisory issues
        IssueType::AUTO_INCREMENT_EDUCATIONAL->value                  => IntegrityIssue::class,
        IssueType::UUID_V4_PERFORMANCE->value                         => IntegrityIssue::class,
        IssueType::MIXED_ID_STRATEGIES->value                         => IntegrityIssue::class,
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
        $type = $rawType instanceof IssueType ? $rawType->value : (is_string($rawType) ? $rawType : 'unknown');

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
