<?php

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Issue;

use AhmedBhs\DoctrineDoctor\ValueObject\IssueCategory;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;

final class MissingTransactionOnBatchIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        $data['type'] = IssueType::MISSING_TRANSACTION_ON_BATCH;

        parent::__construct($data);
    }

    public static function supportedTypes(): array
    {
        return [
            IssueType::MISSING_TRANSACTION_ON_BATCH->value,
        ];
    }

    #[\Override]
    public function getType(): string
    {
        return 'Missing Transaction on Batch';
    }

    public function getCategory(): IssueCategory
    {
        return IssueCategory::PERFORMANCE;
    }
}
