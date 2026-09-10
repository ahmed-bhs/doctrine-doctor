<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\MySQL\Analyzer;

use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\Interface\CollationAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Issue\DatabaseConfigIssue;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\DBAL\Connection;

/**
 * MySQL-specific collation analyzer.
 * Detects issues with collation configuration and mismatches.
 */
final readonly class MySQLCollationAnalyzer implements CollationAnalyzerInterface
{
    private const string RECOMMENDED_COLLATION = 'utf8mb4_unicode_ci';

    private const array SUBOPTIMAL_COLLATIONS = [
        'utf8mb4_general_ci',
        'utf8_general_ci',
        'utf8_unicode_ci',
    ];

    public function __construct(
        private Connection $connection,
        private SuggestionFactoryInterface $suggestionFactory,
        private DatabasePlatformDetector $databasePlatformDetector,
    ) {
    }

    public function analyze(): iterable
    {
        $databaseName = $this->connection->getDatabase();

        if (null === $databaseName) {
            return;
        }

        $dbCollation  = $this->getDatabaseCollation($databaseName);

        // Issue 1: Check database collation
        if ($this->isSuboptimalCollation($dbCollation)) {
            yield $this->createSuboptimalCollationIssue($databaseName, $dbCollation, 'database');
        }

        // Issue 2: Check tables with different collations
        $diffCollationTables = $this->getTablesWithDifferentCollation($databaseName, $dbCollation);

        if ([] !== $diffCollationTables) {
            yield $this->createCollationMismatchIssue($diffCollationTables, $dbCollation);
        }

        // Issue 3: Check for collation mismatches in foreign key columns
        $fkCollationMismatches = $this->getForeignKeyCollationMismatches($databaseName);

        if ([] !== $fkCollationMismatches) {
            yield $this->createForeignKeyCollationIssue($fkCollationMismatches);
        }

        // Issue 4: Check for view columns incompatible with the connection collation
        $viewCollationMismatches = $this->getViewCollationMismatches($databaseName);

        if ([] !== $viewCollationMismatches) {
            yield $this->createViewCollationIssue($viewCollationMismatches);
        }
    }

    private function getDatabaseCollation(string $databaseName): string
    {
        $result = $this->connection->executeQuery(
            'SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$databaseName],
        );

        $row = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['DEFAULT_COLLATION_NAME'] ?? 'unknown';
    }

    private function isSuboptimalCollation(string $collation): bool
    {
        return in_array($collation, self::SUBOPTIMAL_COLLATIONS, true);
    }

    /**
     * Detects view columns whose collation differs from the connection collation
     * within the same character set.
     *
     * A CREATE VIEW statement freezes the session collation into the literals of
     * its definition. When the application later queries such a column with a
     * different collation, MySQL cannot arbitrate between two operands of equal
     * coercibility and raises error 1267 (Illegal mix of collations).
     *
     * Only same-charset differences are reported: across character sets MySQL
     * converts implicitly, which never raises 1267.
     *
     * @return array<mixed>
     */
    private function getViewCollationMismatches(string $databaseName): array
    {
        $result = $this->connection->executeQuery(
            'SELECT c.TABLE_NAME, c.COLUMN_NAME, c.COLLATION_NAME, c.CHARACTER_SET_NAME
             FROM information_schema.COLUMNS c
             INNER JOIN information_schema.VIEWS v
                 ON v.TABLE_SCHEMA = c.TABLE_SCHEMA
                 AND v.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = ?
             AND c.COLLATION_NAME IS NOT NULL
             AND c.CHARACTER_SET_NAME = SUBSTRING_INDEX(@@collation_connection, \'_\', 1)
             AND c.COLLATION_NAME != @@collation_connection',
            [$databaseName],
        );

        return $this->databasePlatformDetector->fetchAllAssociative($result);
    }

    /**
     * @return array<mixed>
     */
    private function getTablesWithDifferentCollation(string $databaseName, string $dbCollation): array
    {
        $result = $this->connection->executeQuery(
            "SELECT TABLE_NAME, TABLE_COLLATION
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ?
             AND TABLE_COLLATION != ?
             AND TABLE_TYPE = 'BASE TABLE'",
            [$databaseName, $dbCollation],
        );

        return $this->databasePlatformDetector->fetchAllAssociative($result);
    }

    /**
     * @return array<mixed>
     */
    private function getForeignKeyCollationMismatches(string $databaseName): array
    {
        $query = <<<SQL
            SELECT
                kcu.TABLE_NAME as child_table,
                kcu.COLUMN_NAME as child_column,
                col1.COLLATION_NAME as child_collation,
                kcu.REFERENCED_TABLE_NAME as parent_table,
                kcu.REFERENCED_COLUMN_NAME as parent_column,
                col2.COLLATION_NAME as parent_collation
            FROM information_schema.KEY_COLUMN_USAGE kcu
            JOIN information_schema.COLUMNS col1
                ON kcu.TABLE_SCHEMA = col1.TABLE_SCHEMA
                AND kcu.TABLE_NAME = col1.TABLE_NAME
                AND kcu.COLUMN_NAME = col1.COLUMN_NAME
            JOIN information_schema.COLUMNS col2
                ON kcu.REFERENCED_TABLE_SCHEMA = col2.TABLE_SCHEMA
                AND kcu.REFERENCED_TABLE_NAME = col2.TABLE_NAME
                AND kcu.REFERENCED_COLUMN_NAME = col2.COLUMN_NAME
            WHERE kcu.TABLE_SCHEMA = ?
            AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            AND col1.COLLATION_NAME IS NOT NULL
            AND col2.COLLATION_NAME IS NOT NULL
            AND col1.COLLATION_NAME != col2.COLLATION_NAME
            SQL;

        $result = $this->connection->executeQuery($query, [$databaseName]);

        return $this->databasePlatformDetector->fetchAllAssociative($result);
    }

    private function createSuboptimalCollationIssue(string $databaseName, string $currentCollation, string $level): DatabaseConfigIssue
    {
        $description = sprintf(
            '%s "%s" is using collation "%s". ' .
            'This is a valid choice with trade-offs: ' .
            'utf8mb4_general_ci is **faster** but less accurate for Unicode sorting (e.g., "ä" vs "a"). ' .
            'utf8mb4_unicode_ci is more accurate for multilingual sorting but slightly slower. ' .
            'utf8mb4_0900_ai_ci (MySQL 8.0+) offers best of both worlds.',
            ucfirst($level),
            $databaseName,
            $currentCollation,
        );

        $fixCommand = 'database' === $level
            ? sprintf('ALTER DATABASE `%s` COLLATE = ', $databaseName) . self::RECOMMENDED_COLLATION . ';'
            : sprintf('ALTER TABLE `%s` COLLATE = ', $databaseName) . self::RECOMMENDED_COLLATION . ';';

        return new DatabaseConfigIssue([
            'title'       => sprintf('%s using collation: %s (performance vs accuracy trade-off)', ucfirst($level), $currentCollation),
            'description' => $description,
            'severity' => Severity::info(), // INFO instead of WARNING (it's a valid choice)
            'suggestion'  => $this->suggestionFactory->createFromTemplate(
                templateName: 'Configuration/configuration',
                context: [
                    'setting' => ucfirst($level) . ' collation',
                    'current_value' => $currentCollation,
                    'recommended_value' => self::RECOMMENDED_COLLATION,
                    'description' => 'utf8mb4_unicode_ci provides accurate Unicode sorting. Only change if multilingual sorting is important.',
                    'fix_command' => $fixCommand,
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

    /**
     * @param array<mixed> $tables
     */
    private function createCollationMismatchIssue(array $tables, string $dbCollation): DatabaseConfigIssue
    {
        $tableList     = array_slice($tables, 0, 5);
        $tableNames    = array_map(fn (array $table): string => sprintf('%s (%s)', $table['TABLE_NAME'], $table['TABLE_COLLATION']), $tableList);
        $tableNamesStr = implode(', ', $tableNames);

        if (count($tables) > 5) {
            $tableNamesStr .= sprintf(' (and %d more)', count($tables) - 5);
        }

        // Check if all tables use the SAME collation (homogeneous but different from DB)
        $uniqueCollations = array_unique(array_column($tables, 'TABLE_COLLATION'));
        $isHomogeneous = 1 === count($uniqueCollations);
        $commonCollation = $isHomogeneous ? reset($uniqueCollations) : null;

        $fixCommands = [];

        foreach (array_slice($tables, 0, 10) as $table) {
            $fixCommands[] = sprintf(
                'ALTER TABLE `%s` COLLATE = %s;',
                $table['TABLE_NAME'],
                $dbCollation,
            );
        }

        if (count($tables) > 10) {
            $fixCommands[] = '-- ... and ' . (count($tables) - 10) . ' more tables';
        }

        // Determine severity and description based on homogeneity
        if ($isHomogeneous) {
            // All tables use the same collation (intentional)
            $severity = 'info';
            $description = sprintf(
                'Found %d tables ALL using collation "%s" while database default is "%s". ' .
                'This appears to be intentional (consistent). Only problematic if JOINing with tables using "%s".',
                count($tables),
                $commonCollation,
                $dbCollation,
                $dbCollation,
            );
        } else {
            // Mixed collations (real problem)
            $severity = count($tables) > 10 ? Severity::warning() : Severity::info();
            $description = sprintf(
                'Found %d tables with MIXED collations different from database default (%s): %s. ' .
                'This can cause performance issues in JOINs and unexpected sorting behavior.',
                count($tables),
                $dbCollation,
                $tableNamesStr,
            );
        }

        return new DatabaseConfigIssue([
            'title'       => sprintf('%d tables with different collation than database', count($tables)),
            'description' => $description,
            'severity'   => $severity,
            'suggestion' => $this->suggestionFactory->createFromTemplate(
                templateName: 'Configuration/configuration',
                context: [
                    'setting' => 'Table collations',
                    'current_value' => $isHomogeneous ? $commonCollation : 'Mixed collations',
                    'recommended_value' => $dbCollation,
                    'description' => $isHomogeneous
                    ? 'Tables use consistent collation, only different from database default'
                    : 'Unify table collations to match database default for consistent behavior',
                    'fix_command' => implode("\n", $fixCommands),
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

    /**
     * @param array<mixed> $mismatches
     */
    private function createViewCollationIssue(array $mismatches): DatabaseConfigIssue
    {
        $mismatchList = array_slice($mismatches, 0, 5);
        $descriptions = array_map(
            fn (array $mismatch): string => sprintf(
                '%s.%s (%s)',
                $mismatch['TABLE_NAME'],
                $mismatch['COLUMN_NAME'],
                $mismatch['COLLATION_NAME'],
            ),
            $mismatchList,
        );
        $descriptionStr = implode("\n- ", $descriptions);

        if (count($mismatches) > 5) {
            $descriptionStr .= sprintf("\n- ... and %d more", count($mismatches) - 5);
        }

        $affectedViews = array_unique(array_column($mismatches, 'TABLE_NAME'));

        $fixCommands = [
            '-- The view definition froze the session collation into its literals.',
            '-- Recreate each view from a connection using the application collation,',
            '-- or pin the literals explicitly so the view no longer depends on it:',
            '--   IF(access = \'pr\',',
            '--      _utf8mb4\'Prive\'  COLLATE utf8mb4_unicode_ci,',
            '--      _utf8mb4\'Public\' COLLATE utf8mb4_unicode_ci) AS access',
        ];

        foreach (array_slice($affectedViews, 0, 5) as $viewName) {
            $fixCommands[] = sprintf('SHOW CREATE VIEW `%s`;', $viewName);
        }

        return new DatabaseConfigIssue([
            'title'       => sprintf('%d view columns incompatible with connection collation', count($mismatches)),
            'description' => sprintf(
                'Found %d view columns whose collation differs from the connection collation (%s) ' .
                'within the same character set. A CREATE VIEW statement freezes the session collation ' .
                'into the literals of its definition, so comparing such a column against a literal ' .
                'raises error 1267 (Illegal mix of collations) — both operands share the same ' .
                'coercibility and MySQL cannot arbitrate:' . "\n- " . $descriptionStr,
                count($mismatches),
                $this->getConnectionCollation(),
            ),
            'severity' => Severity::critical(),
            'suggestion' => $this->suggestionFactory->createFromTemplate(
                templateName: 'Configuration/configuration',
                context: [
                    'setting' => 'View collations',
                    'current_value' => 'Mismatched collations',
                    'recommended_value' => $this->getConnectionCollation(),
                    'description' => 'Views must be created with the same collation the application connects with. '
                        . 'Pinning literals with an explicit COLLATE gives them a higher coercibility, '
                        . 'making the view independent from the session that creates it.',
                    'fix_command' => implode("\n", $fixCommands),
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

    private function getConnectionCollation(): string
    {
        $result = $this->connection->executeQuery('SELECT @@collation_connection AS collation_connection');

        $row = $this->databasePlatformDetector->fetchAssociative($result);

        return $row['collation_connection'] ?? 'unknown';
    }

    /**
     * @param array<mixed> $mismatches
     */
    private function createForeignKeyCollationIssue(array $mismatches): DatabaseConfigIssue
    {
        $mismatchList = array_slice($mismatches, 0, 3);
        $descriptions = array_map(
            fn (array $mismatch): string => sprintf(
                '%s.%s (%s) -> %s.%s (%s)',
                $mismatch['child_table'],
                $mismatch['child_column'],
                $mismatch['child_collation'],
                $mismatch['parent_table'],
                $mismatch['parent_column'],
                $mismatch['parent_collation'],
            ),
            $mismatchList,
        );
        $descriptionStr = implode("\n- ", $descriptions);

        if (count($mismatches) > 3) {
            $descriptionStr .= sprintf("\n- ... and %d more", count($mismatches) - 3);
        }

        $fixCommands = [];

        foreach (array_slice($mismatches, 0, 5) as $mismatch) {
            $fixCommands[] = sprintf(
                'ALTER TABLE `%s` MODIFY COLUMN `%s` VARCHAR(255) COLLATE %s; -- Match parent table',
                $mismatch['child_table'],
                $mismatch['child_column'],
                $mismatch['parent_collation'],
            );
        }

        if (count($mismatches) > 5) {
            $fixCommands[] = '-- ... and ' . (count($mismatches) - 5) . ' more columns';
        }

        return new DatabaseConfigIssue([
            'title'       => sprintf('%d foreign key collation mismatches detected', count($mismatches)),
            'description' => sprintf(
                'Found %d foreign key relationships where child and parent columns have different collations. ' .
                'This prevents index usage in JOINs and causes severe performance degradation:' . "\n- " . $descriptionStr,
                count($mismatches),
            ),
            'severity' => Severity::critical(),
            'suggestion' => $this->suggestionFactory->createFromTemplate(
                templateName: 'Configuration/configuration',
                context: [
                    'setting' => 'Foreign key collations',
                    'current_value' => 'Mismatched collations',
                    'recommended_value' => 'Matching collations',
                    'description' => 'Foreign key columns MUST have the same collation as their referenced columns for optimal JOIN performance',
                    'fix_command' => implode("\n", $fixCommands),
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
