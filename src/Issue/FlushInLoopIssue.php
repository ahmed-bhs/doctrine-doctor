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
                '%d flush() calls ran in a loop, with %.1f operations between flushes on average. ' .
                'Each flush computes the change set of every managed entity and opens its own transaction.',
                $data['flush_count'] ?? 0,
                $data['operations_between_flush'] ?? 0,
            ),
            'severity' => $data['severity'] ?? 'critical',
        ], $data));
    }

    #[\Override]
    public static function supportedTypes(): array
    {
        return [
            IssueType::FLUSH_IN_LOOP->value,
        ];
    }

    #[\Override]
    public function getType(): string
    {
        return IssueType::FLUSH_IN_LOOP->value;
    }

    public function getCategory(): IssueCategory
    {
        return IssueCategory::PERFORMANCE;
    }
}
