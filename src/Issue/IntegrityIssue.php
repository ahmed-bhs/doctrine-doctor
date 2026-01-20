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

    public function getType(): string
    {
        return 'Integrity';
    }

    public function getCategory(): string
    {
        return IssueCategory::INTEGRITY->value;
    }
}

// Backward compatibility alias for serialized data in profiler
class_alias(IntegrityIssue::class, 'AhmedBhs\DoctrineDoctor\Issue\CodeQualityIssue');
