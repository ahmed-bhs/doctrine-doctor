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

class ClassTableInheritanceThinSubclassAnalyzer implements AnalyzerInterface
{
    use ShortClassNameTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly int $minFieldsThreshold = 2,
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
                if (ClassMetadata::INHERITANCE_TYPE_JOINED !== $metadata->inheritanceType) {
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
        $subClasses = $rootMetadata->subClasses;

        if ([] === $subClasses) {
            return;
        }

        $parentFields = array_keys($rootMetadata->fieldMappings);
        $thinSubclasses = [];

        foreach ($subClasses as $subClass) {
            $subMetadata = $this->findMetadata($subClass, $allMetadata);
            if (null === $subMetadata) {
                continue;
            }

            $ownFields = array_diff(array_keys($subMetadata->fieldMappings), $parentFields);
            $ownFieldCount = count($ownFields);

            if ($ownFieldCount <= $this->minFieldsThreshold) {
                $thinSubclasses[] = [
                    'class' => $subClass,
                    'own_fields' => $ownFields,
                    'own_field_count' => $ownFieldCount,
                ];
            }
        }

        if ([] === $thinSubclasses) {
            return;
        }

        $rootClass = $rootMetadata->getName();

        foreach ($thinSubclasses as $thin) {
            $description = DescriptionHighlighter::highlight(
                'Class Table Inheritance subclass {subclass} adds only ' . $thin['own_field_count']
                . ' field(s) to the parent {root}. '
                . 'Each polymorphic query pays a JOIN cost for minimal additional data.',
                [
                    'subclass' => $this->shortClassName($thin['class']),
                    'root' => $this->shortClassName($rootClass),
                ],
            );

            $suggestion = $this->suggestionFactory->createFromTemplate(
                'Integrity/cti_thin_subclass',
                [
                    'root_class' => $this->shortClassName($rootClass),
                    'root_fqcn' => $rootClass,
                    'subclass' => $this->shortClassName($thin['class']),
                    'subclass_fqcn' => $thin['class'],
                    'own_fields' => $thin['own_fields'],
                    'own_field_count' => $thin['own_field_count'],
                    'parent_field_count' => count($parentFields),
                ],
                new SuggestionMetadata(
                    SuggestionType::integrity(),
                    Severity::info(),
                    'Consider switching to Single Table Inheritance (SINGLE_TABLE)',
                ),
            );

            yield new IntegrityIssue((new IssueData(
                type: IssueType::CTI_THIN_SUBCLASS->value,
                title: sprintf('Thin CTI Subclass: %s', $this->shortClassName($thin['class'])),
                description: $description,
                severity: Severity::info(),
                suggestion: $suggestion,
            ))->toArray());
        }
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
