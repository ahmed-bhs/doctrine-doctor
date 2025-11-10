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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Detects orphanRemoval=true without cascade="remove".
 * This creates an incomplete composition configuration:
 * - Removing from collection deletes the child (orphanRemoval)
 * - But deleting the parent does NOT delete children (no cascade remove)
 * Example:
 *  class Order {
 *     @OneToMany(orphanRemoval=true)
 *     private Collection $items;
 * }
 * Behavior:
 * $order->removeItem($item); flush(); //  Item deleted
 * $em->remove($order); flush();       //  Items NOT deleted!
 */
class OrphanRemovalWithoutCascadeRemoveAnalyzer implements AnalyzerInterface
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

                assert(is_iterable($allMetadata), '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    $entityIssues = $this->analyzeEntity($metadata);

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
        return 'Orphan Removal Without Cascade Remove Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects orphanRemoval=true without cascade="remove" (incomplete composition)';
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
            // Only check OneToMany
            if (ClassMetadata::ONE_TO_MANY !== $this->getAssociationTypeConstant($associationMapping)) {
                continue;
            }

            // Check if orphanRemoval is enabled
            $orphanRemoval = (bool) (MappingHelper::getBool($associationMapping, 'orphanRemoval') ?? false);

            if (!$orphanRemoval) {
                continue;
            }

            // Check if cascade="remove" is missing
            $cascade          = MappingHelper::getArray($associationMapping, 'cascade') ?? [];
            $hasCascadeRemove = in_array('remove', $cascade, true) || in_array('all', $cascade, true);

            if ($hasCascadeRemove) {
                continue;
            }

            $issue    = $this->createIssue($entityClass, $fieldName, $associationMapping);
            $issues[] = $issue;
        }

        return $issues;
    }

    private function createIssue(string $entityClass, string $fieldName, array|object $mapping): CodeQualityIssue
    {
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $cascade      = MappingHelper::getArray($mapping, 'cascade') ?? [];

        $codeQualityIssue = new CodeQualityIssue([
            'entity'         => $entityClass,
            'field'          => $fieldName,
            'target_entity'  => $targetEntity,
            'cascade'        => $cascade,
            'orphan_removal' => true,
        ]);

        $codeQualityIssue->setSeverity('warning');
        $codeQualityIssue->setTitle('orphanRemoval Without cascade="remove" (Incomplete)');

        $message = DescriptionHighlighter::highlight(
            "Field {field} in entity {class} has {orphan} but no {cascade}. This creates inconsistent behavior: removing from collection deletes children, but deleting the parent does not.",
            [
                'field' => $fieldName,
                'class' => $entityClass,
                'orphan' => 'orphanRemoval=true',
                'cascade' => 'cascade="remove"',
            ],
        );
        $codeQualityIssue->setMessage($message);
        $codeQualityIssue->setSuggestion($this->buildSuggestion($entityClass, $fieldName, $mapping));

        return $codeQualityIssue;
    }

    private function buildSuggestion(string $entityClass, string $fieldName, array|object $mapping): CodeSuggestion
    {
        $targetEntity    = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $cascade         = MappingHelper::getArray($mapping, 'cascade') ?? [];
        $shortClassName  = $this->getShortClassName($entityClass);
        $shortTargetName = $this->getShortClassName($targetEntity);
        $mappedBy        = MappingHelper::getString($mapping, 'mappedBy') ?? 'parent';

        $currentCascade = [] === $cascade
            ? '// No cascade'
            : 'cascade: ["' . implode('", "', $cascade) . '"]';

        $rendered = $this->templateRenderer->render('orphan_removal', [
            'entity_class'    => $shortClassName,
            'target_class'    => $shortTargetName,
            'field_name'      => $fieldName,
            'mapped_by'       => $mappedBy,
            'current_cascade' => $currentCascade,
        ]);

        return new CodeSuggestion($rendered);
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }

    /**
     * Get association type constant in a version-agnostic way.
     * Doctrine ORM 2.x uses 'type' field, 3.x/4.x uses specific mapping classes.
     */
    private function getAssociationTypeConstant(array|object $mapping): int
    {
        // Try to get type from array (Doctrine ORM 2.x)
        $type = MappingHelper::getInt($mapping, 'type');
        if (null !== $type) {
            return $type;
        }

        // Doctrine ORM 3.x/4.x: determine from class name
        if (is_object($mapping)) {
            $className = $mapping::class;

            if (str_contains($className, 'ManyToOne')) {
                return ClassMetadata::MANY_TO_ONE;
            }

            if (str_contains($className, 'OneToMany')) {
                return ClassMetadata::ONE_TO_MANY;
            }

            if (str_contains($className, 'ManyToMany')) {
                return ClassMetadata::MANY_TO_MANY;
            }

            if (str_contains($className, 'OneToOne')) {
                return ClassMetadata::ONE_TO_ONE;
            }
        }

        return 0; // Unknown
    }
}
