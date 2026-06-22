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

class OrderByNullableLeadingColumnIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        parent::__construct(array_merge([
            'type'        => IssueType::ORDER_BY_NULLABLE_LEADING_COLUMN,
            'title'       => 'ORDER BY On Nullable Leading Column With LIMIT',
            'description' => sprintf(
                'Query on table "%s" orders by nullable column "%s" as the leading sort key and limits the result. A NULL value in that column will be sorted first or last depending on the database engine, silently changing which row is returned.',
                $data['table'] ?? 'N/A',
                $data['column'] ?? 'N/A',
            ),
            'severity' => $data['severity'] ?? 'info',
        ], $data));
    }

    public static function supportedTypes(): array
    {
        return [
            IssueType::ORDER_BY_NULLABLE_LEADING_COLUMN->value,
        ];
    }

    public function getCategory(): IssueCategory
    {
        return IssueCategory::INTEGRITY;
    }
}
