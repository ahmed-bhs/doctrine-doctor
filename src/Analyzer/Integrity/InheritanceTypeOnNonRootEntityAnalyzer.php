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
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\InheritanceType;
use ReflectionClass;

class InheritanceTypeOnNonRootEntityAnalyzer implements AnalyzerInterface
{
    use ShortClassNameTrait;

    public function __construct(
        private readonly \Doctrine\ORM\EntityManagerInterface $entityManager,
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
                if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                    continue;
                }

                if (ClassMetadata::INHERITANCE_TYPE_NONE === $metadata->inheritanceType) {
                    continue;
                }

                if ($metadata->isRootEntity()) {
                    continue;
                }

                $className = $metadata->getName();

                if (!class_exists($className)) {
                    continue;
                }

                $reflectionClass = new ReflectionClass($className);
                $hasInheritanceType = [] !== $reflectionClass->getAttributes(InheritanceType::class);

                if (!$hasInheritanceType) {
                    continue;
                }

                $rootClass = $metadata->parentClasses[array_key_last($metadata->parentClasses)] ?? $className;

                $description = DescriptionHighlighter::highlight(
                    '{entity} declares #[InheritanceType] but is not the root entity of the hierarchy. '
                    . 'Inheritance mapping attributes must be placed on the root entity {root}. '
                    . 'Doctrine may silently ignore this or produce undefined behavior.',
                    [
                        'entity' => $this->shortClassName($className),
                        'root' => $this->shortClassName($rootClass),
                    ],
                );

                yield new IntegrityIssue((new IssueData(
                    type: IssueType::INHERITANCE_TYPE_ON_NON_ROOT->value,
                    title: sprintf(
                        'InheritanceType on non-root entity: %s (root: %s)',
                        $this->shortClassName($className),
                        $this->shortClassName($rootClass),
                    ),
                    description: $description,
                    severity: Severity::critical(),
                ))->toArray());
            }
        });
    }
}
