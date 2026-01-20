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

class SlowQueryIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        parent::__construct(array_merge([
            'type'        => IssueType::SLOW_QUERY,
            'title'       => 'Slow Query Detected',
            'description' => sprintf(
                'Query took %dms to execute, exceeding the threshold of %dms.',
                $data['execution_time'] ?? 0,
                $data['threshold'] ?? 0,
            ),
            'severity' => $data['severity'] ?? 'critical',
        ], $data));
    }

    public function getType(): string
    {
        return 'Slow Query';
    }

    public function getCategory(): string
    {
        return IssueCategory::PERFORMANCE->value;
    }
}
