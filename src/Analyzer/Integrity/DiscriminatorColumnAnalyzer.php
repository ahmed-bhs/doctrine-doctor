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
use AhmedBhs\DoctrineDoctor\Analyzer\Concern\ShortClassNameTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Detects two misconfigurations of the discriminator column in inheritance hierarchies.
 *
 * Doctrine already throws on a missing DiscriminatorMap entry and on invalid enum
 * entries, so those cases are not re-reported here. The two checks below are not
 * validated by the ORM and fail silently at runtime:
 *
 * 1. The discriminator column is too short for the longest map key, which truncates
 *    the value on write and makes rows unloadable.
 * 2. Single Table Inheritance without an index on the discriminator column, which
 *    forces a full table scan for every subclass query.
 */
class DiscriminatorColumnAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;
    use ShortClassNameTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(function () {
            try {
                /** @var array<ClassMetadata<object>> $allMetadata */
                $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
            } catch (\Throwable) {
                return;
            }

            foreach ($allMetadata as $metadata) {
                if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                    continue;
                }

                if (!$metadata->isRootEntity()) {
                    continue;
                }

                if (ClassMetadata::INHERITANCE_TYPE_NONE === $metadata->inheritanceType) {
                    continue;
                }

                if (null === $metadata->discriminatorColumn || [] === $metadata->discriminatorMap) {
                    continue;
                }

                yield from $this->analyzeColumnLength($metadata);
                yield from $this->analyzeMissingIndex($metadata);
            }
        });
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @return iterable<IntegrityIssue>
     */
    private function analyzeColumnLength(ClassMetadata $metadata): iterable
    {
        $column = $metadata->discriminatorColumn;

        if (null === $column || 'string' !== $column->type) {
            return;
        }

        $length = $column->length;

        if (null === $length || $length <= 0) {
            return;
        }

        $longestKey = '';
        foreach (array_keys($metadata->discriminatorMap) as $key) {
            $key = (string) $key;
            if (mb_strlen($key) > mb_strlen($longestKey)) {
                $longestKey = $key;
            }
        }

        $longestLength = mb_strlen($longestKey);

        if ($longestLength <= $length) {
            return;
        }

        $rootClass = $this->shortClassName($metadata->getName());

        $description = DescriptionHighlighter::highlight(
            'Discriminator column {column} on {root} has length {length}, '
            . 'but the longest discriminator value {value} needs {needed} characters. '
            . 'The value is truncated on write, so rows of that subtype can no longer be '
            . 'matched back to their class and hydration fails.',
            [
                'column' => $column->name,
                'root'   => $rootClass,
                'length' => (string) $length,
                'value'  => $longestKey,
                'needed' => (string) $longestLength,
            ],
        );

        yield new IntegrityIssue(new IssueData(
            type: IssueType::DISCRIMINATOR_COLUMN_TOO_SHORT->value,
            title: sprintf('Discriminator column too short: %s::$%s', $rootClass, $column->name),
            description: $description,
            severity: Severity::critical(),
            suggestion: $this->suggestionFactory->createFromTemplate(
                templateName: 'Integrity/discriminator_column_too_short',
                context: [
                    'root_class'     => $rootClass,
                    'column_name'    => $column->name,
                    'current_length' => $length,
                    'longest_value'  => $longestKey,
                    'needed_length'  => $longestLength,
                ],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::integrity(),
                    severity: Severity::critical(),
                    title: 'Widen the discriminator column',
                    tags: ['inheritance', 'schema'],
                ),
            ),
        )->toArray());
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @return iterable<IntegrityIssue>
     */
    private function analyzeMissingIndex(ClassMetadata $metadata): iterable
    {
        if (ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE !== $metadata->inheritanceType) {
            return;
        }

        $column = $metadata->discriminatorColumn;

        if (null === $column) {
            return;
        }

        if ($this->hasIndexOn($metadata, $column->name)) {
            return;
        }

        $rootClass = $this->shortClassName($metadata->getName());
        $subtypeCount = count($metadata->discriminatorMap);

        $description = DescriptionHighlighter::highlight(
            'Single Table Inheritance on {root} stores {count} subtypes in one table, '
            . 'but the discriminator column {column} has no index. '
            . 'Every query on a subclass appends a filter on {column} and scans the whole '
            . 'table to discard the other subtypes.',
            [
                'root'   => $rootClass,
                'count'  => (string) $subtypeCount,
                'column' => $column->name,
            ],
        );

        yield new IntegrityIssue(new IssueData(
            type: IssueType::DISCRIMINATOR_COLUMN_NOT_INDEXED->value,
            title: sprintf('Unindexed discriminator column: %s::$%s', $rootClass, $column->name),
            description: $description,
            severity: Severity::warning(),
            suggestion: $this->suggestionFactory->createFromTemplate(
                templateName: 'Integrity/discriminator_column_not_indexed',
                context: [
                    'root_class'    => $rootClass,
                    'column_name'   => $column->name,
                    'table_name'    => $metadata->getTableName(),
                    'subtype_count' => $subtypeCount,
                ],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::performance(),
                    severity: Severity::warning(),
                    title: 'Index the discriminator column',
                    tags: ['inheritance', 'index'],
                ),
            ),
        )->toArray());
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function hasIndexOn(ClassMetadata $metadata, string $columnName): bool
    {
        $table = $metadata->table;

        foreach (['indexes', 'uniqueConstraints'] as $key) {
            /** @var array<string, array{columns?: list<string>}> $definitions */
            $definitions = $table[$key] ?? [];

            foreach ($definitions as $definition) {
                $columns = $definition['columns'] ?? [];

                if ([] === $columns) {
                    continue;
                }

                if ($columnName === $columns[0]) {
                    return true;
                }
            }
        }

        return false;
    }
}
