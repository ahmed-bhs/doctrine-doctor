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
                '%d lazy loading queries ran for the "%s.%s" relationship. ' .
                'Each access to the relation in the loop costs one more query.',
                $data['query_count'] ?? 0,
                $data['entity'] ?? 'Entity',
                $data['relation'] ?? 'relation',
            ),
            'severity' => $data['severity'] ?? 'warning',
        ], $data));
    }

    #[\Override]
    public static function supportedTypes(): array
    {
        return [
            IssueType::LAZY_LOADING->value,
        ];
    }

    #[\Override]
    public function getType(): string
    {
        return IssueType::LAZY_LOADING->value;
    }

    public function getCategory(): IssueCategory
    {
        return IssueCategory::PERFORMANCE;
    }
}
