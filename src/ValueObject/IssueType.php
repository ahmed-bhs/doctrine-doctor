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

    // Security issues
    case SECURITY = 'security';
    case DQL_INJECTION = 'dql_injection';

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

    // Configuration issues
    case CONFIGURATION = 'configuration';
    case TRANSACTION_BOUNDARY = 'transaction_boundary';

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
