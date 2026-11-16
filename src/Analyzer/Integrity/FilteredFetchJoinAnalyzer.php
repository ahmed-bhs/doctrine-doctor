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
use AhmedBhs\DoctrineDoctor\Analyzer\Concern\QueryFieldAccessorTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\CollectionJoinDetector;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;

/**
 * A to-many collection that is both fetch-joined (its columns are selected) and filtered in the WHERE clause:
 *
 *     ->select('p', 'productTaxon')->innerJoin('p.productTaxons', 'productTaxon')
 *     ->andWhere('productTaxon.taxon = :collection')
 *
 * Doctrine hydrates the collection with the rows that pass the filter only, and the identity map keeps that
 * incomplete collection for the rest of the request.
 */
class FilteredFetchJoinAnalyzer implements AnalyzerInterface
{
    use QueryFieldAccessorTrait;

    /** @var array<string, string> alias (lowercase) => table, for the query being analyzed */
    private array $aliasTables = [];

    public function __construct(
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly IssueFactoryInterface $issueFactory,
        private readonly SqlStructureExtractor $sqlExtractor,
        private readonly CollectionJoinDetector $collectionJoinDetector,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(function () use ($queryDataCollection) {
            $seen = [];
            foreach ($queryDataCollection as $query) {
                $sql = $this->extractSQL($query);
                if ('' === $sql || !str_starts_with(strtoupper(ltrim($sql)), 'SELECT')) {
                    continue;
                }

                foreach ($this->filteredFetchJoins($sql) as [$table, $condition]) {
                    $key = $table . '|' . $condition;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;

                    yield $this->createIssue($table, $condition, $query);
                }
            }
        });
    }

    public function getName(): string
    {
        return 'Filtered Fetch Join Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects to-many collections that are fetch-joined and filtered in WHERE, which hydrates them partially';
    }

    /**
     * @return list<array{0: string, 1: string}> joined table and the WHERE condition that filters it
     */
    private function filteredFetchJoins(string $sql): array
    {
        $normalize = static fn (string $alias): string => rtrim(strtolower($alias), '_');
        $selected = array_map($normalize, $this->sqlExtractor->extractTableAliasesFromSelect($sql));
        $where = $this->whereClause($sql);
        // A grouped query aggregates the joined rows instead of hydrating a collection.
        if ('' === $where || \count($selected) < 2 || $this->sqlExtractor->hasGroupBy($sql)) {
            return [];
        }

        $joins = $this->sqlExtractor->extractJoins($sql);
        $this->aliasTables = $this->aliasTablesOf($sql, $joins);

        $found = [];
        foreach ($joins as $join) {
            $alias = \is_string($join['alias']) ? $join['alias'] : '';
            $table = \is_string($join['table']) ? $join['table'] : '';
            if ('' === $alias || !\in_array($normalize($alias), $selected, true) || !$this->isToManyJoin($table, $alias, $join['expr'])) {
                continue;
            }

            $condition = $this->filterOn($alias, $join['expr'], $where);
            if (null !== $condition) {
                $found[] = [$table, $condition];
            }
        }

        return $found;
    }

    /**
     * The WHERE condition on the joined alias, except on a translation loaded for one locale: filtering it on its
     * locale, or further when the join already restricts the locale, is meant to keep one language.
     */
    private function filterOn(string $alias, mixed $expr, string $where): ?string
    {
        if (1 === preg_match('/\b' . preg_quote($alias, '/') . '\.locale\b/i', $this->onClause($expr))) {
            return null;
        }

        $pattern = '/\b' . preg_quote($alias, '/') . '\.(?!locale\b)\w+\s*(?:=|<>|!=|<=?|>=?|\bIN\b|\bLIKE\b|\bBETWEEN\b)\s*[^\s)]+/i';

        return 1 === preg_match($pattern, $where, $match) ? $match[0] : null;
    }

    /**
     * @param array<int, array{type: string, table: string, alias: string|null, expr: mixed}> $joins
     * @return array<string, string>
     */
    private function aliasTablesOf(string $sql, array $joins): array
    {
        $tables = [];
        if (1 === preg_match('/\bFROM\s+`?(\w+)`?\s+(?:AS\s+)?(\w+)/i', $sql, $from)) {
            $tables[strtolower($from[2])] = $from[1];
        }
        foreach ($joins as $join) {
            if (\is_string($join['alias']) && \is_string($join['table'])) {
                $tables[strtolower($join['alias'])] = $join['table'];
            }
        }

        return $tables;
    }

    /**
     * The WHERE clause of the outer query, without subqueries.
     */
    private function whereClause(string $sql): string
    {
        $withoutSubqueries = (string) preg_replace('/\(\s*SELECT\b(?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/i', '(…)', $sql);
        if (1 !== preg_match('/\bWHERE\b(.*?)(?:\bGROUP\s+BY\b|\bORDER\s+BY\b|\bLIMIT\b|\bOFFSET\b|$)/is', $withoutSubqueries, $match)) {
            return '';
        }

        return $match[1];
    }

    /**
     * The joined table is the many side of its ON condition: the other side compares its single-column primary
     * key with a column of the joined table that is not its primary key.
     */
    private function isToManyJoin(string $table, string $alias, mixed $expr): bool
    {
        $metadataMap = $this->collectionJoinDetector->buildMetadataMap();
        preg_match_all('/\b(\w+)\.(\w+)\s*=\s*(\w+)\.(\w+)\b/', $this->onClause($expr), $pairs, PREG_SET_ORDER);
        foreach ($pairs as [, $leftAlias, $leftColumn, $rightAlias, $rightColumn]) {
            [$otherAlias, $otherColumn, $ownColumn] = 0 === strcasecmp($leftAlias, $alias)
                ? [$rightAlias, $rightColumn, $leftColumn]
                : (0 === strcasecmp($rightAlias, $alias) ? [$leftAlias, $leftColumn, $rightColumn] : [null, null, null]);
            if (null === $otherAlias) {
                continue;
            }

            $ownMetadata = $metadataMap[$table] ?? null;
            $ownKey = $ownMetadata?->getIdentifierColumnNames() ?? ['id'];
            $otherTable = $this->tableOfAlias($otherAlias);
            $otherMetadata = null !== $otherTable ? ($metadataMap[$otherTable] ?? null) : null;
            $otherKey = $otherMetadata?->getIdentifierColumnNames() ?? ['id'];

            $otherIsUnique = 1 === \count($otherKey) && 0 === strcasecmp($otherKey[0], (string) $otherColumn);
            $ownIsUnique = 1 === \count($ownKey) && 0 === strcasecmp($ownKey[0], (string) $ownColumn);

            if ($otherIsUnique && !$ownIsUnique) {
                return true;
            }
        }

        return false;
    }

    private function onClause(mixed $expr): string
    {
        return \is_object($expr) && property_exists($expr, 'on') && \is_array($expr->on)
            ? implode(' AND ', array_map(static fn (mixed $condition): string => \is_scalar($condition) || $condition instanceof \Stringable ? (string) $condition : '', $expr->on))
            : '';
    }

    private function tableOfAlias(string $alias): ?string
    {
        return $this->aliasTables[strtolower($alias)] ?? null;
    }

    private function createIssue(string $table, string $condition, QueryData $query): IntegrityIssue
    {
        $issueData = new IssueData(
            type: IssueType::FILTERED_FETCH_JOIN->value,
            title: sprintf('Filtered Fetch Join: %s', $table),
            description: sprintf(
                'The query loads the %s collection with a fetch join and filters it in WHERE (%s). '
                . 'Doctrine hydrates the collection with the matching rows only, and keeps it incomplete in memory for the rest of the request.',
                $table,
                trim($condition),
            ),
            severity: Severity::critical(),
            suggestion: $this->suggestionFactory->createFromTemplate(
                templateName: 'Integrity/filtered_fetch_join',
                context: ['table' => $table, 'condition' => trim($condition)],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::integrity(),
                    severity: Severity::critical(),
                    title: 'Filter with one join, load with another',
                    tags: ['fetch-join', 'collection', 'identity-map'],
                ),
            ),
            queries: [$query],
            backtrace: $this->extractBacktrace($query),
        );

        /** @var IntegrityIssue $issue */
        $issue = $this->issueFactory->createFromArray($issueData->toArray());

        return $issue;
    }
}
