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

class MappedSuperclassAsTargetEntityAnalyzer implements AnalyzerInterface
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

            $mappedSuperclasses = [];
            foreach ($allMetadata as $metadata) {
                if ($metadata->isMappedSuperclass) {
                    $mappedSuperclasses[$metadata->getName()] = true;
                }
            }

            if ([] === $mappedSuperclasses) {
                return;
            }

            foreach ($allMetadata as $metadata) {
                if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                    continue;
                }

                foreach ($metadata->getAssociationMappings() as $mapping) {
                    $targetEntity = $mapping['targetEntity'] ?? null;
                    if (null === $targetEntity || !isset($mappedSuperclasses[$targetEntity])) {
                        continue;
                    }

                    $description = DescriptionHighlighter::highlight(
                        'Association {entity}::${field} targets {target}, which is a Mapped Superclass. '
                        . 'Mapped Superclasses have no table and cannot be queried. '
                        . 'This will cause a runtime MappingException.',
                        [
                            'entity' => $this->shortClassName($metadata->getName()),
                            'field' => $mapping['fieldName'],
                            'target' => $this->shortClassName($targetEntity),
                        ],
                    );

                    yield new IntegrityIssue((new IssueData(
                        type: IssueType::MAPPED_SUPERCLASS_AS_TARGET->value,
                        title: sprintf(
                            'Association targets Mapped Superclass: %s::$%s -> %s',
                            $this->shortClassName($metadata->getName()),
                            $mapping['fieldName'],
                            $this->shortClassName($targetEntity),
                        ),
                        description: $description,
                        severity: Severity::critical(),
                    ))->toArray());
                }
            }
        });
    }
}
