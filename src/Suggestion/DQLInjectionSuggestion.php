<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Suggestion;

use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

class DQLInjectionSuggestion implements SuggestionInterface
{
    private readonly string $riskLevel;

    private readonly array $indicators;

    private readonly string $example;

    public function __construct(array $data)
    {
        $this->riskLevel  = $data['risk_level'] ?? 'unknown';
        $this->indicators = $data['indicators'] ?? [];
        $this->example    = $this->generateExample();
    }

    public function getCode(): string
    {
        return $this->example;
    }

    public function getDescription(): string
    {
        return sprintf(
            '%s risk of SQL injection: user input is concatenated into DQL. ' .
            'Bind it with setParameter() instead.',
            ucfirst($this->riskLevel),
        );
    }

    public function getMetadata(): SuggestionMetadata
    {
        return new SuggestionMetadata(
            type: SuggestionType::SECURITY,
            severity: Severity::CRITICAL,
            title: 'DQL Injection Prevention',
            tags: ['security', 'sql-injection'],
        );
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'class'      => static::class,
            'risk_level' => $this->riskLevel,
            'indicators' => $this->indicators,
            'example'    => $this->example,
        ];
    }

    private function generateExample(): string
    {
        return <<<'CODE'
            // Before: user input concatenated into DQL
            $dql = "SELECT u FROM User u WHERE u.name = '" . $name . "'";

            // After: bound parameter
            $users = $em->createQuery('SELECT u FROM User u WHERE u.name = :name')
                ->setParameter('name', $name)
                ->getResult();

            // Identifiers (ORDER BY field) cannot be bound: check them against a whitelist.
            $sort = in_array($sort, ['name', 'email', 'createdAt'], true) ? $sort : 'name';
            CODE;
    }
}
