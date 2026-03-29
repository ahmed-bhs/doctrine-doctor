<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\ValueObject;

/**
 * Issue types for Doctrine Doctor analysis.
 * PHP 8.1+ native enum for type safety and better IDE support.
 * Using enums ensures consistency across the codebase and prevents typos.
 */
enum IssueType: string
{
    // Performance issues
    case PERFORMANCE = 'performance';
    case SLOW_QUERY = 'slow_query';
    case N_PLUS_ONE = 'n_plus_one';
    case EAGER_LOADING = 'eager_loading';
    case LAZY_LOADING = 'lazy_loading';
    case FLUSH_IN_LOOP = 'flush_in_loop';
    case BULK_OPERATION = 'bulk_operation';
    case HYDRATION = 'hydration';
    case MISSING_INDEX = 'missing_index';
    case FIND_ALL = 'find_all';
    case GET_REFERENCE = 'get_reference';
    case CARTESIAN_PRODUCT = 'cartesian_product';
    case CARTESIAN_PRODUCT_RISK = 'cartesian_product_risk';
    case UNUSED_EAGER_LOAD = 'unused_eager_load';
    case EAGER_LOADING_MAPPING = 'eager_loading_mapping';
    case NESTED_N_PLUS_ONE = 'nested_n_plus_one';
    case AGGREGATION_WITH_INNER_JOIN = 'aggregation_with_inner_join';
    case SET_MAX_RESULTS_WITH_COLLECTION_JOIN = 'setMaxResults_with_collection_join';

    // Security issues
    case SECURITY = 'security';
    case DQL_INJECTION = 'dql_injection';
    case QUERY_BUILDER_SQL_INJECTION = 'query_builder_sql_injection';
    case OVERPRIVILEGED_DATABASE_USER = 'overprivileged_database_user';
    case HARDCODED_DATABASE_CREDENTIALS = 'hardcoded_database_credentials';
    case UNESCAPED_LIKE = 'unescaped_like';
    case INCORRECT_NULL_COMPARISON = 'incorrect_null_comparison';
    case EMPTY_IN_CLAUSE = 'empty_in_clause';
    case MISSING_PARAMETERS = 'missing_parameters';

    // Integrity issues
    case INTEGRITY = 'integrity';
    case PROPERTY_TYPE_MISMATCH = 'property_type_mismatch';
    case FINAL_ENTITY = 'final_entity';
    case ENTITY_STATE = 'entity_state';
    case COLLECTION_EMPTY_ACCESS = 'collection_empty_access';
    case COLLECTION_UNINITIALIZED = 'collection_uninitialized';
    case DQL_VALIDATION = 'dql_validation';
    case REPOSITORY_INVALID_FIELD = 'repository_invalid_field';
    case ENTITY_MANAGER_CLEAR = 'entity_manager_clear';
    case FLOAT_FOR_MONEY = 'float_for_money';
    case TYPE_HINT_MISMATCH = 'type_hint_mismatch';
    case INTEGRITY_GENERIC = 'integrity_generic';
    case INTEGRITY_INCORRECT_NULL_COMPARISON = 'integrity_incorrect_null_comparison';
    case LEFT_JOIN_WITH_NOT_NULL = 'left_join_with_not_null';
    case CASCADE_REMOVE_SET_NULL = 'cascade_remove_set_null';
    case ONDELETE_CASCADE_NO_ORM = 'ondelete_cascade_no_orm';
    case ORPHAN_REMOVAL_NO_PERSIST = 'orphan_removal_no_persist';
    case ORPHAN_REMOVAL_NULLABLE_FK = 'orphan_removal_nullable_fk';
    case MISSING_EMBEDDABLE_OPPORTUNITY = 'missing_embeddable_opportunity';
    case EMBEDDABLE_MUTABILITY = 'embeddable_mutability';
    case EMBEDDABLE_WITHOUT_VALUE_OBJECT_METHODS = 'embeddable_without_value_object_methods';
    case FLOAT_IN_MONEY_EMBEDDABLE = 'float_in_money_embeddable';
    case MISSING_BLAMEABLE_TRAIT_OPPORTUNITY = 'missing_blameable_trait_opportunity';
    case TIMESTAMPABLE_MUTABLE_DATETIME = 'timestampable_mutable_datetime';
    case TIMESTAMPABLE_NULLABLE_CREATED_AT = 'timestampable_nullable_created_at';
    case TIMESTAMPABLE_PUBLIC_SETTER = 'timestampable_public_setter';
    case BLAMEABLE_NULLABLE_CREATED_BY = 'blameable_nullable_created_by';
    case BLAMEABLE_PUBLIC_SETTER = 'blameable_public_setter';
    case SOFT_DELETE_MUTABLE_DATETIME = 'soft_delete_mutable_datetime';
    case SOFT_DELETE_PUBLIC_SETTER = 'soft_delete_public_setter';
    case AUTO_INCREMENT_EDUCATIONAL = 'auto_increment_educational';
    case UUID_V4_PERFORMANCE = 'uuid_v4_performance';
    case MIXED_ID_STRATEGIES = 'mixed_id_strategies';
    case ENTITY_DETACHED_MODIFICATION = 'entity_detached_modification';
    case ENTITY_NEW_IN_ASSOCIATION = 'entity_new_in_association';
    case ENTITY_REQUIRED_FIELD_NULL = 'entity_required_field_null';
    case ENTITY_REQUIRED_ASSOCIATION_NULL = 'entity_required_association_null';
    case ENTITY_REMOVED_ACCESS = 'entity_removed_access';
    case ENTITY_REMOVED_IN_ASSOCIATION = 'entity_removed_in_association';
    case ENTITY_DETACHED_IN_ASSOCIATION = 'entity_detached_in_association';
    case JOIN_COLUMN_NON_PRIMARY_KEY = 'join_column_non_primary_key';
    case DUPLICATE_PRIVATE_FIELD_IN_HIERARCHY = 'duplicate_private_field_in_hierarchy';
    case COMPOSITE_KEY_COMPLEXITY = 'composite_key_complexity';
    case ONE_TO_ONE_INVERSE_SIDE = 'one_to_one_inverse_side';
    case STI_SPARSE_TABLE = 'sti_sparse_table';
    case CTI_THIN_SUBCLASS = 'cti_thin_subclass';
    case CTI_DEEP_HIERARCHY = 'cti_deep_hierarchy';
    case MAPPED_SUPERCLASS_AS_TARGET = 'mapped_superclass_as_target';
    case INHERITANCE_TYPE_ON_NON_ROOT = 'inheritance_type_on_non_root';
    case STI_NON_NULLABLE_SUBCLASS_COLUMN = 'sti_non_nullable_subclass_column';
    case MAPPED_SUPERCLASS_ONE_TO_MANY = 'mapped_superclass_one_to_many';
    case UNIQUE_ENTITY_WITHOUT_INDEX = 'unique_entity_without_index';
    case DENORMALIZED_AGGREGATE_WITHOUT_LOCKING = 'denormalized_aggregate_without_locking';

    // Configuration issues
    case CONFIGURATION = 'configuration';
    case TRANSACTION_BOUNDARY = 'transaction_boundary';
    case DECIMAL_MISSING_PRECISION = 'decimal_missing_precision';
    case DECIMAL_INSUFFICIENT_PRECISION = 'decimal_insufficient_precision';
    case DECIMAL_EXCESSIVE_PRECISION = 'decimal_excessive_precision';
    case DECIMAL_UNUSUAL_SCALE = 'decimal_unusual_scale';
    case TIMESTAMPABLE_MISSING_TIMEZONE = 'timestampable_missing_timezone';
    case TIMESTAMPABLE_MISSING_TIMEZONE_GLOBAL = 'timestampable_missing_timezone_global';
    case TIMESTAMPABLE_TIMEZONE_INCONSISTENCY = 'timestampable_timezone_inconsistency';
    case BLAMEABLE_WRONG_TARGET = 'blameable_wrong_target';
    case SOFT_DELETE_NOT_NULLABLE = 'soft_delete_not_nullable';
    case SOFT_DELETE_MISSING_TIMEZONE = 'soft_delete_missing_timezone';
    case SOFT_DELETE_CASCADE_CONFLICT = 'soft_delete_cascade_conflict';
    case TRANSACTION_NESTED = 'transaction_nested';
    case TRANSACTION_MULTIPLE_FLUSH = 'transaction_multiple_flush';
    case TRANSACTION_UNCLOSED = 'transaction_unclosed';
    case TRANSACTION_TOO_LONG = 'transaction_too_long';

    /**
     * Create from string value (for backward compatibility).
     */
    public static function fromString(string $value): self
    {
        return self::from($value);
    }

    /**
     * Get the string value (for backward compatibility).
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Check if a type is valid.
     */
    public static function isValid(string $type): bool
    {
        return null !== self::tryFrom($type);
    }

    /**
     * Get all issue types.
     * @return self[]
     */
    public static function all(): array
    {
        return self::cases();
    }
}
