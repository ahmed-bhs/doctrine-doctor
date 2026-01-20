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

class HydrationIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        parent::__construct(array_merge([
            'type'        => IssueType::HYDRATION,
            'title'       => 'Potential Hydration Issue',
            'description' => sprintf(
                'Query returned %d rows in %dms. This might indicate excessive hydration overhead.',
                $data['row_count'] ?? 0,
                $data['execution_time'] ?? 0,
            ),
            'severity' => $data['severity'] ?? 'warning',
        ], $data));
    }

    public function getCategory(): string
    {
        return IssueCategory::PERFORMANCE->value;
    }
}
