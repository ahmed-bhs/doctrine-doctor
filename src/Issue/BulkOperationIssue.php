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
                '%d individual %s queries ran on table "%s". ' .
                'Each row costs its own statement and round trip, where one batched statement would do.',
                $data['query_count'] ?? 0,
                $data['operation_type'] ?? 'UPDATE',
                $data['table'] ?? 'unknown',
            ),
            'severity' => $data['severity'] ?? 'warning',
        ], $data));
    }

    #[\Override]
    public static function supportedTypes(): array
    {
        return [
            IssueType::BULK_OPERATION->value,
        ];
    }

    public function getCategory(): IssueCategory
    {
        return IssueCategory::PERFORMANCE;
    }
}
