<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Issue;

use AhmedBhs\DoctrineDoctor\Translation\Translator;
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
                '%d %s risk quer%s show signs of SQL/DQL injection (%s). ' .
                'Input concatenated into a query can change its meaning and read or modify any data.',
                $queryCount,
                self::riskLevelLabel((string) $riskLevel),
                $queryCount > 1 ? 'ies' : 'y',
                implode(', ', $indicators),
            ),
            'severity' => $data['severity'] ?? 'critical',
        ], $data));
    }

    /**
     * The risk level as shown in the description: its own catalog entry, since "warning" reads as a severity elsewhere.
     */
    private static function riskLevelLabel(string $riskLevel): string
    {
        $label = Translator::trans('risk level: ' . $riskLevel);

        return str_starts_with($label, 'risk level: ') ? $riskLevel : $label;
    }

    #[\Override]
    public static function supportedTypes(): array
    {
        return [
            IssueType::DQL_INJECTION->value,
            IssueType::QUERY_BUILDER_SQL_INJECTION->value,
            IssueType::UNESCAPED_LIKE->value,
            IssueType::INCORRECT_NULL_COMPARISON->value,
            IssueType::EMPTY_IN_CLAUSE->value,
            IssueType::MISSING_PARAMETERS->value,
        ];
    }

    public function getCategory(): IssueCategory
    {
        return IssueCategory::SECURITY;
    }
}
