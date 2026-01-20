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

class DQLInjectionIssue extends AbstractIssue
{
    public function __construct(array $data)
    {
        $riskLevel  = $data['risk_level'] ?? 'unknown';
        $indicators = $data['indicators'] ?? [];
        $queryCount = $data['query_count'] ?? 0;

        parent::__construct(array_merge([
            'type'        => IssueType::DQL_INJECTION,
            'title'       => 'Potential SQL/DQL Injection Risk',
            'description' => sprintf(
                'Detected %d %s risk quer%s with potential SQL/DQL injection vulnerabilities. ' .
                'Indicators found: %s. ' .
                'NEVER concatenate user input directly into SQL/DQL queries. ' .
                'Always use parameter binding (:param or ?) to prevent SQL injection attacks. ' .
                'This is a critical security issue that could allow attackers to access, modify, or delete your database.',
                $queryCount,
                $riskLevel,
                $queryCount > 1 ? 'ies' : 'y',
                implode(', ', $indicators),
            ),
            'severity' => $data['severity'] ?? 'critical',
        ], $data));
    }

    public function getCategory(): string
    {
        return IssueCategory::SECURITY->value;
    }
}
