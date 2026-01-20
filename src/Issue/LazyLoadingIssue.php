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

class LazyLoadingIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        parent::__construct(array_merge([
            'type'        => IssueType::LAZY_LOADING,
            'title'       => 'Lazy Loading Detected in Loop',
            'description' => sprintf(
                'Detected %d lazy loading queries for "%s.%s" relationship. ' .
                'Accessing lazy-loaded relationships inside loops causes N queries to be executed. ' .
                'This is a classic performance anti-pattern that can severely impact application performance. ' .
                'Use eager loading (JOIN with addSelect) to load the relationship data in a single query.',
                $data['query_count'] ?? 0,
                $data['entity'] ?? 'Entity',
                $data['relation'] ?? 'relation',
            ),
            'severity' => $data['severity'] ?? 'warning',
        ], $data));
    }

    public function getType(): string
    {
        return 'Lazy Loading';
    }

    public function getCategory(): string
    {
        return IssueCategory::PERFORMANCE->value;
    }
}
