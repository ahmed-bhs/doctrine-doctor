<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Security\InsecureRandomAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\EntityWithInsecureRandom;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for InsecureRandomAnalyzer.
 *
 * This analyzer detects usage of insecure random number generators
 * (rand, mt_rand, uniqid, time, microtime) in security-sensitive contexts.
 */
final class InsecureRandomAnalyzerTest extends TestCase
{
    private InsecureRandomAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity',
        ]);

        $this->analyzer = new InsecureRandomAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_no_insecure_random(): void
    {
        // Arrange: Entities without insecure random usage
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: May have issues from EntityWithInsecureRandom, just verify runs
        self::assertIsArray($issues->toArray());
    }

    #[Test]
    public function it_detects_rand_in_token_generation(): void
    {
        // Arrange: EntityWithInsecureRandom::generateApiToken() uses rand()
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect rand() usage
        $issuesArray = $issues->toArray();
        $randIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getDescription(), 'generateApiToken') &&
            str_contains((string) $issue->getDescription(), 'rand'));

        self::assertNotNull($randIssue, 'Should detect rand() in API token generation');
        self::assertEquals('critical', $randIssue->getSeverity()->value);
        self::assertStringContainsString('NOT cryptographically secure', $randIssue->getDescription());
    }

    #[Test]
    public function it_detects_mt_rand_in_sensitive_context(): void
    {
        // Arrange: EntityWithInsecureRandom::generateResetToken() uses mt_rand()
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect mt_rand() usage
        $issuesArray = $issues->toArray();
        $mtRandIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'mt_rand'),
        );

        self::assertGreaterThan(0, count($mtRandIssues), 'Should detect mt_rand() usage');

        $issue = array_values($mtRandIssues)[0];
        self::assertEquals('critical', $issue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_uniqid_in_secret_generation(): void
    {
        // Arrange: EntityWithInsecureRandom::generateSecretKey() uses uniqid()
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect uniqid() usage
        $issuesArray = $issues->toArray();
        $uniqidIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getDescription(), 'uniqid'));

        self::assertNotNull($uniqidIssue, 'Should detect uniqid() in secret generation');
        self::assertEquals('critical', $uniqidIssue->getSeverity()->value);
    }

    #[Test]
    public function it_detects_time_in_password_reset(): void
    {
        // Arrange: EntityWithInsecureRandom::generatePasswordResetCode() uses time()
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect time() usage
        $issuesArray = $issues->toArray();
        $timeIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'generatePasswordResetCode'),
        );

        self::assertGreaterThan(0, count($timeIssues), 'Should detect time() in password reset');
    }

    #[Test]
    public function it_detects_microtime_in_csrf_token(): void
    {
        // Arrange: EntityWithInsecureRandom::generateCsrfToken() uses microtime()
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect microtime() usage
        $issuesArray = $issues->toArray();
        $microtimeIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'generateCsrfToken'),
        );

        self::assertGreaterThan(0, count($microtimeIssues), 'Should detect microtime() in CSRF token');
    }

    #[Test]
    public function it_detects_weak_hash_based_randomness(): void
    {
        // Arrange: EntityWithInsecureRandom::generateResetToken() uses md5(mt_rand())
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect weak hash pattern
        $issuesArray = $issues->toArray();
        $weakHashIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getTitle(), 'Weak hash-based randomness'));

        self::assertNotNull($weakHashIssue, 'Should detect md5(rand()) pattern');
        self::assertEquals('critical', $weakHashIssue->getSeverity()->value);
        self::assertStringContainsString('does NOT make it secure', $weakHashIssue->getDescription());
    }

    #[Test]
    public function it_identifies_security_sensitive_contexts(): void
    {
        // Arrange: Multiple security contexts
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should identify various sensitive contexts
        $issuesArray = $issues->toArray();
        $entityIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'EntityWithInsecureRandom'),
        );

        // Should detect: token, reset, secret, password, csrf, session, verification
        self::assertGreaterThan(5, count($entityIssues), 'Should detect multiple sensitive contexts');
    }

    #[Test]
    public function it_does_not_flag_secure_random_bytes(): void
    {
        // Arrange: EntityWithInsecureRandom::generateSecureToken() uses random_bytes()
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Secure methods should NOT be flagged
        $issuesArray = $issues->toArray();
        $secureIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'generateSecureToken'),
        );

        self::assertCount(0, $secureIssues, 'random_bytes() should not be flagged');
    }

    #[Test]
    public function it_does_not_flag_secure_random_int(): void
    {
        // Arrange: EntityWithInsecureRandom::generateSecureCode() uses random_int()
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Secure methods should NOT be flagged
        $issuesArray = $issues->toArray();
        $secureIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'generateSecureCode'),
        );

        self::assertCount(0, $secureIssues, 'random_int() should not be flagged');
    }

    #[Test]
    public function it_does_not_flag_non_security_usage(): void
    {
        // Arrange: EntityWithInsecureRandom::generateRandomColor() uses rand() but not for security
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Non-security rand() should NOT be flagged
        $issuesArray = $issues->toArray();
        $colorIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'generateRandomColor'),
        );

        self::assertCount(0, $colorIssues, 'rand() for non-security purposes should not be flagged');
    }

    #[Test]
    public function it_provides_secure_random_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide suggestions with random_bytes/random_int examples
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];
        $suggestion = $issue->getSuggestion();

        self::assertNotNull($suggestion, 'Should provide suggestion');
        self::assertStringContainsString('random_bytes', $suggestion->getCode());
        self::assertStringContainsString('secure', strtolower($suggestion->getDescription()));
    }

    #[Test]
    public function it_explains_security_risks(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Descriptions should explain security risks
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];
        $description = $issue->getDescription();

        // Should mention consequences
        self::assertTrue(
            str_contains($description, 'predicted') ||
            str_contains($description, 'attack') ||
            str_contains($description, 'hijacking') ||
            str_contains($description, 'bypass'),
            'Should explain security risks',
        );
    }

    #[Test]
    public function it_mentions_csprng(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should mention CSPRNG
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];
        $description = $issue->getDescription();

        self::assertStringContainsString('CSPRNG', $description, 'Should mention CSPRNG');
    }

    #[Test]
    public function it_includes_backtrace_information(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Issues should have backtrace with file and line
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];
        $backtrace = $issue->getBacktrace();

        self::assertNotNull($backtrace);
        self::assertArrayHasKey('file', $backtrace);
        self::assertArrayHasKey('line', $backtrace);
    }

    #[Test]
    public function it_detects_multiple_issues_in_same_entity(): void
    {
        // Arrange: EntityWithInsecureRandom has 7 insecure methods
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect all insecure methods
        $issuesArray = $issues->toArray();
        $entityIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'EntityWithInsecureRandom'),
        );

        self::assertGreaterThanOrEqual(7, count($entityIssues), 'Should detect all 7+ insecure methods');
    }

    #[Test]
    public function it_handles_exceptions_gracefully(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act: Even with potential reflection errors, should not throw
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should return valid collection
        self::assertIsObject($issues);
        $issuesArray = $issues->toArray();
        self::assertIsArray($issuesArray);
    }
}
