<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;

/**
 * Unified Cascade Analyzer - Single Responsibility Principle.
 *
 * Detects all cascade-related issues in ONE pass through entities:
 * 1. cascade="all" (highest priority - most dangerous)
 * 2. cascade="remove" on independent entities
 * 3. cascade="persist" on independent entities
 *
 * Benefits:
 * - No duplication: One analyzer = one issue per field maximum
 * - Performance: O(n) instead of O(3n)
 * - Maintainability: All cascade logic in one place
 * - Clear priorities: Explicit if/else chain
 */
class CascadeAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Entity patterns that are typically independent.
     */
    private const array INDEPENDENT_PATTERNS = [
        'User', 'Customer', 'Account', 'Member', 'Client',
        'Company', 'Organization', 'Team', 'Department',
        'Product', 'Category', 'Brand', 'Tag',
        'Author', 'Editor', 'Publisher',
        'Country', 'City', 'Region',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            function () {
                try {
                    $metadataFactory = $this->entityManager->getMetadataFactory();
                    $allMetadata = $metadataFactory->getAllMetadata();

                    // Build reference count map for better diagnostics
                    $referenceCountMap = $this->buildReferenceCountMap($allMetadata);

                    foreach ($allMetadata as $metadata) {
                        $entityIssues = $this->analyzeEntity($metadata, $referenceCountMap);

                        foreach ($entityIssues as $issue) {
                            yield $issue;
                        }
                    }
                } catch (\Throwable $throwable) {
                    $this->logger?->error('CascadeAnalyzer failed', [
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                        'file' => $throwable->getFile(),
                        'line' => $throwable->getLine(),
                    ]);
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Unified Cascade Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects all cascade-related issues (all, remove, persist) in a single pass';
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     * @param array<string, int> $referenceCountMap
     * @return array<IntegrityIssue>
     */
    private function analyzeEntity(ClassMetadata $classMetadata, array $referenceCountMap): array
    {
        $issues = [];
        $entityClass = $classMetadata->getName();

        foreach ($classMetadata->getAssociationMappings() as $fieldName => $associationMapping) {
            $issue = $this->detectCascadeIssue($entityClass, $fieldName, $associationMapping, $referenceCountMap);

            if (null !== $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * Detect cascade issue with priority:
     * 1. cascade="all" (most dangerous)
     * 2. cascade="remove" on independent entity
     * 3. cascade="persist" on independent entity
     */
    private function detectCascadeIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        array $referenceCountMap,
    ): ?IntegrityIssue {
        $cascade = MappingHelper::getArray($mapping, 'cascade') ?? [];
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? null;

        // Priority 1: cascade="all" - ALWAYS critical
        if ($this->hasAllCascadeOperations($cascade)) {
            return $this->createCascadeAllIssue($entityClass, $fieldName, $mapping);
        }

        // Priority 2: cascade="remove" on independent entity
        if (in_array('remove', $cascade, true) && null !== $targetEntity) {
            $type = $this->getAssociationTypeConstant($mapping);

            // CRITICAL: cascade="remove" on ManyToOne
            if (ClassMetadata::MANY_TO_ONE === $type) {
                return $this->createCascadeRemoveManyToOneIssue($entityClass, $fieldName, $mapping, $referenceCountMap);
            }

            // HIGH: cascade="remove" on ManyToMany to independent entity
            if (ClassMetadata::MANY_TO_MANY === $type && $this->isIndependentEntity($targetEntity, $referenceCountMap)) {
                return $this->createCascadeRemoveManyToManyIssue($entityClass, $fieldName, $mapping, $referenceCountMap);
            }
        }

        // Priority 3: cascade="persist" on independent entity
        if (in_array('persist', $cascade, true) && null !== $targetEntity) {
            $type = $this->getAssociationTypeConstant($mapping);

            // Only check ManyToOne and ManyToMany (associations to independent entities)
            if (in_array($type, [ClassMetadata::MANY_TO_ONE, ClassMetadata::MANY_TO_MANY], true)) {
                if ($this->isIndependentEntity($targetEntity, $referenceCountMap)) {
                    return $this->createCascadePersistIssue($entityClass, $fieldName, $mapping, $referenceCountMap);
                }
            }
        }

        return null; // No cascade issue detected
    }

    private function createCascadeAllIssue(string $entityClass, string $fieldName, array|object $mapping): IntegrityIssue
    {
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $cascade = MappingHelper::getArray($mapping, 'cascade') ?? [];
        $severity = $this->determineSeverityForAll($mapping);

        $issue = new IntegrityIssue([
            'entity' => $entityClass,
            'field' => $fieldName,
            'association_type' => $this->getAssociationType($mapping),
            'target_entity' => $targetEntity,
            'cascade' => $cascade,
        ]);

        $issue->setSeverity($severity);
        $issue->setTitle('Dangerous cascade="all" Detected');

        $message = DescriptionHighlighter::highlight(
            "Field {field} in entity {class} uses {cascade}. This is dangerous and can lead to accidental data deletion or duplication.",
            [
                'field' => $fieldName,
                'class' => $entityClass,
                'cascade' => '"all"',
            ],
        );
        $issue->setMessage($message);
        $issue->setSuggestion($this->buildCascadeAllSuggestion($entityClass, $fieldName, $mapping));

        return $issue;
    }

    private function createCascadeRemoveManyToOneIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        array $referenceCountMap,
    ): IntegrityIssue {
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $shortTargetName = $this->getShortClassName($targetEntity);

        $issue = new IntegrityIssue([
            'entity' => $entityClass,
            'field' => $fieldName,
            'target_entity' => $targetEntity,
            'association_type' => 'ManyToOne',
            'reference_count' => $referenceCountMap[$targetEntity] ?? 0,
        ]);

        $issue->setSeverity(Severity::critical());
        $issue->setTitle('cascade="remove" on ManyToOne (Data Loss Risk)');

        $refCount = $referenceCountMap[$targetEntity] ?? 0;
        $message = sprintf(
            "Field {field} in entity {class} has \"remove\" on ManyToOne relation to {target}. " .
            "Deleting a %s will also delete the {target}, which may be referenced by other entities.\n\n" .
            "{target} is referenced by %d entities.",
            $this->getShortClassName($entityClass),
            $refCount,
        );

        $issue->setMessage(DescriptionHighlighter::highlight($message, [
            'field' => $fieldName,
            'class' => $entityClass,
            'target' => $shortTargetName,
        ]));

        $issue->setSuggestion($this->buildCascadeRemoveManyToOneSuggestion($entityClass, $fieldName, $targetEntity, $refCount));

        return $issue;
    }

    private function createCascadeRemoveManyToManyIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        array $referenceCountMap,
    ): IntegrityIssue {
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $shortClassName = $this->getShortClassName($entityClass);
        $shortTargetName = $this->getShortClassName($targetEntity);

        $issue = new IntegrityIssue([
            'entity' => $entityClass,
            'field' => $fieldName,
            'target_entity' => $targetEntity,
            'association_type' => 'ManyToMany',
        ]);

        $issue->setSeverity(Severity::warning());
        $issue->setTitle(sprintf('Dangerous cascade remove on independent entity %s', $shortTargetName));

        $message = sprintf(
            'Entity "%s" has cascade remove on property "$%s" pointing to independent entity "%s". ' .
            'Deleting a %s will automatically delete all associated %s entities. ' .
            'This is usually NOT what you want for independent entities. ' .
            'Consider removing cascade="remove" and handling deletion separately.',
            $shortClassName,
            $fieldName,
            $shortTargetName,
            $shortClassName,
            $shortTargetName,
        );

        $issue->setMessage($message);
        $issue->setSuggestion($this->buildCascadeRemoveManyToManySuggestion($entityClass, $fieldName, $targetEntity));

        return $issue;
    }

    private function createCascadePersistIssue(
        string $entityClass,
        string $fieldName,
        array|object $mapping,
        array $referenceCountMap,
    ): IntegrityIssue {
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $shortClassName = $this->getShortClassName($entityClass);
        $shortTargetName = $this->getShortClassName($targetEntity);

        $issue = new IntegrityIssue([
            'entity' => $entityClass,
            'field' => $fieldName,
            'target_entity' => $targetEntity,
            'association_type' => $this->getAssociationType($mapping),
        ]);

        $issue->setSeverity(Severity::warning());
        $issue->setTitle('cascade="persist" on Independent Entity (Risk of Duplicates)');

        $message = DescriptionHighlighter::highlight(
            "Field {field} in entity {class} has \"persist\" on independent entity {target}. " .
            "This can lead to duplicate records. Independent entities should be loaded from the database, not created.",
            [
                'field' => $fieldName,
                'class' => $shortClassName,
                'target' => $shortTargetName,
            ],
        );

        $issue->setMessage($message);
        $issue->setSuggestion($this->buildCascadePersistSuggestion($entityClass, $fieldName, $targetEntity));

        return $issue;
    }

    private function buildCascadeAllSuggestion(string $entityClass, string $fieldName, array|object $mapping): SuggestionInterface
    {
        $type = $this->getAssociationType($mapping);
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $shortClassName = $this->getShortClassName($entityClass);
        $isIndependent = $this->isIndependentEntity($targetEntity, []);

        return $this->suggestionFactory->createFromTemplate(
            'cascade_configuration',
            [
                'entity_class' => $entityClass,
                'field_name' => $fieldName,
                'target_entity' => $targetEntity,
                'issue_type' => 'cascade_all',
                'is_composition' => !$isIndependent,
                'association_type' => $type,
            ],
            new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::critical(),
                title: sprintf('Remove cascade="all" from %s::$%s', $shortClassName, $fieldName),
                tags: ['cascade', 'critical', 'data-integrity'],
            ),
        );
    }

    private function buildCascadeRemoveManyToOneSuggestion(string $entityClass, string $fieldName, string $targetEntity, int $refCount): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            'cascade_remove_many_to_one',
            [
                'entity_class' => $entityClass,
                'field_name' => $fieldName,
                'target_entity' => $targetEntity,
                'reference_count' => $refCount,
            ],
            new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::critical(),
                title: sprintf('Remove cascade="remove" from %s::$%s', $this->getShortClassName($entityClass), $fieldName),
                tags: ['cascade', 'critical', 'data-loss'],
            ),
        );
    }

    private function buildCascadeRemoveManyToManySuggestion(string $entityClass, string $fieldName, string $targetEntity): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            'cascade_remove_independent',
            [
                'entity_class' => $entityClass,
                'field_name' => $fieldName,
                'target_entity' => $targetEntity,
            ],
            new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::warning(),
                title: sprintf('Remove cascade="remove" from %s::$%s', $this->getShortClassName($entityClass), $fieldName),
                tags: ['cascade', 'independent-entity'],
            ),
        );
    }

    private function buildCascadePersistSuggestion(string $entityClass, string $fieldName, string $targetEntity): SuggestionInterface
    {
        return $this->suggestionFactory->createFromTemplate(
            'cascade_persist_independent',
            [
                'entity_class' => $entityClass,
                'field_name' => $fieldName,
                'target_entity' => $targetEntity,
            ],
            new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::warning(),
                title: sprintf('Remove cascade="persist" from %s::$%s', $this->getShortClassName($entityClass), $fieldName),
                tags: ['cascade', 'independent-entity', 'duplicates'],
            ),
        );
    }

    /**
     * Check if cascade array contains all cascade operations.
     * Doctrine ORM expands cascade=['all'] into: ['persist', 'remove', 'refresh', 'detach']
     */
    private function hasAllCascadeOperations(array $cascade): bool
    {
        $requiredOperations = ['persist', 'remove', 'refresh', 'detach'];
        return array_all($requiredOperations, fn ($operation) => in_array($operation, $cascade, true));
    }

    private function determineSeverityForAll(array|object $mapping): Severity
    {
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? '';
        $associationType = $this->getAssociationTypeConstant($mapping);

        // CRITICAL: cascade="all" on ManyToOne/ManyToMany to independent entity
        if (in_array($associationType, [ClassMetadata::MANY_TO_ONE, ClassMetadata::MANY_TO_MANY], true)) {
            if ($this->isIndependentEntity($targetEntity, [])) {
                return Severity::critical();
            }
            return Severity::warning();
        }

        return Severity::warning();
    }

    private function getAssociationTypeConstant(array|object $mapping): int
    {
        if (is_array($mapping) && isset($mapping['type'])) {
            return $mapping['type'];
        }

        if (is_object($mapping)) {
            $className = $mapping::class;

            if (str_contains($className, 'ManyToOneAssociation')) {
                return ClassMetadata::MANY_TO_ONE;
            }
            if (str_contains($className, 'OneToManyAssociation')) {
                return ClassMetadata::ONE_TO_MANY;
            }
            if (str_contains($className, 'ManyToManyAssociation')) {
                return ClassMetadata::MANY_TO_MANY;
            }
            if (str_contains($className, 'OneToOneAssociation')) {
                return ClassMetadata::ONE_TO_ONE;
            }
        }

        return 0;
    }

    private function getAssociationType(array|object $mapping): string
    {
        $type = $this->getAssociationTypeConstant($mapping);

        return match ($type) {
            ClassMetadata::ONE_TO_ONE => 'OneToOne',
            ClassMetadata::MANY_TO_ONE => 'ManyToOne',
            ClassMetadata::ONE_TO_MANY => 'OneToMany',
            ClassMetadata::MANY_TO_MANY => 'ManyToMany',
            default => 'Unknown',
        };
    }

    private function isIndependentEntity(string $entityClass, array $referenceCountMap): bool
    {
        foreach (self::INDEPENDENT_PATTERNS as $pattern) {
            if (str_contains($entityClass, $pattern)) {
                return true;
            }
        }

        // Also consider entities referenced by many others as independent
        $refCount = $referenceCountMap[$entityClass] ?? 0;
        return $refCount >= 3;
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        return end($parts);
    }

    /**
     * @param array<ClassMetadata<object>> $allMetadata
     * @return array<string, int>
     */
    private function buildReferenceCountMap(array $allMetadata): array
    {
        $map = [];

        foreach ($allMetadata as $metadata) {
            foreach ($metadata->getAssociationMappings() as $associationMapping) {
                $targetEntity = MappingHelper::getString($associationMapping, 'targetEntity') ?? null;

                if (null !== $targetEntity) {
                    if (!isset($map[$targetEntity])) {
                        $map[$targetEntity] = 0;
                    }
                    $map[$targetEntity]++;
                }
            }
        }

        return $map;
    }
}
