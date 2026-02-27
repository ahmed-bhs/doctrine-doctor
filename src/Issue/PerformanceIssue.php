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
 * Represents a performance issue detected by Doctrine Doctor.
 * Performance issues are patterns that negatively impact query performance,
 * database efficiency, or application response time.
 */
class PerformanceIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        parent::__construct(array_merge([
            'type'        => IssueType::PERFORMANCE,
            'title'       => 'Performance Issue',
            'description' => 'Performance can be improved.',
            'severity'    => $data['severity'] ?? 'warning',
        ], $data));
    }

    public static function supportedTypes(): array
    {
        return [
            IssueType::PERFORMANCE->value,
            'setMaxResults_with_collection_join',
            'setMaxResults with Collection Join',
            IssueType::CARTESIAN_PRODUCT->value,
            'Cartesian Product',
            IssueType::CARTESIAN_PRODUCT_RISK->value,
            'Cartesian Product Risk',
            IssueType::UNUSED_EAGER_LOAD->value,
            'Unused Eager Load',
            IssueType::AGGREGATION_WITH_INNER_JOIN->value,
        ];
    }

    public function getCategory(): IssueCategory
    {
        return IssueCategory::PERFORMANCE;
    }
}
