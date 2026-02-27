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
 * Issue for transaction boundary problems.
 * Detected by TransactionBoundaryAnalyzer.
 */
class TransactionIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        // Keep original type from analyzer (transaction_nested, transaction_multiple_flush, etc.)
        $data['type'] ??= IssueType::TRANSACTION_BOUNDARY;
        $data['title'] ??= 'Transaction Boundary Issue';
        $data['description'] ??= 'Transaction management issue detected.';

        parent::__construct($data);
    }

    public static function supportedTypes(): array
    {
        return [
            IssueType::TRANSACTION_BOUNDARY->value,
            IssueType::TRANSACTION_NESTED->value,
            IssueType::TRANSACTION_MULTIPLE_FLUSH->value,
            IssueType::TRANSACTION_UNCLOSED->value,
            IssueType::TRANSACTION_TOO_LONG->value,
        ];
    }

    public function getCategory(): IssueCategory
    {
        return IssueCategory::INTEGRITY;
    }
}
