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
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

class SingleTableInheritanceNullableColumnAnalyzer implements AnalyzerInterface
{
    use ShortClassNameTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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
        $rootFields = array_keys($rootMetadata->fieldMappings);
        $subClasses = $rootMetadata->subClasses;

        if ([] === $subClasses) {
            return;
        }

        foreach ($subClasses as $subClass) {
            $subMetadata = $this->findMetadata($subClass, $allMetadata);
            if (null === $subMetadata) {
                continue;
            }

            $subOnlyFields = array_diff(array_keys($subMetadata->fieldMappings), $rootFields);

            foreach ($subOnlyFields as $fieldName) {
                $mapping = $subMetadata->fieldMappings[$fieldName];
                $nullable = $mapping['nullable'] ?? $mapping->nullable ?? false;

                if ($nullable) {
                    continue;
                }

                $description = DescriptionHighlighter::highlight(
                    'Field {field} in STI subclass {subclass} is NOT NULL. '
                    . 'In Single Table Inheritance, all subtypes share one table. '
                    . 'Inserting a row of type {root} or any sibling will fail because {field} has no value. '
                    . 'Subclass-specific columns must be nullable.',
                    [
                        'field' => $fieldName,
                        'subclass' => $this->shortClassName($subClass),
                        'root' => $this->shortClassName($rootMetadata->getName()),
                    ],
                );

                yield new IntegrityIssue((new IssueData(
                    type: IssueType::STI_NON_NULLABLE_SUBCLASS_COLUMN->value,
                    title: sprintf(
                        'Non-nullable STI column: %s::$%s',
                        $this->shortClassName($subClass),
                        $fieldName,
                    ),
                    description: $description,
                    severity: Severity::critical(),
                ))->toArray());
            }
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
