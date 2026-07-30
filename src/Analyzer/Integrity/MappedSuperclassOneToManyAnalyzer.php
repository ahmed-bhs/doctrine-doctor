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

class MappedSuperclassOneToManyAnalyzer implements AnalyzerInterface
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

            foreach ($allMetadata as $metadata) {
                if (!$metadata->isMappedSuperclass) {
                    continue;
                }

                foreach ($metadata->getAssociationMappings() as $mapping) {
                    $type = $mapping['type'] ?? $mapping->type ?? null;

                    if (ClassMetadata::ONE_TO_MANY !== $type) {
                        continue;
                    }

                    $description = DescriptionHighlighter::highlight(
                        'Mapped Superclass {entity} declares a OneToMany association on ${field}. '
                        . 'Doctrine does not support OneToMany associations on Mapped Superclasses '
                        . 'because the inverse side cannot reference a non-entity class. '
                        . 'This will cause a MappingException at runtime.',
                        [
                            'entity' => $this->shortClassName($metadata->getName()),
                            'field' => $mapping['fieldName'],
                        ],
                    );

                    yield new IntegrityIssue((new IssueData(
                        type: IssueType::MAPPED_SUPERCLASS_ONE_TO_MANY->value,
                        title: sprintf(
                            'OneToMany on Mapped Superclass: %s::$%s',
                            $this->shortClassName($metadata->getName()),
                            $mapping['fieldName'],
                        ),
                        description: $description,
                        severity: Severity::critical(),
                    ))->toArray());
                }
            }
        });
    }
}
