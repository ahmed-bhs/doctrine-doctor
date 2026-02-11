<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Security\SensitiveDataExposureAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Suggestion\SuggestionInterface;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\User;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\UserWithSensitiveData;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for SensitiveDataExposureAnalyzer.
 *
 * Tests detection of:
 * - Sensitive fields without protection annotations
 * - __toString() methods that expose sensitive data
 * - jsonSerialize() methods that leak passwords/tokens
 * - toArray() methods that expose sensitive information
 */
final class SensitiveDataExposureAnalyzerIntegrationTest extends DatabaseTestCase
{
    private SensitiveDataExposureAnalyzer $sensitiveDataExposureAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->sensitiveDataExposureAnalyzer = new SensitiveDataExposureAnalyzer(
            $this->entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );

        // Create schema for test entities
        $this->createSchema([
            UserWithSensitiveData::class,
            User::class,
        ]);
    }

    #[Test]
    public function it_detects_unprotected_password_field(): void
    {
        // Arrange: Entity with password field but no protection annotation
        $userWithSensitiveData = new UserWithSensitiveData(
            'john_doe',
            'john@example.com',
            'plain_text_password_123',
        );

        $this->entityManager->persist($userWithSensitiveData);
        $this->entityManager->flush();

        // Act: Analyze for sensitive data exposure
        $issues = $this->sensitiveDataExposureAnalyzer->analyze(QueryDataCollection::empty());

        // Assert: Detects unprotected password field
        self::assertGreaterThan(0, count($issues), 'Should detect at least one sensitive data issue');

        $issuesArray = $issues->toArray();
        $passwordIssue = array_find($issuesArray, fn ($issueArray) => str_contains((string) $issueArray->getTitle(), 'password'));

        self::assertInstanceOf(IssueInterface::class, $passwordIssue, 'Should detect unprotected password field');
        self::assertStringContainsString('lacks serialization protection', (string) $passwordIssue->getDescription(), 'Should mention lack of protection');
    }

    #[Test]
    public function it_detects_unprotected_api_key_field(): void
    {
        // Arrange: Entity with apiKey field
        $userWithSensitiveData = new UserWithSensitiveData('jane', 'jane@example.com', 'pass');
        $userWithSensitiveData->setApiKey('sk_live_abc123xyz789');

        $this->entityManager->persist($userWithSensitiveData);
        $this->entityManager->flush();

        // Act
        $issues = $this->sensitiveDataExposureAnalyzer->analyze(QueryDataCollection::empty());

        // Assert: Detects unprotected API key
        self::assertGreaterThan(0, count($issues));

        $issuesArray = $issues->toArray();
        $apiKeyIssue = array_find($issuesArray, fn ($issueArray) => str_contains((string) $issueArray->getTitle(), 'apiKey'));

        self::assertInstanceOf(IssueInterface::class, $apiKeyIssue, 'Should detect unprotected apiKey field');
    }

    #[Test]
    public function it_detects_sensitive_data_in_to_string_method(): void
    {
        // Arrange: Entity with __toString() that uses json_encode($this)
        $userWithSensitiveData = new UserWithSensitiveData('admin', 'admin@example.com', 'secret_password');
        $userWithSensitiveData->setSecretToken('secret_token_xyz');

        $this->entityManager->persist($userWithSensitiveData);
        $this->entityManager->flush();

        // Act
        $issues = $this->sensitiveDataExposureAnalyzer->analyze(QueryDataCollection::empty());

        // Assert: Detects dangerous __toString() method
        self::assertGreaterThan(0, count($issues));

        $issuesArray = $issues->toArray();
        $toStringIssue = array_find($issuesArray, fn ($issueArray) => str_contains((string) $issueArray->getTitle(), '__toString()'));

        self::assertInstanceOf(IssueInterface::class, $toStringIssue, 'Should detect sensitive data exposure in __toString()');
        self::assertStringContainsString('serializes the entire object', (string) $toStringIssue->getDescription(), 'Should warn about serializing entire object');
        self::assertSame('critical', $toStringIssue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_sensitive_data_in_json_serialize_method(): void
    {
        // Arrange: Entity with jsonSerialize() exposing password
        $userWithSensitiveData = new UserWithSensitiveData('api_user', 'api@example.com', 'api_password_123');

        $this->entityManager->persist($userWithSensitiveData);
        $this->entityManager->flush();

        // Act
        $issues = $this->sensitiveDataExposureAnalyzer->analyze(QueryDataCollection::empty());

        // Assert: Detects password exposure in jsonSerialize()
        self::assertGreaterThan(0, count($issues));

        $issuesArray = $issues->toArray();
        $jsonIssue = array_find($issuesArray, fn ($issueArray) => str_contains((string) $issueArray->getTitle(), 'jsonSerialize()'));

        self::assertInstanceOf(IssueInterface::class, $jsonIssue, 'Should detect sensitive data in jsonSerialize()');
        self::assertStringContainsString('exposes sensitive fields', (string) $jsonIssue->getDescription());
        self::assertSame('critical', $jsonIssue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_sensitive_data_in_to_array_method(): void
    {
        // Arrange: Entity with toArray() exposing password
        $userWithSensitiveData = new UserWithSensitiveData('array_user', 'array@example.com', 'array_password');
        $userWithSensitiveData->setApiKey('api_key_exposed');

        $this->entityManager->persist($userWithSensitiveData);
        $this->entityManager->flush();

        // Act
        $issues = $this->sensitiveDataExposureAnalyzer->analyze(QueryDataCollection::empty());

        // Assert: Detects password/apiKey exposure in toArray()
        self::assertGreaterThan(0, count($issues));

        $issuesArray = $issues->toArray();
        $arrayIssue = array_find($issuesArray, fn ($issueArray) => str_contains((string) $issueArray->getTitle(), 'toArray()'));

        self::assertInstanceOf(IssueInterface::class, $arrayIssue, 'Should detect sensitive data in toArray()');
        self::assertStringContainsString('exposes sensitive fields', (string) $arrayIssue->getDescription());
    }

    #[Test]
    public function it_does_not_flag_entities_without_sensitive_fields(): void
    {
        // Arrange: Regular User entity without sensitive fields (or with proper protection)
        $user = new User();
        $user->setName('Regular User');
        $user->setEmail('regular@example.com');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Act: Analyze only the User class
        $issues = $this->sensitiveDataExposureAnalyzer->analyze(QueryDataCollection::empty());

        // Assert: Should not flag User entity
        $issuesArray = $issues->toArray();
        $userIssues = array_filter($issuesArray, fn (IssueInterface $issue): bool => str_contains($issue->getTitle(), 'User::')
            && !str_contains($issue->getTitle(), 'UserWithSensitiveData'));

        self::assertCount(0, $userIssues, 'Should not flag entities without sensitive fields');
    }

    #[Test]
    public function it_detects_all_sensitive_field_patterns(): void
    {
        // Arrange: Create entity with multiple sensitive fields
        $userWithSensitiveData = new UserWithSensitiveData('test', 'test@example.com', 'password123');
        $userWithSensitiveData->setApiKey('sk_test_api_key');
        $userWithSensitiveData->setSecretToken('secret_token_value');

        $this->entityManager->persist($userWithSensitiveData);
        $this->entityManager->flush();

        // Act
        $issues = $this->sensitiveDataExposureAnalyzer->analyze(QueryDataCollection::empty());

        // Assert: Should detect multiple issues
        self::assertGreaterThanOrEqual(3, count($issues), 'Should detect multiple sensitive data issues (password, apiKey, secretToken)');

        $issuesArray = $issues->toArray();
        $titles = array_map(fn (IssueInterface $issue): string => $issue->getTitle(), $issuesArray);
        $allTitles = implode(' | ', $titles);

        // At least one issue should mention password
        self::assertMatchesRegularExpression('/password/i', $allTitles, 'Should detect password field');
    }

    #[Test]
    public function it_provides_actionable_suggestions(): void
    {
        // Arrange
        $userWithSensitiveData = new UserWithSensitiveData('suggestion_test', 'test@example.com', 'password');

        $this->entityManager->persist($userWithSensitiveData);
        $this->entityManager->flush();

        // Act
        $issues = $this->sensitiveDataExposureAnalyzer->analyze(QueryDataCollection::empty());

        // Assert: All issues should have suggestions
        self::assertGreaterThan(0, count($issues));

        foreach ($issues->toArray() as $issue) {
            self::assertInstanceOf(SuggestionInterface::class, $issue->getSuggestion(), 'Each issue should have a suggestion');

            $suggestion = $issue->getSuggestion();
            self::assertNotEmpty($suggestion->getDescription(), 'Suggestion should have a description');
        }
    }

    #[Test]
    public function it_demonstrates_real_security_risk(): void
    {
        // Arrange: Create user with sensitive data
        $userWithSensitiveData = new UserWithSensitiveData('hacker_target', 'target@example.com', 'super_secret_pass');
        $userWithSensitiveData->setApiKey('sk_live_production_key');

        $this->entityManager->persist($userWithSensitiveData);
        $this->entityManager->flush();

        // Demonstrate: What happens when __toString() is called (e.g., in logs)
        $stringRepresentation = (string) $userWithSensitiveData;

        // This is dangerous! The password is now in the string representation
        self::assertStringContainsString('super_secret_pass', $stringRepresentation, 'PASSWORD IS EXPOSED IN STRING REPRESENTATION - THIS IS THE SECURITY RISK!');

        // Demonstrate: What happens with JSON serialization (e.g., API response)
        $jsonData = $userWithSensitiveData->jsonSerialize();

        self::assertArrayHasKey('password', $jsonData, 'Password is exposed in JSON!');
        self::assertArrayHasKey('apiKey', $jsonData, 'API key is exposed in JSON!');

        // Act: Analyzer should detect this
        $issues = $this->sensitiveDataExposureAnalyzer->analyze(QueryDataCollection::empty());

        // Assert: Should have detected these vulnerabilities
        self::assertGreaterThan(0, count($issues), 'Analyzer MUST detect these critical security vulnerabilities');
    }
}
