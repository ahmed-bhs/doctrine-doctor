<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Concern\QueryFieldAccessorTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Detects a text column compared to a numeric literal, such as
 * `WHERE code = 123` on a VARCHAR column. MySQL and MariaDB then cast every
 * row to a number, so the index on the column cannot be used (EXPLAIN shows a
 * full scan); PostgreSQL rejects the comparison outright.
 *
 * The reverse, a numeric column compared to a quoted number (`user_id = '42'`),
 * converts the literal once and keeps the index on all three platforms, so it
 * is not reported. Column types come from the Doctrine metadata: without an
 * entity manager, or for columns it does not map, the analyzer says nothing.
 * Placeholders are ignored, since the bound type is not visible in the SQL.
 */
class ImplicitTypeConversionAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    use QueryFieldAccessorTrait;

    /**
     * @var list<string>
     */
    private const array STRING_TYPES = [
        Types::STRING,
        Types::TEXT,
        Types::ASCII_STRING,
        Types::GUID,
        'enum', // Types::ENUM, added in DBAL 4.2
    ];

    /**
     * Lower-cased table name => lower-cased column name => Doctrine type.
     * @var array<string, array<string, string>>|null
     */
    private ?array $columnTypes = null;

    public function __construct(
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly SqlStructureExtractor $sqlExtractor = new SqlStructureExtractor(),
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () use ($queryDataCollection) {
                if (null === $this->entityManager) {
                    return;
                }

                $seenIssues = [];

                foreach ($queryDataCollection as $query) {
                    $sql = $this->extractSQL($query);
                    if ('' === $sql || !$this->sqlExtractor->isSelectQuery($sql)) {
                        continue;
                    }

                    foreach ($this->detectMismatches($sql) as $mismatch) {
                        $key = $mismatch['table'] . '.' . $mismatch['bare_column'];
                        if (isset($seenIssues[$key])) {
                            continue;
                        }

                        $seenIssues[$key] = true;

                        yield $this->createIssue($mismatch, $sql, $query);
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Implicit Type Conversion Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects text columns compared to numeric literals, which forces a per-row conversion and disables the index';
    }

    /**
     * @return list<array{column: string, bare_column: string, table: string, literal: string, kind: string}>
     */
    private function detectMismatches(string $sql): array
    {
        if (1 !== preg_match('/\bWHERE\b(.*?)(?:\bGROUP\s+BY\b|\bORDER\s+BY\b|\bLIMIT\b|\bHAVING\b|$)/is', $sql, $whereMatches)) {
            return [];
        }

        $pattern = '/(?<![\w.\'"])([a-zA-Z_]\w*(?:\.[a-zA-Z_]\w*)?)\s*(?:=|<>|!=|<=|>=|<|>)\s*(-?\d+(?:\.\d+)?)(?![\w.\'"])/';
        if (0 === preg_match_all($pattern, $whereMatches[1], $allMatches, PREG_SET_ORDER)) {
            return [];
        }

        $tablesByAlias = $this->tablesByAlias($sql);
        $mismatches    = [];

        foreach ($allMatches as $match) {
            $table = $this->findStringColumnTable($match[1], $tablesByAlias);
            if (null === $table) {
                continue;
            }

            $mismatches[] = [
                'column'      => $match[1],
                'bare_column' => strtolower($this->stripAlias($match[1])),
                'table'       => $table,
                'literal'     => $match[2],
                'kind'        => 'string_column_vs_numeric_literal',
            ];
        }

        return $mismatches;
    }

    /**
     * @param array<string, string> $tablesByAlias
     */
    private function findStringColumnTable(string $column, array $tablesByAlias): ?string
    {
        $dotPosition = strrpos($column, '.');
        $candidates  = false === $dotPosition
            ? array_unique(array_values($tablesByAlias))
            : array_filter([$tablesByAlias[strtolower(substr($column, 0, $dotPosition))] ?? null]);
        $bareColumn  = strtolower($this->stripAlias($column));
        $columnTypes = $this->columnTypes();

        foreach ($candidates as $table) {
            $type = $columnTypes[$table][$bareColumn] ?? null;
            if (null !== $type && in_array($type, self::STRING_TYPES, true)) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> lower-cased alias (or table name) => lower-cased table name
     */
    private function tablesByAlias(string $sql): array
    {
        $tablesByAlias = [];

        foreach ($this->sqlExtractor->extractAllTables($sql) as $table) {
            $name                 = strtolower(trim($table['table'], '`"'));
            $tablesByAlias[$name] = $name;

            if (null !== $table['alias'] && '' !== $table['alias']) {
                $tablesByAlias[strtolower($table['alias'])] = $name;
            }
        }

        return $tablesByAlias;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function columnTypes(): array
    {
        if (null !== $this->columnTypes) {
            return $this->columnTypes;
        }

        $this->columnTypes = [];

        if (null === $this->entityManager) {
            return $this->columnTypes;
        }

        try {
            $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        } catch (\Throwable) {
            return $this->columnTypes;
        }

        foreach ($allMetadata as $classMetadata) {
            $table = strtolower($classMetadata->getTableName());

            foreach ($classMetadata->getFieldNames() as $fieldName) {
                $type = $classMetadata->getTypeOfField($fieldName);
                if (null !== $type) {
                    $this->columnTypes[$table][strtolower($classMetadata->getColumnName($fieldName))] = $type;
                }
            }
        }

        return $this->columnTypes;
    }

    private function stripAlias(string $column): string
    {
        $dotPosition = strrpos($column, '.');
        if (false === $dotPosition) {
            return $column;
        }

        return substr($column, $dotPosition + 1);
    }

    /**
     * @param array{column: string, bare_column: string, table: string, literal: string, kind: string} $mismatch
     */
    private function createIssue(array $mismatch, string $sql, array|object $query): PerformanceIssue
    {
        $description = sprintf(
            'Text column %s is compared to the number %s. MySQL and MariaDB convert the column value of every row ' .
            'to a number before comparing, so the index on %s cannot be used and the query runs a full scan; ' .
            'PostgreSQL rejects the comparison. Compare it to a string instead.',
            $mismatch['column'],
            $mismatch['literal'],
            $mismatch['column'],
        );

        $title = sprintf('String Column %s Compared to Numeric Literal', $mismatch['column']);

        $issueData = new IssueData(
            type: 'implicit_type_conversion',
            title: $title,
            description: $description,
            severity: Severity::warning(),
            suggestion: $this->createSuggestion($mismatch, $sql),
            queries: [$query], // @phpstan-ignore argument.type
            backtrace: $this->extractBacktrace($query),
        );

        return new PerformanceIssue($issueData->toArray());
    }

    /**
     * @param array{column: string, bare_column: string, table: string, literal: string, kind: string} $mismatch
     */
    private function createSuggestion(array $mismatch, string $sql): mixed
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Performance/implicit_type_conversion',
            context: [
                'column' => $mismatch['column'],
                'literal' => $mismatch['literal'],
                'kind' => $mismatch['kind'],
                'original_query' => $sql,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::warning(),
                title: 'Compare text columns to strings',
                tags: ['performance', 'index', 'type-conversion', 'optimization'],
            ),
        );
    }
}
