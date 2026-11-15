<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Concern\MetadataAnalyzerTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\DatabaseAuditAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Compares the database with the migrations and the mapping, when doctrine/migrations is installed:
 * - migrations that exist but were not executed: the database is behind the code;
 * - no migration left to run, yet the mapping differs from the database: a mapping change has no migration.
 */
class MigrationStateAnalyzer implements DatabaseAuditAnalyzerInterface
{
    use MetadataAnalyzerTrait;

    private const int SHOWN_STATEMENTS = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly ?DependencyFactory $dependencyFactory = null,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(function () {
            if (null === $this->dependencyFactory) {
                return;
            }

            $pending = [];
            foreach ($this->dependencyFactory->getMigrationStatusCalculator()->getNewMigrations()->getItems() as $migration) {
                $pending[] = (string) $migration->getVersion();
            }

            if ([] !== $pending) {
                yield $this->pendingIssue($pending);

                return;
            }

            // The entity manager of doctrine/migrations maps every entity, vendor ones included: comparing with
            // fewer entities would read their tables as tables to drop.
            $entityManager = $this->dependencyFactory->hasEntityManager() ? $this->dependencyFactory->getEntityManager() : $this->entityManager;
            $statements = new SchemaTool($entityManager)->getUpdateSchemaSql(
                $entityManager->getMetadataFactory()->getAllMetadata(),
            );
            $storage = $this->dependencyFactory->getConfiguration()->getMetadataStorageConfiguration();
            if ($storage instanceof TableMetadataStorageConfiguration) {
                $table = preg_quote($storage->getTableName(), '/');
                $statements = array_values(array_filter($statements, static fn (string $sql): bool => 1 !== preg_match('/\b' . $table . '\b/i', $sql)));
            }

            if ([] !== $statements) {
                yield $this->driftIssue($statements);
            }
        });
    }

    public function getName(): string
    {
        return 'Migration State Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects migrations not executed and mapping changes that no migration covers';
    }

    /**
     * @param list<string> $versions
     */
    private function pendingIssue(array $versions): IntegrityIssue
    {
        return new IntegrityIssue([
            'type' => IssueType::MIGRATIONS_NOT_EXECUTED->value,
            'title' => 'Migrations Not Executed',
            'description' => sprintf(
                '%d migration(s) exist but were not run on this database: %s. The code expects a schema the database does not have yet.',
                \count($versions),
                implode(', ', \array_slice($versions, 0, self::SHOWN_STATEMENTS)),
            ),
            'severity' => Severity::critical(),
            'suggestion' => $this->suggestionFactory->createFromTemplate(
                templateName: 'Integrity/migration_state',
                context: ['case' => 'pending', 'items' => $versions],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::integrity(),
                    severity: Severity::critical(),
                    title: 'Run the pending migrations',
                    tags: ['migrations', 'schema'],
                ),
            ),
            'queries' => [],
        ]);
    }

    /**
     * @param list<string> $statements
     */
    private function driftIssue(array $statements): IntegrityIssue
    {
        $destructive = array_values(array_filter($statements, static fn (string $sql): bool => 1 === preg_match('/\bDROP\s+(?!INDEX|FOREIGN\s+KEY|CONSTRAINT|PRIMARY\s+KEY)/i', $sql)));
        $shown = \array_slice([] !== $destructive ? $destructive : $statements, 0, self::SHOWN_STATEMENTS);

        $examples = implode('; ', array_map(static fn (string $sql): string => mb_strimwidth($sql, 0, 80, '…'), \array_slice($shown, 0, 2)));
        $description = [] !== $destructive
            ? sprintf('Every migration has run, yet the mapping differs from the database by %d statement(s), %d of which drop a table or a column (%s). A migration generated from this diff deletes that data.', \count($statements), \count($destructive), $examples)
            : sprintf('Every migration has run, yet the mapping differs from the database by %d statement(s) (%s). A mapping change was made without generating its migration.', \count($statements), $examples);

        return new IntegrityIssue([
            'type' => IssueType::MAPPING_WITHOUT_MIGRATION->value,
            'title' => 'Mapping Changed Without a Migration',
            'description' => $description,
            'severity' => [] !== $destructive ? Severity::critical() : Severity::warning(),
            'suggestion' => $this->suggestionFactory->createFromTemplate(
                templateName: 'Integrity/migration_state',
                context: ['case' => 'drift', 'items' => $shown],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::integrity(),
                    severity: Severity::warning(),
                    title: 'Generate the missing migration, then review it',
                    tags: ['migrations', 'schema'],
                ),
            ),
            'queries' => [],
        ]);
    }
}
