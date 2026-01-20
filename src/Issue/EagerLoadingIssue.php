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

class EagerLoadingIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        parent::__construct(array_merge([
            'type'        => IssueType::EAGER_LOADING,
            'title'       => 'Excessive Eager Loading Detected',
            'description' => sprintf(
                'Query has %d JOINs, potentially indicating excessive eager loading. Review the query: "%s"',
                $data['join_count'] ?? 0,
                $data['query'] ?? 'N/A',
            ),
            'severity' => $data['severity'] ?? 'warning',
        ], $data));
    }

    public function getCategory(): string
    {
        return IssueCategory::PERFORMANCE->value;
    }
}
