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

class MissingIndexIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        parent::__construct(array_merge([
            'type'        => IssueType::MISSING_INDEX,
            'title'       => 'Missing Index Detected',
            'description' => sprintf(
                'Query "%s" on table "%s" scanned %d rows, suggesting a missing index.',
                $data['query'] ?? 'N/A',
                $data['table'] ?? 'N/A',
                $data['rows_scanned'] ?? 0,
            ),
            'severity' => $data['severity'] ?? 'warning',
        ], $data));
    }

    public function getCategory(): string
    {
        return IssueCategory::PERFORMANCE->value;
    }
}
