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

class NPlusOneIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        parent::__construct(array_merge([
            'type'        => IssueType::N_PLUS_ONE,
            'title'       => 'N+1 Query Detected',
            'description' => sprintf(
                'Detected %d similar queries with pattern "%s". This often indicates an N+1 query problem.',
                $data['count'] ?? 0,
                $data['pattern'] ?? 'N/A',
            ),
            'severity' => $data['severity'] ?? 'critical',
        ], $data));
    }

    public function getCategory(): string
    {
        return IssueCategory::PERFORMANCE->value;
    }
}
