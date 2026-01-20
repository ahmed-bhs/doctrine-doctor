<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Generator;

use AhmedBhs\DoctrineDoctor\Suggestion\IndexSuggestion;
use Webmozart\Assert\Assert;

class MigrationGenerator
{
    public string $addSql = '$this->addSql';

    /**
     * @param IndexSuggestion[] $indexSuggestions
     */
    public function generateFromSuggestions(array $indexSuggestions): string
    {
        $migrationClass = 'Version' . date('YmdHis');

        $upStatements   = [];
        $downStatements = [];

        Assert::isIterable($indexSuggestions, '$indexSuggestions must be iterable');

        foreach ($indexSuggestions as $indexSuggestion) {
            $indexName = 'IDX_' . strtoupper($indexSuggestion->getTable()) . '_' . implode('_', $indexSuggestion->getColumns());
            $columns   = implode(', ', $indexSuggestion->getColumns());

            $upStatements[]   = sprintf('CREATE INDEX %s ON %s (%s)', $indexName, $indexSuggestion->getTable(), $columns);
            $downStatements[] = sprintf('DROP INDEX %s ON %s', $indexName, $indexSuggestion->getTable());
        }

        return $this->renderMigrationTemplate($migrationClass, $upStatements, $downStatements);
    }

    private function renderMigrationTemplate(string $migrationClass, array $upStatements, array $downStatements): string
    {
        // This is a simplified template. In a real scenario, you would use a proper template engine.
        $upSql   = implode("
        ", array_map(fn ($statement): string => sprintf("%s('%s');", $this->addSql, $statement), $upStatements));
        $downSql = implode("
        ", array_map(fn ($statement): string => sprintf("%s('%s');", $this->addSql, $statement), $downStatements));

        return <<<EOT
            <?php

            declare(strict_types=1);

            namespace DoctrineMigrations;

            use Doctrine\DBAL\Schema\Schema;
            use Doctrine\Migrations\AbstractMigration;

            final class {$migrationClass} extends AbstractMigration
            {
                public function getDescription(): string
                {
                    return 'Automatically generated migration for missing indexes.';
                }

                public function up(Schema \$schema): void
                {
                    // this up() migration is auto-generated, please modify it to your needs
                    {$upSql}
                }

                public function down(Schema \$schema): void
                {
                    // this down() migration is auto-generated, please modify it to your needs
                    {$downSql}
                }
            }
            EOT;
    }
}
