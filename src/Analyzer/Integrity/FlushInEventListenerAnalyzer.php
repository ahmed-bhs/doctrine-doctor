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
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;

class FlushInEventListenerAnalyzer implements MetadataAnalyzerInterface
{
    use MetadataAnalyzerTrait;
    use ShortClassNameTrait;

    /**
     * Events dispatched while the unit of work is being flushed: a flush() there is nested in the running one.
     */
    private const array FLUSH_TIME_EVENTS = [
        Events::prePersist,
        Events::postPersist,
        Events::preUpdate,
        Events::postUpdate,
        Events::preRemove,
        Events::postRemove,
        Events::preFlush,
        Events::onFlush,
        Events::postFlush,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactoryInterface $suggestionFactory,
        private readonly IssueFactoryInterface $issueFactory,
        private readonly PhpCodeParser $phpCodeParser = new PhpCodeParser(),
    ) {
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

                yield from $this->analyzeListeners($allMetadata);
            },
        );
    }

    public function getName(): string
    {
        return 'Flush In Event Listener Analyzer';
    }

    public function getDescription(): string
    {
        return 'Detects flush() calls inside lifecycle callbacks, entity listeners and Doctrine event listeners';
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

    /**
     * Entity listeners (#[AsEntityListener], #[ORM\EntityListeners]) and listeners or subscribers registered on the
     * event manager (#[AsDoctrineListener]). Classes under vendor/ and the bundle's own listeners are skipped.
     *
     * @param array<ClassMetadata<object>> $allMetadata
     * @return iterable<IntegrityIssue>
     */
    private function analyzeListeners(array $allMetadata): iterable
    {
        $candidates = [];

        foreach ($allMetadata as $metadata) {
            foreach ($metadata->entityListeners as $event => $listeners) {
                foreach ($listeners as $listener) {
                    $candidates[] = [$listener['class'], $listener['method'], $event];
                }
            }
        }

        foreach ($this->entityManager->getEventManager()->getAllListeners() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if (\is_object($listener)) {
                    $candidates[] = [$listener::class, $event, $event];
                }
            }
        }

        $seen = [];
        foreach ($candidates as [$class, $methodName, $event]) {
            $key = $class . '::' . $methodName;
            if (isset($seen[$key]) || !\in_array($event, self::FLUSH_TIME_EVENTS, true) || !method_exists($class, $methodName)) {
                continue;
            }
            $seen[$key] = true;

            $method = new \ReflectionMethod($class, $methodName);
            $file = str_replace('\\', '/', (string) $method->getFileName());
            if (str_contains($file, '/vendor/') || str_starts_with($file, str_replace('\\', '/', \dirname(__DIR__, 2)) . '/')) {
                continue;
            }

            if ($this->phpCodeParser->hasFlushCall($method)) {
                yield $this->createListenerIssue($method, $event);
            }
        }
    }

    private function createListenerIssue(\ReflectionMethod $method, string $event): IntegrityIssue
    {
        $class = $method->getDeclaringClass();
        $shortName = $class->getShortName();

        $description = Events::postFlush === $event
            ? '{class}::{method}() calls flush() in a postFlush listener. Doctrine dispatches postFlush before it clears '
              . 'the unit of work, so this flush replays the collection deletions of the previous one: many-to-many links '
              . 'cleared then added again are deleted and not inserted back.'
            : '{class}::{method}() calls flush() in a {event} listener, while Doctrine is still flushing. The nested flush '
              . 'runs the pending work of the outer one and can fire the same event again or lose changes.';

        $issueData = new IssueData(
            type: IssueType::FLUSH_IN_LIFECYCLE_CALLBACK->value,
            title: sprintf('Flush in Doctrine Listener: %s::%s() (%s)', $shortName, $method->getName(), $event),
            description: DescriptionHighlighter::highlight($description, [
                'class' => $shortName,
                'method' => $method->getName(),
                'event' => $event,
            ]),
            severity: Severity::critical(),
            suggestion: $this->suggestionFactory->createFromTemplate(
                templateName: 'Integrity/flush_in_doctrine_listener',
                context: [
                    'listener_class' => $shortName,
                    'method_name' => $method->getName(),
                    'event' => $event,
                ],
                suggestionMetadata: new SuggestionMetadata(
                    type: SuggestionType::integrity(),
                    severity: Severity::critical(),
                    title: 'Move flush() out of the Doctrine listener',
                    tags: ['lifecycle', 'flush', 'data-integrity'],
                ),
            ),
            queries: [],
            backtrace: $this->createMethodBacktrace($method),
        );

        /** @var IntegrityIssue $issue */
        $issue = $this->issueFactory->createFromArray($issueData->toArray() + [
            'class' => $class->getName(),
            'method' => $method->getName(),
        ]);

        return $issue;
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
            . 'The nested flush recomputes the change set and can fire the same event again, causing an infinite loop or lost changes.',
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
