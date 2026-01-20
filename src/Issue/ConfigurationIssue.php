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

    public function getType(): string
    {
        return 'Configuration';
    }

    public function getCategory(): string
    {
        return IssueCategory::CONFIGURATION->value;
    }
}
