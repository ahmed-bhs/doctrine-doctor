<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\PostgreSQL\Analyzer;

use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\StrictModeAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\DBAL\Connection;

/**
 * PostgreSQL-specific strict mode analyzer.
 * Detects issues with standard_conforming_strings and check_function_bodies.
 */
final readonly class PostgreSQLStrictModeAnalyzer implements StrictModeAnalyzerInterface
{
    public function __construct(
        private Connection $connection,
        private SuggestionFactoryInterface $suggestionFactory,
        private DatabasePlatformDetector $databasePlatformDetector,
    ) {
    }

    public function analyze(): iterable
    {
        $standardConformingStrings = $this->getStandardConformingStrings();
        $checkFunctionBodies       = $this->getCheckFunctionBodies();

        // Issue 1: standard_conforming_strings = off (old, dangerous behavior)
        if ('off' === $standardConformingStrings) {
            yield new DatabaseConfigIssue([
                'title'       => 'Non-standard string escaping enabled (security risk)',
                'description' => 'standard_conforming_strings is OFF. ' .
                    'This enables legacy backslash escaping which can cause SQL injection vulnerabilities. ' .
                    'PostgreSQL (9.1+) uses standard-compliant string escaping by default.',
                'severity'   => 'critical',
                'suggestion' => $this->suggestionFactory->createFromTemplate(
                    templateName: 'Configuration/configuration',
                    context: [
                        'setting' => 'standard_conforming_strings',
                        'current_value' => 'off',
                        'recommended_value' => 'on',
                        'description' => 'Enable standard-compliant string escaping for security',
                        'fix_command' => "-- In postgresql.conf:\nstandard_conforming_strings = on\n\n-- Or set globally:\nALTER DATABASE your_db SET standard_conforming_strings = on;",
                    ],
                    suggestionMetadata: new SuggestionMetadata(
                        type: SuggestionType::configuration(),
                        severity: Severity::info(),
                        title: 'Configuration Issue',
                        tags: ['configuration', 'settings'],
                    ),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }

        // Issue 2: check_function_bodies = off (skips validation)
        if ('off' === $checkFunctionBodies) {
            yield new DatabaseConfigIssue([
                'title'       => 'Function body validation disabled',
                'description' => 'check_function_bodies is OFF. ' .
                    'This skips validation when creating functions, allowing invalid code to be stored. ' .
                    'Recommended to enable for catching errors early.',
                'severity'   => 'warning',
                'suggestion' => $this->suggestionFactory->createFromTemplate(
                    templateName: 'Configuration/configuration',
                    context: [
                        'setting' => 'check_function_bodies',
                        'current_value' => 'off',
                        'recommended_value' => 'on',
                        'description' => 'Enable function validation to catch errors during creation',
                        'fix_command' => "-- In postgresql.conf:\ncheck_function_bodies = on\n\n-- Or set globally:\nALTER DATABASE your_db SET check_function_bodies = on;",
                    ],
                    suggestionMetadata: new SuggestionMetadata(
                        type: SuggestionType::configuration(),
                        severity: Severity::info(),
                        title: 'Configuration Issue',
                        tags: ['configuration', 'settings'],
                    ),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }
    }

    private function getStandardConformingStrings(): string
    {
        $result = $this->connection->executeQuery('SHOW standard_conforming_strings');
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['standard_conforming_strings'] ?? 'on';
    }

    private function getCheckFunctionBodies(): string
    {
        $result = $this->connection->executeQuery('SHOW check_function_bodies');
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['check_function_bodies'] ?? 'on';
    }
}
