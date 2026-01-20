<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\MySQL\Analyzer;

use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\StrictModeAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\DBAL\Connection;

/**
 * MySQL-specific strict mode analyzer.
 * Detects missing SQL modes that ensure data integrity.
 */
final class MySQLStrictModeAnalyzer implements StrictModeAnalyzerInterface
{
    private const RECOMMENDED_SQL_MODES = [
        'STRICT_TRANS_TABLES',
        'NO_ZERO_DATE',
        'NO_ZERO_IN_DATE',
        'ERROR_FOR_DIVISION_BY_ZERO',
        'NO_ENGINE_SUBSTITUTION',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly DatabasePlatformDetector $databasePlatformDetector,
    ) {
    }

    public function analyze(): iterable
    {
        $sqlMode      = $this->getSqlMode();
        $missingModes = $this->getMissingModes($sqlMode);

        if ([] !== $missingModes) {
            yield new DatabaseConfigIssue([
                'title'       => 'Missing SQL Strict Mode Settings',
                'description' => sprintf(
                    'Your database is missing important SQL modes: %s. ' .
                    'These modes prevent silent data truncation and invalid data insertion.',
                    implode(', ', $missingModes),
                ),
                'severity'   => count($missingModes) >= 3 ? Severity::critical() : Severity::warning(),
                'suggestion' => $this->suggestionFactory->createConfiguration(
                    setting: 'sql_mode',
                    currentValue: $sqlMode,
                    recommendedValue: implode(',', self::RECOMMENDED_SQL_MODES),
                    description: 'Add missing modes to prevent data corruption and ensure data integrity',
                    fixCommand: $this->getFixCommand($sqlMode, $missingModes),
                ),
                'backtrace' => null,
                'queries'   => [],
            ]);
        }
    }

    private function getSqlMode(): string
    {
        $result = $this->connection->executeQuery("SHOW VARIABLES LIKE 'sql_mode'");
        $row    = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['Value'] ?? '';
    }

    /**
     * @return array<string>
     */
    private function getMissingModes(string $currentMode): array
    {
        $activeModes = array_map(function ($mode) {
            return trim($mode);
        }, explode(',', strtoupper($currentMode)));
        $missing     = [];

        foreach (self::RECOMMENDED_SQL_MODES as $mode) {
            if (!in_array($mode, $activeModes, true)) {
                $missing[] = $mode;
            }
        }

        return $missing;
    }

    /**
     * @param array<string> $missingModes
     */
    private function getFixCommand(string $currentMode, array $missingModes): string
    {
        $allModes = array_merge(
            array_filter(array_map(function ($mode) {
                return trim($mode);
            }, explode(',', $currentMode)), fn (string $mode): bool => '' !== $mode),
            $missingModes,
        );
        $newMode = implode(',', array_unique($allModes));

        return "-- In your MySQL configuration file (my.cnf or my.ini):\n" .
               "[mysqld]\n" .
               "sql_mode = '{$newMode}'\n\n" .
               "-- Or set it dynamically (session only):\n" .
               "SET SESSION sql_mode = '{$newMode}';\n\n" .
               "-- Or globally (requires SUPER privilege):\n" .
               sprintf("SET GLOBAL sql_mode = '%s';", $newMode);
    }
}
