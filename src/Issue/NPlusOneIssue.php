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
                'The same query ran %d times: "%s". One query per loop iteration means the page slows down as the data grows.',
                $data['count'] ?? 0,
                $data['pattern'] ?? 'N/A',
            ),
            'severity' => $data['severity'] ?? 'critical',
        ], $data));
    }

    #[\Override]
    public static function supportedTypes(): array
    {
        return [
            IssueType::N_PLUS_ONE->value,
            IssueType::NESTED_N_PLUS_ONE->value,
        ];
    }

    public function getCategory(): IssueCategory
    {
        return IssueCategory::PERFORMANCE;
    }
}
