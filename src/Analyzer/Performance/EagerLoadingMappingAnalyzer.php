<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Concern\MetadataAnalyzerTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\Concern\ShortClassNameTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use ArrayAccess;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;

/**
 * Detects fetch: 'EAGER' declared directly in entity mapping attributes.
 * Global eager loading (in the mapping) forces Doctrine to always load the relation,
 * even when not needed. Eager loading should be decided per-query via QueryBuilder.
 */
class EagerLoadingMappingAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;
    use ShortClassNameTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return IssueCollection<PerformanceIssue>
     */
    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                try {
                    $metadataFactory = $this->entityManager->getMetadataFactory();
                    $allMetadata     = $metadataFactory->getAllMetadata();

                    foreach ($allMetadata as $metadata) {
                        $entityIssues = $this->analyzeEntity($metadata);

                        foreach ($entityIssues as $entityIssue) {
                            yield $entityIssue;
                        }
                    }
                } catch (\Throwable $throwable) {
                    $this->logger?->error('EagerLoadingMappingAnalyzer failed', [
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                        'file' => $throwable->getFile(),
                        'line' => $throwable->getLine(),
                    ]);
                }
            },
        );
    }

    /**
     * @return array<PerformanceIssue>
     */
    private function analyzeEntity(ClassMetadata $classMetadata): array
    {
        $issues      = [];
        $entityClass = $classMetadata->getName();

        foreach ($classMetadata->getAssociationMappings() as $fieldName => $mapping) {
            $fetchMode = MappingHelper::getInt($mapping, 'fetch');

            if (ClassMetadata::FETCH_EAGER === $fetchMode) {
                $issues[] = $this->createEagerLoadingMappingIssue(
                    $entityClass,
                    $fieldName,
                    $mapping,
                );
            }
        }

        return $issues;
    }

    /**
     * @param class-string $entityClass
     * @param ArrayAccess&object $mapping  Association mapping (array in ORM 2.x, AssociationMapping object in 3.x+)
     */
    private function createEagerLoadingMappingIssue(
        string $entityClass,
        string $fieldName,
        ArrayAccess $mapping,
    ): PerformanceIssue {
        $shortClassName = $this->shortClassName($entityClass);
        $targetEntity = MappingHelper::getString($mapping, 'targetEntity') ?? 'Unknown';
        $targetShortName = $this->shortClassName($targetEntity);

        $description = DescriptionHighlighter::highlight(
            'Association {entity}::{field} declares {fetch_mode} which forces eager loading for ALL queries. '
            . 'This is almost always wrong — eager loading should be decided per-query via {querybuilder}. '
            . 'Remove {fetch_mode} from the mapping and use {addselect} in the repository when needed.',
            [
                'entity' => $shortClassName,
                'field' => '$' . $fieldName,
                'fetch_mode' => 'EAGER fetch mode',
                'querybuilder' => 'QueryBuilder::addSelect()',
                'addselect' => 'leftJoin()\'s addSelect()',
            ],
        );

        $issue = new PerformanceIssue([
            'type' => IssueType::EAGER_LOADING_MAPPING->value,
            'title' => sprintf('Global eager fetch on %s::$%s', $shortClassName, $fieldName),
            'description' => $description,
            'severity' => Severity::info(),
            'suggestion' => $this->suggestionFactory->createFromTemplate(
                templateName: 'Performance/eager_loading_mapping',
                context: [
                    'entity_class' => $entityClass,
                    'field_name' => $fieldName,
                    'target_entity' => $targetEntity,
                    'target_short_name' => $targetShortName,
                ],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::performance(),
                    severity: Severity::info(),
                    title: 'Use Dynamic Eager Loading via QueryBuilder',
                    tags: ['performance', 'lazy-loading'],
                ),
            ),
            'backtrace' => null,
            'queries' => [],
        ]);

        return $issue;
    }
}
