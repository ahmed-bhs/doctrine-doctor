<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Helper;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

class CollectionJoinDetector
{
    /** @var array<string, ClassMetadata>|null */
    private ?array $metadataMapCache = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SqlStructureExtractor $sqlExtractor,
    ) {
    }

    /**
     * @param array<string, mixed> $join
     * @param array<string, ClassMetadata> $metadataMap
     */
    public function isCollectionJoin(array $join, array $metadataMap, string $sql, string $fromTable): bool
    {
        $joinTable = $join['table'];

        if (!is_string($joinTable)) {
            return false;
        }

        $metadata = $metadataMap[$joinTable] ?? null;

        if (null === $metadata) {
            return false;
        }

        return $this->isForeignKeyInJoinedTable($sql, $fromTable, $joinTable, $metadataMap);
    }

    /**
     * @param array<string, ClassMetadata> $metadataMap
     */
    public function isForeignKeyInJoinedTable(
        string $sql,
        string $fromTable,
        string $joinTable,
        array $metadataMap,
    ): bool {
        $fromMetadata = $metadataMap[$fromTable] ?? null;
        $joinMetadata = $metadataMap[$joinTable] ?? null;

        if (null === $fromMetadata || null === $joinMetadata) {
            return false;
        }

        // Compare against DB column names, not field paths: an entity with an
        // embedded value-object identifier (e.g. #[Embedded] EntityId $id) has
        // getIdentifierFieldNames() return "id.value" while the SQL join
        // condition references the column "id". Using field names here makes
        // every condition match neither side, so the comparison degrades to
        // canBeCollection()'s table-wide fallback regardless of what this
        // specific join actually is.
        $fromPKs = $fromMetadata->getIdentifierColumnNames();
        $joinPKs = $joinMetadata->getIdentifierColumnNames();

        $conditions = $this->sqlExtractor->extractJoinOnConditions($sql, $joinTable);

        if ([] === $conditions) {
            return $this->canBeCollection($joinTable, $metadataMap);
        }

        $collectionVotes = 0;
        $notCollectionVotes = 0;
        $totalConditions = 0;

        foreach ($conditions as $condition) {
            $leftParts = explode('.', $condition['left']);
            $rightParts = explode('.', $condition['right']);

            $leftCol = end($leftParts);
            $rightCol = end($rightParts);

            ++$totalConditions;

            $leftIsPK = in_array($leftCol, $fromPKs, true);
            $rightIsPK = in_array($rightCol, $joinPKs, true);

            if ($leftIsPK && !$rightIsPK && $this->isOneToOneJoinColumn($joinMetadata, (string) $rightCol)) {
                // The foreign key of a OneToOne: at most one row per parent, nothing multiplies.
                ++$notCollectionVotes;
            } elseif ($leftIsPK && !$rightIsPK && $this->isPinnedToOneRow($sql, $rightParts[0] ?? '', $joinMetadata, (string) $rightCol)) {
                // A translation filtered on its locale: (translatable_id, locale) is unique, one row per parent.
                ++$notCollectionVotes;
            } elseif ($leftIsPK && !$rightIsPK) {
                ++$collectionVotes;
            } elseif (!$leftIsPK && $rightIsPK) {
                ++$notCollectionVotes;
            }
        }

        if (0 === $totalConditions) {
            return $this->canBeCollection($joinTable, $metadataMap);
        }

        if ($collectionVotes > 0 && 0 === $notCollectionVotes) {
            return true;
        }

        if ($notCollectionVotes > 0 && 0 === $collectionVotes) {
            return false;
        }

        return $this->canBeCollection($joinTable, $metadataMap);
    }

    /**
     * The other columns of a unique constraint containing the foreign key are each compared to one value.
     */
    private function isPinnedToOneRow(string $sql, string $alias, ClassMetadata $metadata, string $foreignKey): bool
    {
        if ('' === $alias) {
            return false;
        }

        preg_match_all('/\\b' . preg_quote($alias, '/') . '\\.(\\w+)\\s*=\\s*(?:\\?|:\\w+|\'[^\']*\'|\\d+)/i', $sql, $matches);
        $pinned = array_map(strtolower(...), $matches[1]);
        if ([] === $pinned) {
            return false;
        }

        // ORM 2 and 3.x describe indexes as arrays, newer versions as mapping objects.
        $constraints = [];
        foreach ($metadata->table['uniqueConstraints'] ?? [] as $constraint) {
            $constraints[] = MappingHelper::getArray($constraint, 'columns') ?? [];
        }
        foreach ($metadata->table['indexes'] ?? [] as $index) {
            if (true === MappingHelper::getBool($index, 'unique')) {
                $constraints[] = MappingHelper::getArray($index, 'columns') ?? [];
            }
        }

        foreach ($constraints as $constraintColumns) {
            $columns = array_map(static fn (mixed $column): string => strtolower((string) $column), $constraintColumns);
            if (!\in_array(strtolower($foreignKey), $columns, true)) {
                continue;
            }

            if ([] === array_diff($columns, [strtolower($foreignKey)], $pinned)) {
                return true;
            }
        }

        return false;
    }

    private function isOneToOneJoinColumn(ClassMetadata $metadata, string $column): bool
    {
        foreach ($metadata->getAssociationMappings() as $field => $mapping) {
            if (ClassMetadata::ONE_TO_ONE !== $mapping->type() || !$metadata->isAssociationWithSingleJoinColumn($field)) {
                continue;
            }

            if (strtolower($metadata->getSingleAssociationJoinColumnName($field)) === strtolower($column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, ClassMetadata> $metadataMap
     */
    public function canBeCollection(string $tableName, array $metadataMap): bool
    {
        foreach ($metadataMap as $sourceMetadata) {
            foreach ($sourceMetadata->getAssociationMappings() as $associationMapping) {
                $targetEntity = $associationMapping->targetEntity;

                try {
                    $targetMetadata = $this->entityManager->getClassMetadata($targetEntity);

                    if ($targetMetadata->getTableName() === $tableName) {
                        if (
                            ClassMetadata::ONE_TO_MANY === $associationMapping->type()
                            || ClassMetadata::MANY_TO_MANY === $associationMapping->type()
                        ) {
                            return true;
                        }
                    }
                } catch (\Exception) {
                    continue;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, ClassMetadata> $metadataMap
     */
    public function extractFromTable(string $sql, array $metadataMap): ?string
    {
        $mainTable = $this->sqlExtractor->extractMainTable($sql);

        if (null === $mainTable) {
            return null;
        }

        $tableName = $mainTable['table'];

        if (!isset($metadataMap[$tableName])) {
            return null;
        }

        return $tableName;
    }

    /**
     * @return array<string, ClassMetadata>
     */
    public function buildMetadataMap(): array
    {
        if (null !== $this->metadataMapCache) {
            return $this->metadataMapCache;
        }

        /** @var array<string, ClassMetadata> $map */
        $map = [];
        $classMetadataFactory = $this->entityManager->getMetadataFactory();
        $allMetadata = $classMetadataFactory->getAllMetadata();

        foreach ($allMetadata as $classMetadatum) {
            $tableName = $classMetadatum->getTableName();
            $map[$tableName] = $classMetadatum;
        }

        $this->metadataMapCache = $map;

        return $map;
    }
}
