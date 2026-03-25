<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Concern\MetadataAnalyzerTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\Concern\ShortClassNameTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
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

class ClassTableInheritanceDepthAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;
    use ShortClassNameTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly int $maxDepth = 3,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(function () {
            try {
                /** @var array<ClassMetadata<object>> $allMetadata */
                $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
            } catch (\Throwable) {
                return;
            }

            $processed = [];

            foreach ($allMetadata as $metadata) {
                if (ClassMetadata::INHERITANCE_TYPE_JOINED !== $metadata->inheritanceType) {
                    continue;
                }

                $className = $metadata->getName();
                if (isset($processed[$className])) {
                    continue;
                }
                $processed[$className] = true;

                $depth = count($metadata->parentClasses);

                if ($depth < $this->maxDepth) {
                    continue;
                }

                $joinsRequired = $depth;
                $severity = $depth >= ($this->maxDepth + 1) ? Severity::critical() : Severity::warning();

                $description = DescriptionHighlighter::highlight(
                    'Class Table Inheritance entity {entity} has a hierarchy depth of ' . $depth
                    . ' levels. Every polymorphic query requires ' . $joinsRequired
                    . ' JOIN(s), which degrades query performance.',
                    [
                        'entity' => $this->shortClassName($className),
                    ],
                );

                $rootClass = $metadata->parentClasses[array_key_last($metadata->parentClasses)] ?? $className;
                $chain = array_reverse($metadata->parentClasses);
                $chain[] = $className;

                $suggestion = $this->suggestionFactory->createFromTemplate(
                    'Integrity/cti_deep_hierarchy',
                    [
                        'entity_class' => $this->shortClassName($className),
                        'entity_fqcn' => $className,
                        'root_class' => $this->shortClassName($rootClass),
                        'root_fqcn' => $rootClass,
                        'depth' => $depth,
                        'joins_required' => $joinsRequired,
                        'chain' => array_map(fn (string $c): string => $this->shortClassName($c), $chain),
                    ],
                    new SuggestionMetadata(
                        SuggestionType::integrity(),
                        $severity,
                        'Consider flattening the CTI hierarchy',
                    ),
                );

                yield new IntegrityIssue((new IssueData(
                    type: IssueType::CTI_DEEP_HIERARCHY->value,
                    title: sprintf('Deep CTI Hierarchy: %s (%d levels)', $this->shortClassName($className), $depth),
                    description: $description,
                    severity: $severity,
                    suggestion: $suggestion,
                ))->toArray());
            }
        });
    }
}
