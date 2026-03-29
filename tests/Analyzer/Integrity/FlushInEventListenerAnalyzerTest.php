<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\FlushInEventListenerAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FlushInEventListenerAnalyzerTest extends TestCase
{
    #[Test]
    public function it_detects_flush_in_lifecycle_callback(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/FlushInEventListenerTest',
        ]);

        $analyzer = new FlushInEventListenerAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $issues = $analyzer->analyzeMetadata();
        $issueArray = $issues->toArray();

        $flushIssues = array_filter(
            $issueArray,
            static fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'EntityWithFlushInCallback'),
        );

        self::assertNotEmpty($flushIssues, 'Should detect flush() in lifecycle callback');

        $issue = reset($flushIssues);
        self::assertStringContainsString('onPrePersist', $issue->getTitle());
        self::assertStringContainsString('infinite loop', $issue->getDescription());
        self::assertSame('critical', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_does_not_flag_safe_lifecycle_callback(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/FlushInEventListenerTest',
        ]);

        $analyzer = new FlushInEventListenerAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $issues = $analyzer->analyzeMetadata();
        $issueArray = $issues->toArray();

        $safeIssues = array_filter(
            $issueArray,
            static fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'EntityWithSafeCallback'),
        );

        self::assertEmpty($safeIssues, 'Should not flag lifecycle callback without flush()');
    }

    #[Test]
    public function it_ignores_entities_without_lifecycle_callbacks(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/FlushInEventListenerTest',
        ]);

        $analyzer = new FlushInEventListenerAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        $issues = $analyzer->analyzeMetadata();
        $issueArray = $issues->toArray();

        $noCallbackIssues = array_filter(
            $issueArray,
            static fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'EntityWithoutCallbacks'),
        );

        self::assertEmpty($noCallbackIssues, 'Should not flag entities without lifecycle callbacks');
    }
}
