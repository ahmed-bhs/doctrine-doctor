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
use Webmozart\Assert\Assert;

/**
 * Detects the dangerous use of cascade="all" in entity associations.
 * cascade="all" is almost always a mistake and can lead to:
 * - Accidental deletion of independent entities
 * - Creation of duplicate records
 * - Unpredictable behavior in production
 * Example of CRITICAL issue:
 * class Order {
 *     @ManyToOne(targetEntity="Customer", cascade={"all"})
 *     private Customer $customer;
 * }
 * → Deleting an Order will DELETE the Customer!
 */
class CascadeAllAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Entity patterns that are typically independent.
     */
    private const array INDEPENDENT_PATTERNS = [
        'User', 'Customer', 'Account', 'Member', 'Client',
        'Company', 'Organization', 'Team', 'Department',
        'Product', 'Category', 'Brand', 'Tag',
        'Author', 'Editor', 'Publisher',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactory $suggestionFactory,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return IssueCollection<IntegrityIssue>
     */
    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                try {
                    $metadataFactory = $this->entityManager->getMetadataFactory();
                    $allMetadata     = $metadataFactory->getAllMetadata();

                    Assert::isIterable($allMetadata, '$allMetadata must be iterable');

                    foreach ($allMetadata as $metadata) {
                        $entityIssues = $this->analyzeEntity($metadata);

                        Assert::isIterable($entityIssues, '$entityIssues must be iterable');

                        foreach ($entityIssues as $entityIssue) {
                            yield $entityIssue;
                        }
                    }
                } catch (\Throwable $throwable) {
                    $this->logger?->error('CascadeAllAnalyzer failed', [
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
        return 'Cascade All Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects dangerous use of cascade="all" which can cause accidental data deletion or duplication';
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     * @return array<\AhmedBhs\DoctrineDoctor\Issue\IssueInterface>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {

        $issues      = [];
        $entityClass = $classMetadata->getName();

        foreach ($classMetadata->getAssociationMappings() as $fieldName => $associationMapping) {
            // Check if cascade contains 'all'
            // Note: Doctrine ORM expands cascade=['all'] into individual operations:
            // ['persist', 'remove', 'refresh', 'merge', 'detach']
            $cascade = $associationMapping['cascade'] ?? [];

            // Check if explicitly uses 'all' or has all cascade operations
            $hasAll = in_array('all', $cascade, true) || $this->hasAllCascadeOperations($cascade);

            if (!$hasAll) {
                continue;
            }

            // Determine severity based on association type and target entity
            $severity     = $this->determineSeverity($associationMapping);
            $targetEntity = $associationMapping['targetEntity'] ?? 'Unknown';

            $issue = new IntegrityIssue([
                'entity'           => $entityClass,
                'field'            => $fieldName,
                'association_type' => $this->getAssociationType($associationMapping),
                'target_entity'    => $targetEntity,
                'cascade'          => $cascade,
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
            $issue->setSuggestion($this->buildSuggestion($entityClass, $fieldName, $associationMapping));

            $issues[] = $issue;
        }

        return $issues;
    }

    private function determineSeverity(array|object $mapping): Severity
    {
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? '';

        // Determine association type (Doctrine ORM 2.x uses 'type' field, 3.x uses class name)
        $associationType = $this->getAssociationTypeConstant($mapping);

        // CRITICAL: cascade="all" on ManyToOne/ManyToMany to independent entity
        if (in_array($associationType, [ClassMetadata::MANY_TO_ONE, ClassMetadata::MANY_TO_MANY], true)) {
            if ($this->isIndependentEntity($targetEntity)) {
                return Severity::CRITICAL;
            }

            return Severity::WARNING;
        }

        return Severity::WARNING;
    }

    /**
     * Get association type constant in a version-agnostic way.
     * Doctrine ORM 2.x uses 'type' field, 3.x/4.x uses specific mapping classes.
     */
    private function getAssociationTypeConstant(array|object $mapping): int
    {
        // Try to get type from array (Doctrine ORM 2.x)
        if (is_array($mapping) && isset($mapping['type'])) {
            return $mapping['type'];
        }

        // Doctrine ORM 3.x/4.x: determine from class name
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

        return 0; // Unknown
    }

    private function isIndependentEntity(string $entityClass): bool
    {
        return array_any(self::INDEPENDENT_PATTERNS, fn ($pattern) => str_contains($entityClass, (string) $pattern));
    }

    private function getAssociationType(array|object $mapping): string
    {
        $type = $this->getAssociationTypeConstant($mapping);

        return match ($type) {
            ClassMetadata::ONE_TO_ONE   => 'OneToOne',
            ClassMetadata::MANY_TO_ONE  => 'ManyToOne',
            ClassMetadata::ONE_TO_MANY  => 'OneToMany',
            ClassMetadata::MANY_TO_MANY => 'ManyToMany',
            default                     => 'Unknown',
        };
    }

    private function buildSuggestion(string $entityClass, string $fieldName, array|object $mapping): SuggestionInterface
    {
        $type            = $this->getAssociationType($mapping);
        $targetEntity    = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $shortClassName  = $this->getShortClassName($entityClass);
        $shortTargetName = $this->getShortClassName($targetEntity);

        $isIndependent = $this->isIndependentEntity($targetEntity);

        $suggestions = [
            'cascade="all" should be avoided',
            '',
            sprintf('Current: @%s(cascade={"all"}) private %s $%s', $type, $shortTargetName, $fieldName),
        ];

        // Provide specific recommendations based on association type
        if ('ManyToOne' === $type || 'ManyToMany' === $type) {
            $suggestions[] = sprintf('→ Risks: duplicate %ss and data loss on delete', $shortTargetName);
            $suggestions[] = '';
            $suggestions[] = sprintf('Solution: @%s private %s $%s (no cascade)', $type, $shortTargetName, $fieldName);
        } elseif ('OneToMany' === $type) {
            $suggestions[] = '';
            $suggestions[] = 'For composition (parent owns children):';
            $suggestions[] = sprintf('@OneToMany(cascade={"persist","remove"}, orphanRemoval=true)');
        } else {
            $suggestions[] = '';
            $suggestions[] = 'Composition: cascade={"persist","remove"}, orphanRemoval=true';
            $suggestions[] = 'Association: no cascade';
        }

        $suggestions[] = '';
        $suggestions[] = ' cascade="all" = persist+remove+merge+detach+refresh (too broad!)';
        $suggestions[] = '👉 Only use explicit cascades you actually need';

        // Create suggestion using factory
        return $this->suggestionFactory->createFromTemplate(
            'cascade_configuration',
            [
                'entity_class'     => $entityClass,
                'field_name'       => $fieldName,
                'target_entity'    => $targetEntity,
                'issue_type'       => 'cascade_all',
                'is_composition'   => !$isIndependent,
                'association_type' => $type,
                'recommendations'  => implode("\n", $suggestions),
            ],
            new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::critical(),
                title: sprintf('Remove cascade="all" from %s::$%s', $shortClassName, $fieldName),
                tags: ['cascade', 'critical', 'data-integrity'],
            ),
        );
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }

    /**
     * Check if cascade array contains all cascade operations.
     * Doctrine ORM expands cascade=['all'] into: ['persist', 'remove', 'refresh', 'detach']
     * (merge is sometimes included depending on Doctrine version).
     */
    private function hasAllCascadeOperations(array $cascade): bool
    {
        // Doctrine generates these operations from cascade="all"
        // Different versions may include/exclude 'merge'
        $allOperations = ['persist', 'remove', 'refresh', 'detach', 'merge'];

        // Count how many cascade operations match "all" operations
        $matchCount = 0;

        Assert::isIterable($allOperations, '$allOperations must be iterable');

        foreach ($allOperations as $operation) {
            if (in_array($operation, $cascade, true)) {
                $matchCount++;
            }
        }

        // If 4 or 5 operations present (all except optionally merge), it's cascade="all"
        // Must have at least 4 matching operations AND no extra operations
        return $matchCount >= 4 && count($cascade) === $matchCount;
    }
}
