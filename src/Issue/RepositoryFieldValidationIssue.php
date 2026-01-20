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
 * Issue for invalid fields used in repository method calls.
 * Detected by RepositoryFieldValidationAnalyzer.
 */
class RepositoryFieldValidationIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        $data['type'] ??= IssueType::REPOSITORY_INVALID_FIELD;
        $data['title'] ??= 'Invalid Field in Repository Method';
        $data['description'] ??= 'Repository method called with non-existent field.';

        parent::__construct($data);
    }

    public function getCategory(): string
    {
        return IssueCategory::INTEGRITY->value;
    }
}
