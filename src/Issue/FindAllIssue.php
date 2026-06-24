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

class FindAllIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        $data['type'] = IssueType::FIND_ALL;

        parent::__construct(array_merge([
            'title'       => 'Unrestricted Query: SELECT without WHERE or LIMIT',
            'description' => 'Query retrieves rows from the table without a WHERE or LIMIT clause. ' .
                'This may come from findAll(), a custom repository method, or a hand-built QueryBuilder, and ' .
                'could load an unbounded number of rows into memory, causing performance issues and potential ' .
                'out-of-memory errors. Always use pagination or filters for large datasets.',
        ], $data));
    }

    public static function supportedTypes(): array
    {
        return [
            IssueType::FIND_ALL->value,
        ];
    }

    #[\Override]
    public function getType(): string
    {
        return 'Find All';
    }

    public function getCategory(): IssueCategory
    {
        return IssueCategory::PERFORMANCE;
    }
}
