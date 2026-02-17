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

        $fromPKs = $fromMetadata->getIdentifierFieldNames();
        $joinPKs = $joinMetadata->getIdentifierFieldNames();

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

            if ($leftIsPK && !$rightIsPK) {
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
     * @param array<string, ClassMetadata> $metadataMap
     */
    public function canBeCollection(string $tableName, array $metadataMap): bool
    {
        foreach ($metadataMap as $sourceMetadata) {
            foreach ($sourceMetadata->getAssociationMappings() as $associationMapping) {
                $targetEntity = $associationMapping['targetEntity'] ?? null;

                if (null === $targetEntity) {
                    continue;
                }

                try {
                    $targetMetadata = $this->entityManager->getClassMetadata($targetEntity);

                    if ($targetMetadata->getTableName() === $tableName) {
                        if (
                            ClassMetadata::ONE_TO_MANY === $associationMapping['type']
                            || ClassMetadata::MANY_TO_MANY === $associationMapping['type']
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
