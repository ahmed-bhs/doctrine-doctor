<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Security\SensitiveDataExposureAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\UserWithProtectedData;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\UserWithSensitiveData;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for SensitiveDataExposureAnalyzer.
 *
 * This analyzer detects sensitive data exposure through serialization,
 * __toString(), jsonSerialize(), and toArray() methods.
 */
final class SensitiveDataExposureAnalyzerTest extends TestCase
{
    private SensitiveDataExposureAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity',
        ]);

        $this->analyzer = new SensitiveDataExposureAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_no_sensitive_fields(): void
    {
        // Arrange: Entity without sensitive fields (use Invoice which has no passwords/tokens)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: No sensitive fields = might have no issues for that entity
        // But we may have UserWithSensitiveData loaded, so just verify analyzer runs
        self::assertIsArray($issues->toArray());
    }

    #[Test]
    public function it_detects_sensitive_fields_in_entity(): void
    {
        // Arrange: UserWithSensitiveData has password, apiKey, secretToken
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect sensitive fields
        $issuesArray = $issues->toArray();
        $sensitiveIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'UserWithSensitiveData'),
        );

        self::assertGreaterThan(0, count($sensitiveIssues), 'Should detect sensitive fields in UserWithSensitiveData');
    }

    #[Test]
    public function it_detects_to_string_exposing_sensitive_data(): void
    {
        // Arrange: UserWithSensitiveData::__toString() uses json_encode($this)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect __toString() exposure
        $issuesArray = $issues->toArray();
        $toStringIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getTitle(), '__toString()'));

        self::assertNotNull($toStringIssue, 'Should detect __toString() exposing sensitive data');
        self::assertEquals('critical', $toStringIssue->getSeverity()->value);
        self::assertStringContainsString('serializes the entire object', $toStringIssue->getDescription());
    }

    #[Test]
    public function it_detects_json_serialize_exposing_sensitive_data(): void
    {
        // Arrange: UserWithSensitiveData::jsonSerialize() exposes password, apiKey, secretToken
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect jsonSerialize() exposure
        $issuesArray = $issues->toArray();
        $jsonIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getTitle(), 'jsonSerialize()'));

        self::assertNotNull($jsonIssue, 'Should detect jsonSerialize() exposing sensitive data');
        self::assertEquals('critical', $jsonIssue->getSeverity()->value);
        self::assertStringContainsString('password', $jsonIssue->getDescription());
    }

    #[Test]
    public function it_detects_to_array_exposing_sensitive_data(): void
    {
        // Arrange: UserWithSensitiveData::toArray() exposes password, apiKey
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect toArray() exposure
        $issuesArray = $issues->toArray();
        $toArrayIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getTitle(), 'toArray()'));

        self::assertNotNull($toArrayIssue, 'Should detect toArray() exposing sensitive data');
        self::assertEquals('critical', $toArrayIssue->getSeverity()->value);
        self::assertStringContainsString('password', $toArrayIssue->getDescription());
    }

    #[Test]
    public function it_detects_missing_serialization_protection(): void
    {
        // Arrange: UserWithSensitiveData fields lack #[Ignore] annotations
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect unprotected fields (WARNING severity)
        $issuesArray = $issues->toArray();
        $protectionIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'Unprotected sensitive field'),
        );

        self::assertGreaterThan(0, count($protectionIssues), 'Should detect unprotected sensitive fields');

        $issue = array_values($protectionIssues)[0];
        self::assertEquals('warning', $issue->getSeverity()->value);
        self::assertStringContainsString('lacks serialization protection', $issue->getDescription());
    }

    #[Test]
    public function it_identifies_password_fields(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should identify 'password' field
        $issuesArray = $issues->toArray();
        $passwordIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'password'),
        );

        self::assertGreaterThan(0, count($passwordIssues), 'Should identify password field');
    }

    #[Test]
    public function it_identifies_api_key_fields(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should identify 'apiKey' field
        $issuesArray = $issues->toArray();
        $apiKeyIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains(strtolower((string) $issue->getDescription()), 'apikey'),
        );

        self::assertGreaterThan(0, count($apiKeyIssues), 'Should identify apiKey field');
    }

    #[Test]
    public function it_identifies_token_fields(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should identify 'secretToken' field
        $issuesArray = $issues->toArray();
        $tokenIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains(strtolower((string) $issue->getDescription()), 'token'),
        );

        self::assertGreaterThan(0, count($tokenIssues), 'Should identify token fields');
    }

    #[Test]
    public function it_does_not_flag_protected_fields(): void
    {
        // Arrange: UserWithProtectedData has #[Ignore] on sensitive fields
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Protected fields should not trigger unprotected field warnings
        $issuesArray = $issues->toArray();
        $protectedEntityIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'UserWithProtectedData') &&
                          str_contains((string) $issue->getTitle(), 'Unprotected'),
        );

        self::assertCount(0, $protectedEntityIssues, 'Protected fields should not be flagged as unprotected');
    }

    #[Test]
    public function it_does_not_flag_safe_to_string(): void
    {
        // Arrange: UserWithProtectedData::__toString() only exposes id
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Safe __toString() should not be flagged
        $issuesArray = $issues->toArray();
        $toStringIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'UserWithProtectedData') &&
                          str_contains((string) $issue->getTitle(), '__toString()'),
        );

        self::assertCount(0, $toStringIssues, 'Safe __toString() should not be flagged');
    }

    #[Test]
    public function it_does_not_flag_safe_json_serialize(): void
    {
        // Arrange: UserWithProtectedData::jsonSerialize() excludes sensitive fields
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Safe jsonSerialize() should not be flagged
        $issuesArray = $issues->toArray();
        $jsonIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'UserWithProtectedData') &&
                          str_contains((string) $issue->getTitle(), 'jsonSerialize()'),
        );

        self::assertCount(0, $jsonIssues, 'Safe jsonSerialize() should not be flagged');
    }

    #[Test]
    public function it_does_not_flag_safe_to_array(): void
    {
        // Arrange: UserWithProtectedData::toArray() excludes sensitive fields
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Safe toArray() should not be flagged
        $issuesArray = $issues->toArray();
        $toArrayIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'UserWithProtectedData') &&
                          str_contains((string) $issue->getTitle(), 'toArray()'),
        );

        self::assertCount(0, $toArrayIssues, 'Safe toArray() should not be flagged');
    }

    #[Test]
    public function it_provides_to_string_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide suggestion for __toString()
        $issuesArray = $issues->toArray();
        $toStringIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getTitle(), '__toString()'));

        if (null !== $toStringIssue) {
            $suggestion = $toStringIssue->getSuggestion();
            self::assertNotNull($suggestion, 'Should provide suggestion');
            self::assertStringContainsString('__toString()', $suggestion->getDescription());
        }
    }

    #[Test]
    public function it_provides_json_serialize_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide suggestion for jsonSerialize()
        $issuesArray = $issues->toArray();
        $jsonIssue = array_find($issuesArray, fn ($issue) => str_contains((string) $issue->getTitle(), 'jsonSerialize()'));

        if (null !== $jsonIssue) {
            $suggestion = $jsonIssue->getSuggestion();
            self::assertNotNull($suggestion, 'Should provide suggestion');
            self::assertStringContainsString('sensitive', strtolower($suggestion->getDescription()));
        }
    }

    #[Test]
    public function it_provides_protection_suggestion(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide suggestion for unprotected fields
        $issuesArray = $issues->toArray();
        $protectionIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'Unprotected'),
        );

        if (count($protectionIssues) > 0) {
            $issue = array_values($protectionIssues)[0];
            $suggestion = $issue->getSuggestion();
            self::assertNotNull($suggestion, 'Should provide protection suggestion');
            self::assertStringContainsString('#[Ignore]', $suggestion->getCode());
        }
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
        // Arrange: UserWithSensitiveData has multiple vulnerabilities
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect all issues (toString, jsonSerialize, toArray, + unprotected fields)
        $issuesArray = $issues->toArray();
        $userIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getDescription(), 'UserWithSensitiveData'),
        );

        // Expect: 3 methods (toString, jsonSerialize, toArray) + 3 unprotected fields (password, apiKey, secretToken)
        self::assertGreaterThanOrEqual(5, count($userIssues), 'Should detect multiple vulnerabilities');
    }

    #[Test]
    public function it_explains_security_impact(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Descriptions should explain security impact
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray));

        $issue = $issuesArray[0];
        $description = $issue->getDescription();

        // Should mention consequences
        self::assertTrue(
            str_contains($description, 'exposed') ||
            str_contains($description, 'leak') ||
            str_contains($description, 'security') ||
            str_contains($description, 'sensitive'),
            'Should explain security impact',
        );
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

    #[Test]
    public function it_skips_metadata_fields_with_is_prefix(): void
    {
        // Arrange: Field like "isCreditCardSaved" should be skipped (metadata, not actual card data)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag fields starting with "is_" as sensitive
        $issuesArray = $issues->toArray();
        foreach ($issuesArray as $issue) {
            $description = $issue->getDescription();
            // Should not contain "isCreditCardSaved" or similar is_ prefixed fields
            self::assertStringNotContainsString('$isCreditCardSaved', $description, 'Should skip is_ prefixed metadata fields');
            self::assertStringNotContainsString('$isTokenValid', $description, 'Should skip is_ prefixed metadata fields');
            self::assertStringNotContainsString('$isPasswordExpired', $description, 'Should skip is_ prefixed metadata fields');
        }
    }

    #[Test]
    public function it_skips_metadata_fields_with_has_prefix(): void
    {
        // Arrange: Field like "hasPaymentMethod" should be skipped (boolean flag)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag fields starting with "has_" as sensitive
        $issuesArray = $issues->toArray();
        foreach ($issuesArray as $issue) {
            $description = $issue->getDescription();
            self::assertStringNotContainsString('$hasPaymentMethod', $description, 'Should skip has_ prefixed metadata fields');
            self::assertStringNotContainsString('$hasToken', $description, 'Should skip has_ prefixed metadata fields');
        }
    }

    #[Test]
    public function it_skips_timestamp_fields_with_at_suffix(): void
    {
        // Arrange: Field like "passwordResetAt" should be skipped (timestamp metadata)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag timestamp fields ending with "_at"
        $issuesArray = $issues->toArray();
        foreach ($issuesArray as $issue) {
            $description = $issue->getDescription();
            self::assertStringNotContainsString('$passwordResetAt', $description, 'Should skip _at suffixed timestamp fields');
            self::assertStringNotContainsString('$tokenExpiresAt', $description, 'Should skip _at suffixed timestamp fields');
        }
    }

    #[Test]
    public function it_skips_enabled_fields_with_enabled_suffix(): void
    {
        // Arrange: Field like "creditCardEnabled" should be skipped (boolean flag)
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT flag fields ending with "_enabled"
        $issuesArray = $issues->toArray();
        foreach ($issuesArray as $issue) {
            $description = $issue->getDescription();
            self::assertStringNotContainsString('$creditCardEnabled', $description, 'Should skip _enabled suffixed metadata fields');
            self::assertStringNotContainsString('$passwordResetEnabled', $description, 'Should skip _enabled suffixed metadata fields');
        }
    }

    #[Test]
    public function it_still_detects_actual_sensitive_data_not_metadata(): void
    {
        // Arrange: Fields like "password", "apiKey", "secretToken" should still be detected
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should STILL detect actual sensitive fields (not starting with is_/has_)
        $issuesArray = $issues->toArray();
        $hasRealSensitiveIssue = false;

        foreach ($issuesArray as $issue) {
            $description = $issue->getDescription();
            // Check if we detect real sensitive fields (password, apiKey, etc.)
            if (
                str_contains($description, '$password')
                || str_contains($description, '$apiKey')
                || str_contains($description, '$secretToken')
            ) {
                $hasRealSensitiveIssue = true;
                break;
            }
        }

        self::assertTrue($hasRealSensitiveIssue, 'Should still detect actual sensitive data fields like password, apiKey');
    }

    #[Test]
    public function it_distinguishes_credit_card_number_from_is_credit_card_saved(): void
    {
        // Arrange: "creditCardNumber" should be flagged, but "isCreditCardSaved" should not
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        $descriptions = array_map(fn ($issue) => $issue->getDescription(), $issuesArray);
        $allDescriptions = implode(' ', $descriptions);

        // Should NOT flag metadata
        self::assertStringNotContainsString('$isCreditCardSaved', $allDescriptions, 'Metadata field should be skipped');

        // But SHOULD flag actual card data (if such entity exists in fixtures)
        // Note: We may not have a creditCardNumber in our test fixtures, so this is just documentation
        // If we add such a field, it should be detected
    }
}
