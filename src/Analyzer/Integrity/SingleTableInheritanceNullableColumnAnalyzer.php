<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Concern\ShortClassNameTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Analyzer\StaticAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

class SingleTableInheritanceNullableColumnAnalyzer implements StaticAnalyzerInterface
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
                $nullable = MappingHelper::getBool($mapping, 'nullable') ?? false;

                if ($nullable) {
                    continue;
                }

                // SchemaTool creates every column of a single-table subclass as nullable: the siblings leave it empty.
                $description = DescriptionHighlighter::highlight(
                    '{subclass}::{field} is required in the mapping, but Doctrine creates the columns of single-table '
                    . 'subclasses as nullable, since the other {root} subtypes leave them empty. The database cannot reject '
                    . 'a {subclass} without {field}: only the PHP code does. A CHECK constraint on the discriminator, or '
                    . 'Class Table Inheritance, keeps the rule in the database.',
                    [
                        'field' => $fieldName,
                        'subclass' => $this->shortClassName($subClass),
                        'root' => $this->shortClassName($rootMetadata->getName()),
                    ],
                );

                yield new IntegrityIssue(new IssueData(
                    type: IssueType::STI_NON_NULLABLE_SUBCLASS_COLUMN->value,
                    title: sprintf(
                        'STI Column Not Enforced by the Database: %s::$%s',
                        $this->shortClassName($subClass),
                        $fieldName,
                    ),
                    description: $description,
                    severity: Severity::info(),
                )->toArray());
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
