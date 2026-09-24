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
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\DBAL\ParameterType;
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
 * A positional parameter counts when the query was bound with an integer type,
 * which DQL infers from a PHP int: `setParameter('code', 123)` on a text column.
 * `findBy()` binds the mapped type and is not reported.
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

                    foreach ($this->detectMismatches($sql, $this->extractTypes($query)) as $mismatch) {
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
        return 'Detects text columns compared to numeric literals or integer parameters, which forces a per-row conversion and disables the index';
    }

    /**
     * @param array<mixed> $types binding type of each positional parameter
     * @return list<array{column: string, bare_column: string, table: string, literal: string, kind: string}>
     */
    private function detectMismatches(string $sql, array $types): array
    {
        if (1 !== preg_match('/\bWHERE\b(.*?)(?:\bGROUP\s+BY\b|\bORDER\s+BY\b|\bLIMIT\b|\bHAVING\b|$)/is', $sql, $whereMatches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        [$whereClause, $whereOffset] = $whereMatches[1];

        $pattern = '/(?<![\w.\'"])([a-zA-Z_]\w*(?:\.[a-zA-Z_]\w*)?)\s*(?:=|<>|!=|<=|>=|<|>)\s*(?:(-?\d+(?:\.\d+)?)(?![\w.\'"])|(\?))/';
        if (0 === preg_match_all($pattern, $whereClause, $allMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $tablesByAlias = $this->tablesByAlias($sql);
        $mismatches    = [];

        foreach ($allMatches as $match) {
            $column = $match[1][0];

            if (isset($match[2]) && -1 !== $match[2][1]) {
                $kind    = 'string_column_vs_numeric_literal';
                $literal = $match[2][0];
            } elseif (isset($match[3])) {
                // DQL infers the binding type from the PHP value: an int is bound as an integer.
                $parameterIndex = substr_count(substr($sql, 0, $whereOffset + $match[3][1]), '?');
                if (!$this->isIntegerBinding($types[$parameterIndex] ?? null)) {
                    continue;
                }

                $kind    = 'string_column_vs_integer_parameter';
                $literal = 'parameter #' . ($parameterIndex + 1);
            } else {
                continue;
            }

            $table = $this->findStringColumnTable($column, $tablesByAlias);
            if (null === $table) {
                continue;
            }

            $mismatches[] = [
                'column'      => $column,
                'bare_column' => strtolower($this->stripAlias($column)),
                'table'       => $table,
                'literal'     => $literal,
                'kind'        => $kind,
            ];
        }

        return $mismatches;
    }

    private function isIntegerBinding(mixed $type): bool
    {
        // QueryData keeps the ParameterType case name on DBAL 4 and the int constant (PDO::PARAM_INT) on DBAL 3.
        return 'INTEGER' === $type || ParameterType::INTEGER === $type || \PDO::PARAM_INT === $type;
    }

    /**
     * @param array<mixed>|object $query
     * @return array<mixed>
     */
    private function extractTypes(array|object $query): array
    {
        if ($query instanceof QueryData) {
            return $query->types;
        }

        $types = is_array($query) ? ($query['types'] ?? []) : [];

        return is_array($types) ? $types : [];
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
        $isParameter = 'string_column_vs_integer_parameter' === $mismatch['kind'];

        $description = sprintf(
            '%s MySQL and MariaDB convert the column value of every row to a number before comparing, so the index ' .
            'on %s cannot be used to look the value up and every row is read (full scan); PostgreSQL rejects the comparison. %s',
            $isParameter
                ? sprintf('Text column %s is compared to %s, bound as an integer because DQL infers the type from the PHP int value.', $mismatch['column'], $mismatch['literal'])
                : sprintf('Text column %s is compared to the number %s.', $mismatch['column'], $mismatch['literal']),
            $mismatch['column'],
            $isParameter ? 'Pass the value as a string, or declare the parameter type.' : 'Compare it to a string instead.',
        );

        $title = $isParameter
            ? sprintf('String Column %s Compared to Integer Parameter', $mismatch['column'])
            : sprintf('String Column %s Compared to Numeric Literal', $mismatch['column']);

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
