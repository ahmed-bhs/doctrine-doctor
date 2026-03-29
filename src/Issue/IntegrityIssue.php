<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Issue;

use AhmedBhs\DoctrineDoctor\ValueObject\IssueCategory;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;

/**
 * Represents a data integrity issue detected by Doctrine Doctor.
 * Integrity issues are anti-patterns, violations of best practices,
 * or architectural problems in entity code that don't necessarily cause
 * immediate bugs but lead to maintainability, testability, or performance issues.
 */
class IntegrityIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        parent::__construct(array_merge([
            'type'        => IssueType::INTEGRITY,
            'title'       => 'Integrity Issue',
            'description' => 'Data integrity needs improvement.',
            'severity'    => $data['severity'] ?? 'warning',
        ], $data));
    }

    public static function supportedTypes(): array
    {
        return [
            IssueType::INTEGRITY->value,
            IssueType::INTEGRITY_GENERIC->value,
            IssueType::TYPE_HINT_MISMATCH->value,
            IssueType::FLOAT_FOR_MONEY->value,
            IssueType::CASCADE_REMOVE_SET_NULL->value,
            IssueType::ONDELETE_CASCADE_NO_ORM->value,
            IssueType::ORPHAN_REMOVAL_NO_PERSIST->value,
            IssueType::ORPHAN_REMOVAL_NULLABLE_FK->value,
            IssueType::INTEGRITY_INCORRECT_NULL_COMPARISON->value,
            IssueType::LEFT_JOIN_WITH_NOT_NULL->value,
            IssueType::MISSING_EMBEDDABLE_OPPORTUNITY->value,
            IssueType::EMBEDDABLE_MUTABILITY->value,
            IssueType::EMBEDDABLE_WITHOUT_VALUE_OBJECT_METHODS->value,
            IssueType::FLOAT_IN_MONEY_EMBEDDABLE->value,
            IssueType::MISSING_BLAMEABLE_TRAIT_OPPORTUNITY->value,
            IssueType::TIMESTAMPABLE_MUTABLE_DATETIME->value,
            IssueType::TIMESTAMPABLE_NULLABLE_CREATED_AT->value,
            IssueType::TIMESTAMPABLE_PUBLIC_SETTER->value,
            IssueType::BLAMEABLE_NULLABLE_CREATED_BY->value,
            IssueType::BLAMEABLE_PUBLIC_SETTER->value,
            IssueType::SOFT_DELETE_MUTABLE_DATETIME->value,
            IssueType::SOFT_DELETE_PUBLIC_SETTER->value,
            IssueType::AUTO_INCREMENT_EDUCATIONAL->value,
            IssueType::UUID_V4_PERFORMANCE->value,
            IssueType::MIXED_ID_STRATEGIES->value,
            IssueType::JOIN_COLUMN_NON_PRIMARY_KEY->value,
            IssueType::DUPLICATE_PRIVATE_FIELD_IN_HIERARCHY->value,
            IssueType::COMPOSITE_KEY_COMPLEXITY->value,
            IssueType::ONE_TO_ONE_INVERSE_SIDE->value,
            IssueType::STI_SPARSE_TABLE->value,
            IssueType::CTI_THIN_SUBCLASS->value,
            IssueType::CTI_DEEP_HIERARCHY->value,
            IssueType::MAPPED_SUPERCLASS_AS_TARGET->value,
            IssueType::INHERITANCE_TYPE_ON_NON_ROOT->value,
            IssueType::STI_NON_NULLABLE_SUBCLASS_COLUMN->value,
            IssueType::MAPPED_SUPERCLASS_ONE_TO_MANY->value,
            IssueType::UNIQUE_ENTITY_WITHOUT_INDEX->value,
            IssueType::DENORMALIZED_AGGREGATE_WITHOUT_LOCKING->value,
            IssueType::MANY_TO_MANY_EXTRA_COLUMNS->value,
            IssueType::MISSING_VERSION_FIELD_FOR_CONCURRENCY->value,
            IssueType::FLUSH_IN_LIFECYCLE_CALLBACK->value,
        ];
    }

    public function getCategory(): IssueCategory
    {
        return IssueCategory::INTEGRITY;
    }
}

// Backward compatibility alias for serialized data in profiler
class_alias(IntegrityIssue::class, 'AhmedBhs\DoctrineDoctor\Issue\CodeQualityIssue');
