<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Configuration\DoctrineCacheAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DoctrineCacheAnalyzer reads proxy auto-generation from the running
 * EntityManager, so it also sees values overridden after the configuration
 * files are loaded. The setting only applies to generated proxies, that is
 * when native lazy objects are disabled.
 */
final class DoctrineCacheAnalyzerRuntimeProxyTest extends TestCase
{
    #[Test]
    public function it_marks_the_runtime_source_in_its_title(): void
    {
        $titles = $this->proxyTitles(autoGenerate: 1, environment: 'prod');

        self::assertNotEmpty($titles);

        foreach ($titles as $title) {
            self::assertStringContainsString('runtime', $title);
        }
    }

    #[Test]
    public function it_stays_silent_when_auto_generation_is_disabled(): void
    {
        self::assertEmpty($this->proxyTitles(autoGenerate: 0, environment: 'prod'));
    }

    #[Test]
    public function it_stays_silent_outside_production(): void
    {
        self::assertEmpty($this->proxyTitles(autoGenerate: 1, environment: 'dev'));
    }

    /**
     * @param 0|1 $autoGenerate
     * @return list<string>
     */
    private function proxyTitles(int $autoGenerate, string $environment): array
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager();
        $entityManager->getConfiguration()->setAutoGenerateProxyClasses($autoGenerate);
        // Generated proxies: the only mode in which auto_generate_proxy_classes applies.
        $entityManager->getConfiguration()->enableNativeLazyObjects(false);

        $analyzer = new DoctrineCacheAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            $environment,
        );

        $titles = [];

        foreach ($analyzer->analyze(QueryDataCollection::empty())->toArray() as $issue) {
            if (str_contains(strtolower($issue->getTitle()), 'proxy auto-generation')) {
                $titles[] = $issue->getTitle();
            }
        }

        return $titles;
    }
}
