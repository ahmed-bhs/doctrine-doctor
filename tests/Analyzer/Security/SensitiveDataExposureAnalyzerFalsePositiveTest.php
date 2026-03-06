<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Security;

use AhmedBhs\DoctrineDoctor\Analyzer\Security\SensitiveDataExposureAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SensitiveDataExposureAnalyzerFalsePositiveTest extends TestCase
{
    private SensitiveDataExposureAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/FalsePositiveTest',
        ]);

        $this->analyzer = new SensitiveDataExposureAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_falsely_flags_secret_question_as_sensitive_field(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        $secretQuestionIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains((string) $issue->getDescription(), 'secretQuestion'),
        );

        self::assertCount(0, $secretQuestionIssues, 'secretQuestion should not match "secret" pattern -- this is a security recovery question text, not a secret value');
    }

    #[Test]
    public function it_falsely_flags_password_strength_as_sensitive_field(): void
    {
        $queries = QueryDataBuilder::create()->build();
        $issues = $this->analyzer->analyze($queries);

        $passwordStrengthIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains((string) $issue->getDescription(), 'passwordStrength'),
        );

        self::assertCount(0, $passwordStrengthIssues, 'passwordStrength should not match "password" pattern -- this is an integer score measuring password complexity, not a password value');
    }
}
