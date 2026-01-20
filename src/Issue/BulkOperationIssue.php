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

class BulkOperationIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        parent::__construct(array_merge([
            'type'        => IssueType::BULK_OPERATION,
            'title'       => 'Use DQL Bulk Operations Instead of Loop',
            'description' => sprintf(
                'Detected %d individual %s operations on table "%s". ' .
                'Executing multiple UPDATE/DELETE queries in a loop is extremely inefficient. ' .
                'Instead, use DQL bulk operations (UPDATE/DELETE queries) to perform mass operations in a single database query. ' .
                'This can improve performance by 10-100x depending on the number of affected rows.',
                $data['query_count'] ?? 0,
                $data['operation_type'] ?? 'UPDATE',
                $data['table'] ?? 'unknown',
            ),
            'severity' => $data['severity'] ?? 'warning',
        ], $data));
    }

    public function getCategory(): string
    {
        return IssueCategory::PERFORMANCE->value;
    }
}
