<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\DTO\IssueData;
use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionMetadata;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Webmozart\Assert\Assert;

/**
 * Educational analyzer about primary key strategies (Auto-increment vs UUID).
 * This analyzer takes a NUANCED, NON-DOGMATIC approach:
 * - Does NOT claim "auto-increment is evil"
 * - Provides EDUCATIONAL information about trade-offs
 * - Suggests UUIDs only when there are legitimate reasons
 * - Recommends UUID v7 over v4 for better performance
 * - Severity is always INFO (educational, not critical)
 * Key points:
 * 1. Auto-increment is fine for 99% of applications
 * 2. UUIDs have performance costs (index size, fragmentation)
 * 3. Security through obscurity is NOT real security
 * 4. UUID v7 (sequential) is better than UUID v4 (random) for performance
 */
class PrimaryKeyStrategyAnalyzer implements \AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface
{
    /**
     * Entities that typically benefit from UUIDs.
     * These are often exposed in APIs or need distribution.
     */
    private const array UUID_CANDIDATE_ENTITIES = [
        'User',
        'Session',
        'Token',
        'ApiKey',
        'OAuth',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionFactory $suggestionFactory,
    ) {
    }

    public function analyze(mixed $subject = null): IssueCollection
    {
        return IssueCollection::fromGenerator(
            /**
             * @return \Generator<int, \AhmedBhs\DoctrineDoctor\Issue\IssueInterface, mixed, void>
             */
            function () {
                $classMetadataFactory = $this->entityManager->getMetadataFactory();
                $allMetadata = $classMetadataFactory->getAllMetadata();

                $statistics = $this->gatherStatistics($allMetadata);

                Assert::isIterable($allMetadata, '$allMetadata must be iterable');

                foreach ($allMetadata as $metadata) {
                    // Skip mapped superclasses and embeddables
                    if ($metadata->isMappedSuperclass) {
                        continue;
                    }

                    if ($metadata->isEmbeddedClass) {
                        continue;
                    }

                    // Pattern 1: Auto-increment on potentially exposed entities
                    if ($this->usesAutoIncrement($metadata) && $this->isUuidCandidate($metadata)) {
                        yield $this->createAutoIncrementEducationalIssue($metadata, $statistics);
                    }

                    // Pattern 2: UUID v4 detected (suggest v7 for better performance)
                    if ($this->usesUuidV4($metadata)) {
                        yield $this->createUuidV4PerformanceIssue($metadata);
                    }

                    // Pattern 3: Mixed strategies (inconsistency across codebase)
                    // Reported only once for the entire codebase
                }

                // Pattern 3: Report mixed strategies once
                if ($this->hasMixedStrategies($statistics)) {
                    yield $this->createMixedStrategiesIssue($statistics);
                }
            },
        );
    }

    public function getName(): string
    {
        return 'Primary Key Strategy Analyzer';
    }

    public function getDescription(): string
    {
        return 'Educational analyzer about primary key strategies (Auto-increment vs UUID) with trade-off analysis';
    }

    /**
     * Check if entity uses auto-increment strategy.
     */
    private function usesAutoIncrement(ClassMetadata $classMetadata): bool
    {
        $generatorType = $classMetadata->generatorType;

        return in_array($generatorType, [
            ClassMetadata::GENERATOR_TYPE_AUTO,
            ClassMetadata::GENERATOR_TYPE_IDENTITY,
            ClassMetadata::GENERATOR_TYPE_SEQUENCE,
        ], true);
    }

    /**
     * Check if entity uses UUID v4 (random).
     * Note: We detect by CustomIdGenerator class name containing "UuidV4" or "Uuid4".
     */
    private function usesUuidV4(ClassMetadata $classMetadata): bool
    {
        if (ClassMetadata::GENERATOR_TYPE_CUSTOM !== $classMetadata->generatorType) {
            return false;
        }

        $customGenerator = $classMetadata->customGeneratorDefinition;
        if (null === $customGenerator || !isset($customGenerator['class'])) {
            return false;
        }

        $generatorClass = $customGenerator['class'];

        // Check if it's UUID v4
        return str_contains($generatorClass, 'UuidV4') || str_contains($generatorClass, 'Uuid4');
    }

    /**
     * Check if entity is a candidate for UUID (e.g., User, Session, Token).
     */
    private function isUuidCandidate(ClassMetadata $classMetadata): bool
    {
        $shortName = $classMetadata->getReflectionClass()->getShortName();
        return array_any(self::UUID_CANDIDATE_ENTITIES, fn ($candidate) => str_contains($shortName, (string) $candidate));
    }

    /**
     * Gather statistics about ID strategies across all entities.
     * @param array<ClassMetadata> $allMetadata
     * @return array{autoIncrement: int, uuid: int, total: int, autoIncrementEntities: array<string>, uuidEntities: array<string>}
     */
    private function gatherStatistics(array $allMetadata): array
    {
        $stats = [
            'autoIncrement' => 0,
            'uuid' => 0,
            'total' => 0,
            'autoIncrementEntities' => [],
            'uuidEntities' => [],
        ];

        Assert::isIterable($allMetadata, '$allMetadata must be iterable');

        foreach ($allMetadata as $metadata) {
            if ($metadata->isMappedSuperclass) {
                continue;
            }

            if ($metadata->isEmbeddedClass) {
                continue;
            }

            $stats['total']++;

            if ($this->usesAutoIncrement($metadata)) {
                $stats['autoIncrement']++;
                $stats['autoIncrementEntities'][] = $metadata->getName();
            } elseif (ClassMetadata::GENERATOR_TYPE_CUSTOM === $metadata->generatorType) {
                $stats['uuid']++;
                $stats['uuidEntities'][] = $metadata->getName();
            }
        }

        return $stats;
    }

    /**
     * Check if codebase has mixed strategies.
     */
    private function hasMixedStrategies(array $statistics): bool
    {
        return $statistics['autoIncrement'] > 0 && $statistics['uuid'] > 0;
    }

    /**
     * Create educational issue about auto-increment.
     */
    private function createAutoIncrementEducationalIssue(ClassMetadata $classMetadata, array $statistics): IntegrityIssue
    {
        $entityName = $classMetadata->getName();
        $shortName = $classMetadata->getReflectionClass()->getShortName();

        $issueData = new IssueData(
            type: 'auto_increment_educational',
            title: sprintf('%s Uses Auto-Increment ID', $shortName),
            description: sprintf(
                "Entity '%s' uses auto-increment ID strategy. This is fine for most applications, but consider UUIDs if you need: " .
                "1) Prevention of ID enumeration in APIs, 2) Distributed system support, or 3) Data merging from multiple sources. " .
                "Trade-off: UUIDs are 4x larger and slightly slower than auto-increment. " .
                "Note: %d/%d entities in your codebase use auto-increment.",
                $entityName,
                $statistics['autoIncrement'],
                $statistics['total'],
            ),
            severity: Severity::info(),
            suggestion: $this->createAutoIncrementSuggestion($entityName, $shortName, $statistics),
            queries: [],
            backtrace: $this->createEntityBacktrace($classMetadata),
        );

        return new IntegrityIssue($issueData->toArray());
    }

    /**
     * Create issue for UUID v4 usage (suggest v7).
     */
    private function createUuidV4PerformanceIssue(ClassMetadata $classMetadata): IntegrityIssue
    {
        $entityName = $classMetadata->getName();
        $shortName = $classMetadata->getReflectionClass()->getShortName();

        $issueData = new IssueData(
            type: 'uuid_v4_performance',
            title: sprintf('%s Uses UUID v4 (Random)', $shortName),
            description: sprintf(
                "Entity '%s' uses UUID v4 (random generation). Consider UUID v7 instead for better performance. " .
                "UUID v7 is sequential (timestamp-based) like auto-increment, providing 50-200%% faster inserts and smaller indexes, " .
                "while keeping all benefits of UUIDs (uniqueness, unpredictability, distribution).",
                $entityName,
            ),
            severity: Severity::info(),
            suggestion: $this->createUuidV7Suggestion($entityName, $shortName),
            queries: [],
            backtrace: $this->createEntityBacktrace($classMetadata),
        );

        return new IntegrityIssue($issueData->toArray());
    }

    /**
     * Create issue for mixed strategies.
     */
    private function createMixedStrategiesIssue(array $statistics): IntegrityIssue
    {
        $issueData = new IssueData(
            type: 'mixed_id_strategies',
            title: 'Mixed Primary Key Strategies Detected',
            description: sprintf(
                "Your codebase uses both auto-increment (%d entities) and UUIDs (%d entities). " .
                "While this is not necessarily wrong, consider standardizing for consistency. " .
                "Mixed strategies can cause foreign key type mismatches and increase complexity.",
                $statistics['autoIncrement'],
                $statistics['uuid'],
            ),
            severity: Severity::info(),
            suggestion: $this->createMixedStrategiesSuggestion($statistics),
            queries: [],
        );

        return new IntegrityIssue($issueData->toArray());
    }

    /**
     * Create synthetic backtrace pointing to entity file.
     * @return array<int, array<string, mixed>>|null
     */
    private function createEntityBacktrace(ClassMetadata $classMetadata): ?array
    {
        try {
            $reflectionClass = $classMetadata->getReflectionClass();
            $fileName = $reflectionClass->getFileName();
            $startLine = $reflectionClass->getStartLine();

            if (false === $fileName || false === $startLine) {
                return null;
            }

            return [[
                'file' => $fileName,
                'line' => $startLine,
                'class' => $classMetadata->getName(),
                'function' => '__construct',
                'type' => '::',
            ]];
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Create suggestion for auto-increment educational.
     */
    private function createAutoIncrementSuggestion(string $entityName, string $shortName, array $statistics): mixed
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/primary_key_auto_increment',
            context: [
                'entity_name' => $entityName,
                'short_name' => $shortName,
                'auto_increment_count' => $statistics['autoIncrement'],
                'uuid_count' => $statistics['uuid'],
                'total_count' => $statistics['total'],
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::info(),
                title: 'Consider UUID for distributed or security-sensitive entities',
                tags: ['educational', 'architecture', 'uuid', 'auto-increment'],
            ),
        );
    }

    /**
     * Create suggestion for UUID v7.
     */
    private function createUuidV7Suggestion(string $entityName, string $shortName): mixed
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/primary_key_uuid_v7',
            context: [
                'entity_name' => $entityName,
                'short_name' => $shortName,
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::performance(),
                severity: Severity::info(),
                title: 'Use UUID v7 instead of v4 for better performance',
                tags: ['performance', 'uuid', 'optimization'],
            ),
        );
    }

    /**
     * Create suggestion for mixed strategies.
     */
    private function createMixedStrategiesSuggestion(array $statistics): mixed
    {
        return $this->suggestionFactory->createFromTemplate(
            templateName: 'Integrity/primary_key_mixed',
            context: [
                'auto_increment_count' => $statistics['autoIncrement'],
                'uuid_count' => $statistics['uuid'],
                'auto_increment_entities' => $statistics['autoIncrementEntities'],
                'uuid_entities' => $statistics['uuidEntities'],
            ],
            suggestionMetadata: new SuggestionMetadata(
                type: SuggestionType::integrity(),
                severity: Severity::info(),
                title: 'Consider standardizing primary key strategy',
                tags: ['consistency', 'architecture'],
            ),
        );
    }
}
