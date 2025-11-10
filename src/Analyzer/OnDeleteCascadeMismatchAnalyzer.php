<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\CodeQualityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\CodeSuggestion;
use AhmedBhs\DoctrineDoctor\Template\Renderer\TemplateRendererInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Detects mismatches between ORM cascade and database onDelete constraints.
 * When ORM cascade and DB constraints differ, behavior depends on HOW you delete:
 * - $em->remove($entity) uses ORM cascade
 * - Direct SQL DELETE uses DB onDelete constraint
 * Example:
 * class Order {
 *     @OneToMany(cascade={"remove"})
 *     private Collection $items;
 * }
 * class OrderItem {
 *     @ManyToOne @JoinColumn(onDelete="SET NULL")
 * }
 * Result: $em->remove($order) deletes items, but SQL DELETE sets NULL!
 */
class OnDeleteCascadeMismatchAnalyzer implements AnalyzerInterface
{
    public function __construct(
        /**
         * @readonly
         */
        private EntityManagerInterface $entityManager,
        /**
         * @readonly
         */
        private TemplateRendererInterface $templateRenderer,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                $classMetadataFactory = $this->entityManager->getMetadataFactory();
                $allMetadata          = $classMetadataFactory->getAllMetadata();

                // Create metadata map
                $metadataMap = [];

                assert(is_iterable($allMetadata), '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    $metadataMap[$metadata->getName()] = $metadata;
                }

                assert(is_iterable($allMetadata), '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    $entityIssues = $this->analyzeEntity($metadata, $metadataMap);

                    assert(is_iterable($entityIssues), '$entityIssues must be iterable');

                    foreach ($entityIssues as $entityIssue) {
                        yield $entityIssue;
                    }
                }
            },
        );
    }

    public function getName(): string
    {
        return 'OnDelete Cascade Mismatch Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects mismatches between ORM cascade and database onDelete constraints';
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     * @param array<string, mixed>  $metadataMap
     * @return array<\AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function analyzeEntity(ClassMetadata $classMetadata, array $metadataMap): array
    {

        $issues      = [];
        $entityClass = $classMetadata->getName();

        // Check OneToMany associations
        foreach ($classMetadata->getAssociationMappings() as $fieldName => $associationMapping) {
            if (($associationMapping['type'] ?? 0) !== ClassMetadata::ONE_TO_MANY) {
                continue;
            }

            $mismatch = $this->detectMismatch($associationMapping, $metadataMap);

            if (null !== $mismatch) {
                $issue    = $this->createIssue($entityClass, $fieldName, $mismatch);
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * Detect mismatch between ORM cascade and DB onDelete.
     */
    private function detectMismatch(array|object $mapping, array $metadataMap): ?array
    {
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? null;
        $mappedBy     = MappingHelper::getString($mapping, 'mappedBy') ?? null;

        if (null === $targetEntity || null === $mappedBy) {
            return null;
        }

        $inverseMapping = $this->getInverseMapping($targetEntity, $mappedBy, $metadataMap);

        if (null === $inverseMapping) {
            return null;
        }

        $cascadeConfig = $this->extractCascadeConfiguration($mapping, $inverseMapping);

        if (null === $cascadeConfig) {
            return null;
        }

        $mismatchType = $this->identifyMismatchType($cascadeConfig);

        if (null === $mismatchType) {
            return null;
        }

        return $this->buildMismatchResult($mismatchType, $cascadeConfig, $targetEntity, $mappedBy);
    }

    /**
     * Get inverse mapping from target entity metadata.
     */
    private function getInverseMapping(string $targetEntity, string $mappedBy, array $metadataMap): array|object|null
    {
        $targetMetadata = $metadataMap[$targetEntity] ?? null;

        if (null === $targetMetadata) {
            return null;
        }

        $inverseMappings = $targetMetadata->getAssociationMappings();

        return $inverseMappings[$mappedBy] ?? null;
    }

    /**
     * Extract cascaconfiguration from mapping and inverse mapping.
     * @return array{cascade: array, ormHasCascadeRemove: bool, orphanRemoval: bool, onDelete: string}|null
     */
    private function extractCascadeConfiguration(array|object $mapping, array|object $inverseMapping): ?array
    {
        $cascade             = MappingHelper::getArray($mapping, 'cascade') ?? [];
        $ormHasCascadeRemove = in_array('remove', $cascade, true) || in_array('all', $cascade, true);
        $orphanRemoval       = MappingHelper::getBool($mapping, 'orphanRemoval') ?? false;

        $joinColumns = MappingHelper::getArray($inverseMapping, 'joinColumns') ?? [];

        if ([] === $joinColumns) {
            return null;
        }

        $firstJoinColumn = reset($joinColumns);
        // Handle both array and object joinColumn (Doctrine 3 vs 4)
        $onDelete = is_array($firstJoinColumn)
            ? strtoupper($firstJoinColumn['onDelete'] ?? '')
            : strtoupper($firstJoinColumn->onDelete ?? '');

        return [
            'cascade'             => $cascade,
            'ormHasCascadeRemove' => $ormHasCascadeRemove,
            'orphanRemoval'       => $orphanRemoval,
            'onDelete'            => $onDelete,
        ];
    }

    /**
     * Identify mismatch type based on cascaconfiguration.
     */
    private function identifyMismatchType(array $config): ?string
    {
        $ormHasCascadeRemove = $config['ormHasCascadeRemove'];
        $orphanRemoval       = $config['orphanRemoval'];
        $onDelete            = $config['onDelete'];

        // Mismatch 1: ORM cascade=remove but DB onDelete=SET NULL
        if ($ormHasCascadeRemove && 'SET NULL' === $onDelete) {
            return 'orm_cascade_db_setnull';
        }

        // Mismatch 2: ORM orphanRemoval but DB onDelete=SET NULL
        if ($orphanRemoval && 'SET NULL' === $onDelete) {
            return 'orm_orphan_db_setnull';
        }

        // Mismatch 3: DB onDelete=CASCADE but no ORM cascade
        if ('CASCADE' === $onDelete && !$ormHasCascadeRemove) {
            return 'db_cascade_no_orm';
        }

        // Mismatch 4: ORM cascade but no DB constraint
        if ($ormHasCascadeRemove && '' === $onDelete) {
            return 'orm_cascade_no_db';
        }

        return null;
    }

    /**
     * Build mismatch result array.
     * @return array<string, mixed>
     */
    private function buildMismatchResult(string $mismatchType, array $config, string $targetEntity, string $mappedBy): array
    {
        return [
            'type'               => $mismatchType,
            'orm_cascade'        => $config['cascade'],
            'orm_orphan_removal' => $config['orphanRemoval'],
            'db_on_delete'       => $config['onDelete'] ?: 'NONE',
            'inverse_field'      => $mappedBy,
            'target_entity'      => $targetEntity,
        ];
    }

    private function createIssue(
        string $entityClass,
        string $fieldName,
        array $mismatch,
    ): CodeQualityIssue {
        $severity = $this->determineSeverity($mismatch['type']);

        $codeQualityIssue = new CodeQualityIssue([
            'entity'        => $entityClass,
            'field'         => $fieldName,
            'mismatch_type' => $mismatch['type'],
            'orm_cascade'   => $mismatch['orm_cascade'],
            'db_on_delete'  => $mismatch['db_on_delete'],
            'target_entity' => $mismatch['target_entity'],
            'inverse_field' => $mismatch['inverse_field'],
        ]);

        $codeQualityIssue->setSeverity($severity);
        $codeQualityIssue->setTitle('ORM Cascade / Database onDelete Mismatch');
        $codeQualityIssue->setMessage($this->getMismatchMessage($entityClass, $fieldName, $mismatch));
        $codeQualityIssue->setSuggestion($this->buildSuggestion($entityClass, $fieldName, $mismatch));

        return $codeQualityIssue;
    }

    private function determineSeverity(string $mismatchType): Severity
    {
        return match ($mismatchType) {
            'orm_cascade_db_setnull' => Severity::WARNING,  // Was 'high' - data integrity risk
            'orm_orphan_db_setnull'  => Severity::WARNING,  // Was 'high' - orphan removal issue
            'db_cascade_no_orm'      => Severity::WARNING,  // Was 'medium' - configuration mismatch
            'orm_cascade_no_db'      => Severity::WARNING,  // Was 'medium' - configuration mismatch
            default                  => Severity::WARNING,
        };
    }

    private function getMismatchMessage(string $entityClass, string $fieldName, array $mismatch): string
    {
        $type = $mismatch['type'];

        return match ($type) {
            'orm_cascade_db_setnull' => DescriptionHighlighter::highlight(
                "Field {field} in {class} has {ormCascade} in ORM but {dbOnDelete} in database. Delete behavior differs: ORM deletes children, database sets FK to NULL.",
                [
                    'field' => $fieldName,
                    'class' => $entityClass,
                    'ormCascade' => 'cascade="remove"',
                    'dbOnDelete' => 'onDelete="SET NULL"',
                ],
            ),

            'orm_orphan_db_setnull' => DescriptionHighlighter::highlight(
                "Field {field} in {class} has {orphan} in ORM but {dbOnDelete} in database. Inconsistent: ORM wants to delete orphans, database wants to set NULL.",
                [
                    'field' => $fieldName,
                    'class' => $entityClass,
                    'orphan' => 'orphanRemoval=true',
                    'dbOnDelete' => 'onDelete="SET NULL"',
                ],
            ),

            'db_cascade_no_orm' => DescriptionHighlighter::highlight(
                "Field {field} in {class} has {dbOnDelete} in database but no {ormCascade} in ORM. Direct SQL DELETEs cascade, but {remove} does not.",
                [
                    'field' => $fieldName,
                    'class' => $entityClass,
                    'dbOnDelete' => 'onDelete="CASCADE"',
                    'ormCascade' => 'cascade="remove"',
                    'remove' => '$em->remove()',
                ],
            ),

            'orm_cascade_no_db' => DescriptionHighlighter::highlight(
                "Field {field} in {class} has {ormCascade} in ORM but no onDelete constraint in database. ORM deletes work, but direct SQL DELETEs may cause FK constraint violations.",
                [
                    'field' => $fieldName,
                    'class' => $entityClass,
                    'ormCascade' => 'cascade="remove"',
                ],
            ),

            default => 'ORM cascade and database onDelete constraint mismatch detected.',
        };
    }

    private function buildSuggestion(
        string $entityClass,
        string $fieldName,
        array $mismatch,
    ): CodeSuggestion {
        $shortClassName  = $this->getShortClassName($entityClass);
        $shortTargetName = $this->getShortClassName($mismatch['target_entity']);

        $ormCascade = $mismatch['orm_cascade'];
        $dbOnDelete = $mismatch['db_on_delete'];

        // Convert cascade array to string for display
        $ormCascadeString = is_array($ormCascade)
            ? ([] === $ormCascade ? 'none' : implode(', ', $ormCascade))
            : $ormCascade;

        $rendered = $this->templateRenderer->render('on_delete_cascade_mismatch', [
            'entity_class' => $shortClassName,
            'target_class' => $shortTargetName,
            'field_name'   => $fieldName,
            'orm_cascade'  => $ormCascadeString,
            'db_on_delete' => $dbOnDelete,
        ]);

        return new CodeSuggestion($rendered);
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }
}
