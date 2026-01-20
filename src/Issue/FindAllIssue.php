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
        $rowCount = $data['row_count'] ?? 0;

        $data['type']        = IssueType::FIND_ALL;
        $data['title']       = 'Unrestricted findAll() or SELECT without LIMIT';
        $data['description'] = sprintf(
            'Query retrieves all rows from the table without WHERE or LIMIT clause. ' .
            'This could load %d+ rows into memory, causing performance issues and potential out-of-memory errors. ' .
            'Always use pagination or filters for large datasets.',
            $rowCount,
        );

        parent::__construct($data);
    }

    public function getType(): string
    {
        return 'Find All';
    }

    public function getCategory(): string
    {
        return IssueCategory::PERFORMANCE->value;
    }
}
