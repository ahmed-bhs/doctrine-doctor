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
 * Issue for entity classes marked as final.
 * Detected by FinalEntityAnalyzer.
 */
class FinalEntityIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        $data['type'] ??= IssueType::FINAL_ENTITY;
        $data['title'] ??= 'Final Entity Class';
        $data['description'] ??= 'Entity class is marked as final, preventing Doctrine proxy creation.';

        parent::__construct($data);
    }

    public function getCategory(): string
    {
        return IssueCategory::INTEGRITY->value;
    }
}
