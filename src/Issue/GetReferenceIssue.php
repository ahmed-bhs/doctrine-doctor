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

class GetReferenceIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        parent::__construct(array_merge([
            'type'        => IssueType::GET_REFERENCE,
            'title'       => 'Use getReference() Instead of find()',
            'description' => sprintf(
                'Detected %d queries selecting "%s" entities by ID. ' .
                'If these entities are only used as references (e.g., for setting relationships), ' .
                'consider using EntityManager::getReference() instead of find(). ' .
                'This creates a proxy without executing a database query, which can eliminate unnecessary SELECT statements.',
                $data['query_count'] ?? 0,
                $data['entity'] ?? 'unknown',
            ),
            'severity' => $data['severity'] ?? 'warning',
        ], $data));
    }

    public function getCategory(): string
    {
        return IssueCategory::PERFORMANCE->value;
    }
}
