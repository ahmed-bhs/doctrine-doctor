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
 * Issue for unsafe access to potentially empty Doctrine Collections.
 * Detected by CollectionEmptyAccessAnalyzer.
 */
class CollectionEmptyAccessIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        $data['type'] ??= IssueType::COLLECTION_EMPTY_ACCESS;
        $data['title'] ??= 'Unsafe Collection Access';
        $data['description'] ??= 'Collection accessed without checking if empty.';

        parent::__construct($data);
    }

    public function getCategory(): string
    {
        return IssueCategory::INTEGRITY->value;
    }
}
