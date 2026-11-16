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
                '%d sequential INSERT/UPDATE/DELETE operations ran on table "%s" without EntityManager::clear(). ' .
                'Every entity stays managed by the unit of work, so memory and flush time grow with the batch.',
                $data['operation_count'] ?? 0,
                $data['table'] ?? 'unknown',
            ),
            'severity' => $data['severity'] ?? 'critical',
        ], $data));
    }

    #[\Override]
    public static function supportedTypes(): array
    {
        return [
            IssueType::ENTITY_MANAGER_CLEAR->value,
        ];
    }

    public function getCategory(): IssueCategory
    {
        return IssueCategory::PERFORMANCE;
    }
}
