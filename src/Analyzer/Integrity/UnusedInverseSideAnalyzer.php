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
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\MappingHelper;
use AhmedBhs\DoctrineDoctor\Analyzer\Helper\ProjectSourceScanner;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactoryInterface;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactoryInterface;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
use AhmedBhs\DoctrineDoctor\ValueObject\IssueType;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Inverse sides of to-many associations (mappedBy) that nothing in the application reads: no getter, adder or
 * remover call, no DQL path and no criteria key outside the entity itself. Doctrine never writes the inverse side,
 * so the collection only costs the code that keeps both sides in sync. Only properties declared in the project
 * are checked, and inverse sides with cascade or orphanRemoval are left alone since they do work.
 */
class UnusedInverseSideAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;

    /** @var array<string, string>|null */
    private ?array $sources = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectSourceScanner $projectSourceScanner,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly IssueFactoryInterface $issueFactory,
    ) {
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(function () {
            foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
                if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                    continue;
                }

                foreach ($metadata->associationMappings as $field => $mapping) {
                    $file = $this->unusedInverseSideFile($metadata, (string) $field, $mapping);
                    if (null !== $file) {
                        yield $this->createIssue($metadata, (string) $field, $mapping, $file);
                    }
                }
            }
        });
    }

    public function getName(): string
    {
        return 'Unused Inverse Side Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects inverse sides of to-many associations that the application never reads';
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>|object $mapping
     */
    private function unusedInverseSideFile(ClassMetadata $metadata, string $field, array|object $mapping): ?string
    {
        $type = MappingHelper::getAssociationType($mapping);
        if (!\in_array($type, [ClassMetadata::ONE_TO_MANY, ClassMetadata::MANY_TO_MANY], true)
            || null === MappingHelper::getString($mapping, 'mappedBy')
            || true === MappingHelper::getBool($mapping, 'orphanRemoval')
            || [] !== (MappingHelper::getArray($mapping, 'cascade') ?? [])
            || !property_exists($metadata->getName(), $field)) {
            return null;
        }

        $declaringFile = str_replace('\\', '/', (string) new \ReflectionProperty($metadata->getName(), $field)->getDeclaringClass()->getFileName());
        if (!$this->projectSourceScanner->isProjectFile($declaringFile)) {
            return null;
        }

        $usage = sprintf('/\b(?:get|add|remove|set|has|count)%1$s\b|\.%2$s\b|->%2$s\b|[\'"]%2$s[\'"]/', preg_quote(ucfirst($field), '/'), preg_quote($field, '/'));
        foreach ($this->sources() as $file => $source) {
            if ($file !== $declaringFile && 1 === preg_match($usage, $source)) {
                return null;
            }
        }

        return $declaringFile;
    }

    /**
     * @return array<string, string>
     */
    private function sources(): array
    {
        if (null === $this->sources) {
            $this->sources = [];
            foreach (array_keys($this->projectSourceScanner->files()) as $file) {
                // The owning side names the inverse field in its mapping: that is not a use of the collection.
                $this->sources[$file] = (string) preg_replace('/\b(?:inversedBy|mappedBy|inversed-by|mapped-by)\s*[:=]\s*[\'"]\w+[\'"]/', '', (string) file_get_contents($file));
            }
        }

        return $this->sources;
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>|object $mapping
     */
    private function createIssue(ClassMetadata $metadata, string $field, array|object $mapping, string $file): IntegrityIssue
    {
        $shortName = $metadata->getReflectionClass()->getShortName();
        $target = (string) MappingHelper::getString($mapping, 'targetEntity');
        $shortTarget = substr((string) strrchr('\\' . $target, '\\'), 1);
        $mappedBy = (string) MappingHelper::getString($mapping, 'mappedBy');

        $issueData = new IssueData(
            type: IssueType::UNUSED_INVERSE_SIDE->value,
            title: sprintf('Unused Inverse Side: %s::$%s', $shortName, $field),
            description: DescriptionHighlighter::highlight(
                '{class}::${field} is the inverse side of {target}::${mappedBy}, and nothing outside the entity reads it. '
                . 'Doctrine only writes the owning side, so this collection only adds code that must keep both sides in sync.',
                ['class' => $shortName, 'field' => $field, 'target' => $shortTarget, 'mappedBy' => $mappedBy],
            ),
            severity: Severity::info(),
            suggestion: $this->suggestionFactory->createFromTemplate(
                templateName: 'Integrity/unused_inverse_side',
                context: [
                    'entity_class' => $shortName,
                    'field' => $field,
                    'target_class' => $shortTarget,
                    'mapped_by' => $mappedBy,
                ],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::integrity(),
                    severity: Severity::info(),
                    title: 'Keep only the owning side',
                    tags: ['mapping', 'bidirectional', 'simplicity'],
                ),
            ),
            queries: [],
        );

        /** @var IntegrityIssue $issue */
        $issue = $this->issueFactory->createFromArray($issueData->toArray() + [
            'entity' => $metadata->getName(),
            'field' => $field,
            'file' => $file,
        ]);

        return $issue;
    }
}
