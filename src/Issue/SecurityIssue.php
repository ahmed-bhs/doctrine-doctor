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
 * Represents a security vulnerability issue detected by Doctrine Doctor.
 */
class SecurityIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        parent::__construct(array_merge([
            'type'        => IssueType::SECURITY,
            'title'       => 'Security Issue',
            'description' => 'Security vulnerability detected.',
            'severity'    => 'critical',
        ], $data));
    }

    public function getType(): string
    {
        return 'Security';
    }

    public function getCategory(): string
    {
        return IssueCategory::SECURITY->value;
    }
}
