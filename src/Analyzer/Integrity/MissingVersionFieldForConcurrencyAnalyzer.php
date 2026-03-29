<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Concern\ShortClassNameTrait;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;

class MissingVersionFieldForConcurrencyAnalyzer implements AnalyzerInterface
{
    use ShortClassNameTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(function () use ($queryDataCollection) {
            try {
                $tableMap = $this->buildTableMap();
                $concurrentTables = $this->detectConcurrentTables($queryDataCollection);

                foreach ($concurrentTables as $tableName) {
                    $metadata = $tableMap[$tableName] ?? null;
                    if (null === $metadata) {
                        continue;
                    }

                    if ($metadata->isVersioned) {
                        continue;
                    }

                    yield $this->createIssue($metadata->getName(), $tableName);
                }
            } catch (\Throwable $e) {
                // Swallow exceptions to avoid breaking the profiler
            }
        });
    }

    private function buildTableMap(): array
    {
        $metadataFactory = $this->entityManager->getMetadataFactory();
        $allMetadata = $metadataFactory->getAllMetadata();

        $tableMap = [];
        foreach ($allMetadata as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }

            $tableName = $metadata->getTableName();
            $tableMap[$tableName] = $metadata;
        }

        return $tableMap;
    }

    private function detectConcurrentTables(QueryDataCollection $queryDataCollection): array
    {
        $forUpdateTables = $this->getForUpdateTables($queryDataCollection);
        $highFrequencyTables = $this->getHighFrequencyUpdateTables($queryDataCollection);
        $transactionUpdateTables = $this->getTransactionUpdateTables($queryDataCollection);

        return array_unique(array_merge($forUpdateTables, $highFrequencyTables, $transactionUpdateTables));
    }

    private function getForUpdateTables(QueryDataCollection $queryDataCollection): array
    {
        $tables = [];
        foreach ($queryDataCollection->matchingSql('FOR UPDATE') as $queryData) {
            $sql = $queryData->sql;
            if (preg_match('/FROM\s+(\w+)(?:\s+(?:a|AS\s+\w+|WHERE|LIMIT|FOR))?/i', $sql, $matches)) {
                $tables[] = $matches[1];
            }
        }

        return $tables;
    }

    private function getHighFrequencyUpdateTables(QueryDataCollection $queryDataCollection): array
    {
        $tables = [];
        $updates = $queryDataCollection->onlyUpdates();

        $updatesByTable = [];
        foreach ($updates as $queryData) {
            $sql = $queryData->sql;
            $tableName = $this->extractTableFromUpdate($sql);
            if (null !== $tableName) {
                $updatesByTable[$tableName] = ($updatesByTable[$tableName] ?? 0) + 1;
            }
        }

        foreach ($updatesByTable as $tableName => $count) {
            if ($count >= 2) {
                $tables[] = $tableName;
            }
        }

        return $tables;
    }

    private function getTransactionUpdateTables(QueryDataCollection $queryDataCollection): array
    {
        $hasExplicitTransaction = false;
        foreach ($queryDataCollection->matchingSql('START TRANSACTION') as $_) {
            $hasExplicitTransaction = true;
            break;
        }

        if (!$hasExplicitTransaction) {
            foreach ($queryDataCollection->matchingSql('BEGIN') as $_) {
                $hasExplicitTransaction = true;
                break;
            }
        }

        if (!$hasExplicitTransaction) {
            return [];
        }

        $tables = [];
        foreach ($queryDataCollection->onlyUpdates() as $queryData) {
            $sql = $queryData->sql;
            $tableName = $this->extractTableFromUpdate($sql);
            if (null !== $tableName) {
                $tables[] = $tableName;
            }
        }

        return $tables;
    }

    private function extractTableFromUpdate(string $sql): ?string
    {
        if (preg_match('/UPDATE\s+(\w+)\s+SET/i', $sql, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function createIssue(string $entityClass, string $tableName): IntegrityIssue
    {
        $shortClassName = $this->shortClassName($entityClass);

        return new IntegrityIssue([
            'type' => IssueType::MISSING_VERSION_FIELD_FOR_CONCURRENCY->value,
            'title' => sprintf('%s entity has no version field for concurrency control', $shortClassName),
            'description' => sprintf(
                'The %s entity is involved in concurrent write patterns but lacks an #[ORM\Version] field. '
                . 'Without optimistic locking, concurrent processes may overwrite each other\'s changes (lost updates). '
                . 'Consider adding a version field to detect and prevent concurrency conflicts.',
                $entityClass,
            ),
            'severity' => Severity::warning(),
            'suggestion' => $this->suggestionFactory->createFromTemplate(
                templateName: 'Integrity/missing_version_field_for_concurrency',
                context: [
                    'entity_class' => $entityClass,
                ],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::integrity(),
                    severity: Severity::warning(),
                    title: 'Add #[ORM\Version] field for optimistic locking',
                    tags: ['concurrency', 'locking', 'integrity'],
                ),
            ),
        ]);
    }
}
