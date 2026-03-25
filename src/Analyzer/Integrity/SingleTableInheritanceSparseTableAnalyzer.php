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
use AhmedBhs\DoctrineDoctor\Analyzer\Concern\ShortClassNameTrait;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

class SingleTableInheritanceSparseTableAnalyzer implements AnalyzerInterface
{
    use ShortClassNameTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly float $sparseThreshold = 0.6,
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        return IssueCollection::fromGenerator(function () {
            try {
                /** @var array<ClassMetadata<object>> $allMetadata */
                $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
            } catch (\Throwable) {
                return;
            }

            $processedRoots = [];

            foreach ($allMetadata as $metadata) {
                if (ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE !== $metadata->inheritanceType) {
                    continue;
                }

                if (!$metadata->isRootEntity()) {
                    continue;
                }

                $rootClass = $metadata->getName();
                if (isset($processedRoots[$rootClass])) {
                    continue;
                }
                $processedRoots[$rootClass] = true;

                yield from $this->analyzeHierarchy($metadata, $allMetadata);
            }
        });
    }

    /**
     * @param array<ClassMetadata<object>> $allMetadata
     * @return iterable<IntegrityIssue>
     */
    private function analyzeHierarchy(ClassMetadata $rootMetadata, array $allMetadata): iterable
    {
        $rootClass = $rootMetadata->getName();
        $subClasses = $rootMetadata->subClasses;

        if ([] === $subClasses) {
            return;
        }

        $allFields = $this->collectAllFields($rootMetadata, $subClasses, $allMetadata);
        $totalColumns = count($allFields);

        if (0 === $totalColumns) {
            return;
        }

        foreach ($subClasses as $subClass) {
            $subMetadata = $this->findMetadata($subClass, $allMetadata);
            if (null === $subMetadata) {
                continue;
            }

            $ownFields = array_keys($subMetadata->fieldMappings);
            $unusedColumns = $totalColumns - count($ownFields);
            $sparseRatio = $unusedColumns / $totalColumns;

            if ($sparseRatio < $this->sparseThreshold) {
                continue;
            }

            $percentage = (int) round($sparseRatio * 100);

            $description = DescriptionHighlighter::highlight(
                'Single Table Inheritance hierarchy rooted at {root} has a sparse table: '
                . 'subclass {subclass} uses only ' . (count($ownFields)) . ' of ' . $totalColumns . ' columns '
                . '(' . $percentage . '% unused). '
                . 'This wastes storage and reduces query efficiency.',
                [
                    'root' => $this->shortClassName($rootClass),
                    'subclass' => $this->shortClassName($subClass),
                ],
            );

            $suggestion = $this->suggestionFactory->createFromTemplate(
                'Integrity/sti_sparse_table',
                [
                    'root_class' => $this->shortClassName($rootClass),
                    'root_fqcn' => $rootClass,
                    'subclass' => $this->shortClassName($subClass),
                    'subclass_fqcn' => $subClass,
                    'own_fields_count' => count($ownFields),
                    'total_columns' => $totalColumns,
                    'sparse_percentage' => $percentage,
                    'subclass_count' => count($subClasses),
                ],
                new SuggestionMetadata(
                    SuggestionType::integrity(),
                    Severity::warning(),
                    'Consider switching to Class Table Inheritance (JOINED)',
                ),
            );

            yield new IntegrityIssue((new IssueData(
                type: IssueType::STI_SPARSE_TABLE->value,
                title: sprintf('Sparse STI Table: %s', $this->shortClassName($rootClass)),
                description: $description,
                severity: Severity::warning(),
                suggestion: $suggestion,
            ))->toArray());
        }
    }

    /**
     * @param list<string> $subClasses
     * @param array<ClassMetadata<object>> $allMetadata
     * @return array<string, true>
     */
    private function collectAllFields(ClassMetadata $rootMetadata, array $subClasses, array $allMetadata): array
    {
        $fields = [];
        foreach (array_keys($rootMetadata->fieldMappings) as $field) {
            $fields[$field] = true;
        }

        foreach ($subClasses as $subClass) {
            $subMetadata = $this->findMetadata($subClass, $allMetadata);
            if (null === $subMetadata) {
                continue;
            }
            foreach (array_keys($subMetadata->fieldMappings) as $field) {
                $fields[$field] = true;
            }
        }

        return $fields;
    }

    /**
     * @param array<ClassMetadata<object>> $allMetadata
     * @return ClassMetadata<object>|null
     */
    private function findMetadata(string $className, array $allMetadata): ?ClassMetadata
    {
        foreach ($allMetadata as $metadata) {
            if ($metadata->getName() === $className) {
                return $metadata;
            }
        }

        return null;
    }
}
