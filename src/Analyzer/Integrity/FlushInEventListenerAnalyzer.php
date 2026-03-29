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
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\PhpCodeParser;
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

class FlushInEventListenerAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;
    use ShortClassNameTrait;

    private readonly PhpCodeParser $phpCodeParser;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly IssueFactoryInterface $issueFactory,
        ?PhpCodeParser $phpCodeParser = null,
    ) {
        $this->phpCodeParser = $phpCodeParser ?? new PhpCodeParser();
    }

    public function analyzeMetadata(): IssueCollection
    {
        return IssueCollection::fromGenerator(
            function () {
                $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

                foreach ($allMetadata as $metadata) {
                    if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                        continue;
                    }

                    yield from $this->analyzeEntity($metadata);
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Flush In Event Listener Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects flush() calls inside Doctrine lifecycle callbacks, which can cause infinite loops';
    }

    /**
     * @return iterable<IntegrityIssue>
     */
    private function analyzeEntity(ClassMetadata $metadata): iterable
    {
        if ([] === $metadata->lifecycleCallbacks) {
            return;
        }

        $reflectionClass = $metadata->getReflectionClass();

        foreach ($metadata->lifecycleCallbacks as $event => $methods) {
            foreach ($methods as $methodName) {
                if (!$reflectionClass->hasMethod($methodName)) {
                    continue;
                }

                $method = $reflectionClass->getMethod($methodName);

                if ($this->phpCodeParser->hasFlushCall($method)) {
                    yield $this->createIssue($metadata, $method, $event);
                }
            }
        }
    }

    private function createIssue(
        ClassMetadata $metadata,
        \ReflectionMethod $method,
        string $event,
    ): IntegrityIssue {
        $shortName = $metadata->getReflectionClass()->getShortName();
        $entityClass = $metadata->getName();

        $description = DescriptionHighlighter::highlight(
            '{class}::{method}() calls flush() inside a {event} lifecycle callback. '
            . 'This triggers the UnitOfWork computation again, which can re-trigger the same event, '
            . 'causing an infinite loop or unexpected side effects. '
            . 'Move this logic to an event listener service or call flush() outside the lifecycle event.',
            [
                'class' => $shortName,
                'method' => $method->getName(),
                'event' => $event,
            ],
        );

        $issueData = new IssueData(
            type: IssueType::FLUSH_IN_LIFECYCLE_CALLBACK->value,
            title: sprintf(
                'Flush in Lifecycle Callback: %s::%s() (%s)',
                $shortName,
                $method->getName(),
                $event,
            ),
            description: $description,
            severity: Severity::critical(),
            suggestion: $this->createSuggestion($entityClass, $shortName, $method, $event),
            queries: [],
            backtrace: $this->createMethodBacktrace($method),
        );

        /** @var IntegrityIssue $issue */
        $issue = $this->issueFactory->createFromArray($issueData->toArray() + [
            'entity' => $entityClass,
        ]);

        return $issue;
    }

    private function createSuggestion(
        string $entityClass,
        string $shortName,
        \ReflectionMethod $method,
        string $event,
    ): \AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/flush_in_event_listener',
            context: [
                'entity_class' => $shortName,
                'entity_fqcn' => $entityClass,
                'method_name' => $method->getName(),
                'event' => $event,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::critical(),
                title: 'Remove flush() from Lifecycle Callback',
                tags: ['infinite-loop', 'lifecycle', 'flush', 'data-integrity'],
            ),
        );
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function createMethodBacktrace(\ReflectionMethod $method): ?array
    {
        $fileName = $method->getFileName();
        $startLine = $method->getStartLine();

        if (false === $fileName || false === $startLine) {
            return null;
        }

        return [[
            'file' => $fileName,
            'line' => $startLine,
            'class' => $method->getDeclaringClass()->getName(),
            'function' => $method->getName(),
            'type' => '->',
        ]];
    }
}
