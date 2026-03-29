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
use AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Detects entities using Gedmo Loggable or Translatable extensions.
 * These extensions generate additional database queries during persist/flush operations:
 * - Loggable: Creates INSERT queries in the changelog table for tracked fields
 * - Translatable: Creates additional queries per translatable field per locale
 */
class GedmoExtensionPerformanceAnalyzer implements MetadataAnalyzerInterface
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
                        if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                            continue;
                        }

                        $entityClass = $metadata->getName();
                        $rc = new ReflectionClass($entityClass);

                        if ($this->isLoggable($rc)) {
                            yield $this->createLoggableIssue($entityClass);
                        } elseif ($this->isTranslatable($rc)) {
                            yield $this->createTranslatableIssue($entityClass);
                        }
                    }
                } catch (\Throwable $throwable) {
                    $this->logger?->error('GedmoExtensionPerformanceAnalyzer failed', [
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                        'file' => $throwable->getFile(),
                        'line' => $throwable->getLine(),
                    ]);
                }
            },
        );
    }

    private function isLoggable(ReflectionClass $reflectionClass): bool
    {
        foreach ($reflectionClass->getTraitNames() as $traitName) {
            if (str_contains($traitName, 'Loggable')) {
                return true;
            }
        }

        return false;
    }

    private function isTranslatable(ReflectionClass $reflectionClass): bool
    {
        foreach ($reflectionClass->getTraitNames() as $traitName) {
            if (str_contains($traitName, 'Translatable')) {
                return true;
            }
        }

        return false;
    }

    private function createLoggableIssue(string $entityClass): PerformanceIssue
    {
        $shortClassName = $this->shortClassName($entityClass);

        $description = DescriptionHighlighter::highlight(
            '{entity} uses the Gedmo {loggable} extension. This generates an additional INSERT query in the changelog table '
            . 'for every tracked field that changes. With many fields or frequent updates, this can significantly impact performance.',
            [
                'entity' => $shortClassName,
                'loggable' => 'Loggable',
            ],
        );

        return new PerformanceIssue([
            'type' => IssueType::GEDMO_LOGGABLE->value,
            'title' => sprintf('%s uses Gedmo Loggable extension', $shortClassName),
            'description' => $description,
            'severity' => Severity::warning(),
            'suggestion' => $this->suggestionFactory->createFromTemplate(
                templateName: 'Performance/gedmo_extension_performance',
                context: [
                    'entity_class' => $entityClass,
                    'extension_type' => 'Loggable',
                    'impact' => 'generates INSERT queries in the changelog table',
                ],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::performance(),
                    severity: Severity::warning(),
                    title: 'Review Gedmo Loggable Impact',
                    tags: ['performance', 'gedmo'],
                ),
            ),
            'backtrace' => null,
            'queries' => [],
        ]);
    }

    private function createTranslatableIssue(string $entityClass): PerformanceIssue
    {
        $shortClassName = $this->shortClassName($entityClass);

        $description = DescriptionHighlighter::highlight(
            '{entity} uses the Gedmo {translatable} extension. This generates additional queries for each translatable field and locale combination. '
            . 'The query count grows linearly with the number of locales — consider the performance impact of maintaining translations.',
            [
                'entity' => $shortClassName,
                'translatable' => 'Translatable',
            ],
        );

        return new PerformanceIssue([
            'type' => IssueType::GEDMO_TRANSLATABLE->value,
            'title' => sprintf('%s uses Gedmo Translatable extension', $shortClassName),
            'description' => $description,
            'severity' => Severity::info(),
            'suggestion' => $this->suggestionFactory->createFromTemplate(
                templateName: 'Performance/gedmo_extension_performance',
                context: [
                    'entity_class' => $entityClass,
                    'extension_type' => 'Translatable',
                    'impact' => 'generates queries per translatable field per locale',
                ],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::performance(),
                    severity: Severity::info(),
                    title: 'Review Gedmo Translatable Impact',
                    tags: ['performance', 'gedmo'],
                ),
            ),
            'backtrace' => null,
            'queries' => [],
        ]);
    }
}
