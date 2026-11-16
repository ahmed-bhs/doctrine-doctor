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
                '%d queries selected "%s" entities by ID. ' .
                'When the entity only sets an association, each query loads a row that getReference() would not need.',
                $data['query_count'] ?? 0,
                $data['entity'] ?? 'unknown',
            ),
            'severity' => $data['severity'] ?? 'warning',
        ], $data));
    }

    #[\Override]
    public static function supportedTypes(): array
    {
        return [
            IssueType::GET_REFERENCE->value,
        ];
    }

    public function getCategory(): IssueCategory
    {
        return IssueCategory::PERFORMANCE;
    }
}
