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

class EntityManagerClearIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        parent::__construct(array_merge([
            'type'        => IssueType::ENTITY_MANAGER_CLEAR,
            'title'       => 'Batch Operations Without EntityManager Clear',
            'description' => sprintf(
                'Detected %d sequential INSERT/UPDATE/DELETE operations on table "%s" without apparent EntityManager::clear() calls. ' .
                'This can lead to memory leaks in batch processing as Doctrine keeps all managed entities in memory. ' .
                'Consider calling $entityManager->clear() periodically (e.g., every 50-100 operations) to free memory.',
                $data['operation_count'] ?? 0,
                $data['table'] ?? 'unknown',
            ),
            'severity' => $data['severity'] ?? 'critical',
        ], $data));
    }

    public function getCategory(): string
    {
        return IssueCategory::PERFORMANCE->value;
    }
}
