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
 * Represents a configuration issue detected by Doctrine Doctor.
 * Configuration issues are related to database or ORM configuration problems
 * like decimal precision, charset, collation, strict mode, etc.
 */
class ConfigurationIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        parent::__construct(array_merge([
            'type'        => IssueType::CONFIGURATION,
            'title'       => 'Configuration Issue',
            'description' => 'Configuration needs optimization.',
            'severity'    => $data['severity'] ?? 'warning',
        ], $data));
    }

    public static function supportedTypes(): array
    {
        return [
            IssueType::DECIMAL_MISSING_PRECISION->value,
            IssueType::DECIMAL_INSUFFICIENT_PRECISION->value,
            IssueType::DECIMAL_EXCESSIVE_PRECISION->value,
            IssueType::DECIMAL_UNUSUAL_SCALE->value,
            IssueType::TIMESTAMPABLE_MISSING_TIMEZONE->value,
            IssueType::TIMESTAMPABLE_MISSING_TIMEZONE_GLOBAL->value,
            IssueType::TIMESTAMPABLE_TIMEZONE_INCONSISTENCY->value,
            IssueType::BLAMEABLE_WRONG_TARGET->value,
            IssueType::SOFT_DELETE_NOT_NULLABLE->value,
            IssueType::SOFT_DELETE_MISSING_TIMEZONE->value,
            IssueType::SOFT_DELETE_CASCADE_CONFLICT->value,
        ];
    }

    #[\Override]
    public function getType(): string
    {
        return 'Configuration';
    }

    public function getCategory(): IssueCategory
    {
        return IssueCategory::CONFIGURATION;
    }
}
