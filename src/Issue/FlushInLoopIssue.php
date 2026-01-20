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

class FlushInLoopIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        parent::__construct(array_merge([
            'type'        => IssueType::FLUSH_IN_LOOP,
            'title'       => 'flush() Called Inside Loop',
            'description' => sprintf(
                'Detected %d flush() calls with an average of %.1f operations between each flush. ' .
                'Calling flush() inside a loop creates one database transaction per iteration, which is extremely inefficient. ' .
                'Each flush forces Doctrine to synchronize all managed entities with the database, causing significant overhead. ' .
                'Instead, batch your operations and call flush() once after the loop, or use batch processing with periodic flushes (e.g., every 50-100 entities).',
                $data['flush_count'] ?? 0,
                $data['operations_between_flush'] ?? 0,
            ),
            'severity' => $data['severity'] ?? 'critical',
        ], $data));
    }

    public function getType(): string
    {
        return 'Flush In Loop';
    }

    public function getCategory(): string
    {
        return IssueCategory::PERFORMANCE->value;
    }
}
