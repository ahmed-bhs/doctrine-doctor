<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Security;

use AhmedBhs\DoctrineDoctor\Analyzer\Security\InsecureRandomAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InsecureRandomAnalyzerFalsePositiveTest extends TestCase
{
    private InsecureRandomAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/FalsePositiveTest',
        ]);

        $this->analyzer = new InsecureRandomAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_falsely_flags_rand_in_non_security_method_with_api_keyword_in_property(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        $apiKeyIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getDescription(), 'resetApiKeyTimestamp'),
        );

        self::assertCount(0, $apiKeyIssues, 'Should not flag rand() in resetApiKeyTimestamp() just because source code contains "apiKey" property reference');
    }
}
