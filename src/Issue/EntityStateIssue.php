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

/**
 * Issue for entity state consistency problems.
 * Detected by EntityStateConsistencyAnalyzer.
 */
class EntityStateIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        // Keep original type from analyzer (entity_detached_modification, entity_new_in_association, etc.)
        $data['type'] ??= IssueType::ENTITY_STATE;
        $data['title'] ??= 'Entity State Issue';
        $data['description'] ??= 'Entity state consistency issue detected.';

        parent::__construct($data);
    }

    public static function supportedTypes(): array
    {
        return [
            IssueType::ENTITY_STATE->value,
            IssueType::ENTITY_DETACHED_MODIFICATION->value,
            IssueType::ENTITY_NEW_IN_ASSOCIATION->value,
            IssueType::ENTITY_REQUIRED_FIELD_NULL->value,
            IssueType::ENTITY_REQUIRED_ASSOCIATION_NULL->value,
            IssueType::ENTITY_REMOVED_ACCESS->value,
            IssueType::ENTITY_REMOVED_IN_ASSOCIATION->value,
            IssueType::ENTITY_DETACHED_IN_ASSOCIATION->value,
            'Entity State Issue',
        ];
    }

    public function getCategory(): IssueCategory
    {
        return IssueCategory::INTEGRITY;
    }
}
