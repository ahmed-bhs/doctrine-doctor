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
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Listener\FlushInEventListenerTest\SafePostFlushListener;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Listener\FlushInEventListenerTest\SyncOnPostFlushListener;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use Doctrine\ORM\Events;
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

    #[Test]
    public function it_detects_flush_in_an_entity_listener(): void
    {
        $titles = $this->titles([]);

        self::assertContains('Flush in Doctrine Listener: FlushingEntityListener::postUpdate() (postUpdate)', $titles);
    }

    #[Test]
    public function it_detects_flush_in_a_post_flush_listener_and_explains_the_replayed_deletions(): void
    {
        $issues = $this->issues([new SyncOnPostFlushListener(self::createStub(\Doctrine\ORM\EntityManagerInterface::class))]);

        $issue = $this->find($issues, 'SyncOnPostFlushListener::postFlush()');
        self::assertNotNull($issue);
        self::assertSame('critical', $issue->getSeverity()->value);
        self::assertStringContainsString('replays the collection deletions', strip_tags($issue->getDescription()));
        self::assertSame('postFlush', $issue->getData()['method']);
    }

    #[Test]
    public function it_does_not_flag_a_listener_without_flush(): void
    {
        $titles = $this->titles([new SafePostFlushListener()]);

        self::assertSame([], array_values(array_filter($titles, static fn (string $t): bool => str_contains($t, 'SafePostFlushListener'))));
    }

    /**
     * @param list<object> $postFlushListeners
     * @return list<IssueInterface>
     */
    private function issues(array $postFlushListeners): array
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/FlushInEventListenerTest',
        ]);
        foreach ($postFlushListeners as $listener) {
            $entityManager->getEventManager()->addEventListener([Events::postFlush], $listener);
        }

        $analyzer = new FlushInEventListenerAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );

        return array_values($analyzer->analyzeMetadata()->toArray());
    }

    /**
     * @param list<object> $postFlushListeners
     * @return list<string>
     */
    private function titles(array $postFlushListeners): array
    {
        return array_map(static fn (IssueInterface $issue): string => $issue->getTitle(), $this->issues($postFlushListeners));
    }

    /**
     * @param list<IssueInterface> $issues
     */
    private function find(array $issues, string $needle): ?IssueInterface
    {
        foreach ($issues as $issue) {
            if (str_contains($issue->getTitle(), $needle)) {
                return $issue;
            }
        }

        return null;
    }
}
