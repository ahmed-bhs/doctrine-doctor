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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;

/**
 * A field left out of every serialization group, while the other fields have groups, is not serialized by group.
 */
final class SensitiveDataExposureSerializerGroupsTest extends TestCase
{
    #[Test]
    public function it_skips_a_sensitive_field_left_out_of_the_serialization_groups(): void
    {
        self::assertSame(
            ['Unprotected sensitive field: ApiAccount::$apiToken', 'Unprotected sensitive field: PlainAccount::$password'],
            $this->titles(new ClassMetadataFactory(new AttributeLoader())),
        );
    }

    #[Test]
    public function it_names_the_groups_that_serialize_a_sensitive_field(): void
    {
        $analyzer = new SensitiveDataExposureAnalyzer(
            PlatformAnalyzerTestHelper::createTestEntityManager([__DIR__ . '/../../Fixtures/Entity/SerializerGroupsTest']),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            serializerMetadata: new ClassMetadataFactory(new AttributeLoader()),
        );

        $descriptions = array_map(static fn ($issue): string => (string) $issue->getDescription(), $analyzer->analyzeMetadata()->toArray());

        self::assertContains('The sensitive field "$apiToken" of entity "ApiAccount" is in the serialization groups account:owner: every normalization with these groups includes it.', $descriptions);
    }

    #[Test]
    public function it_reports_both_fields_without_the_serializer_mapping(): void
    {
        self::assertSame(
            ['Unprotected sensitive field: ApiAccount::$apiToken', 'Unprotected sensitive field: ApiAccount::$password', 'Unprotected sensitive field: PlainAccount::$password'],
            $this->titles(null),
        );
    }

    /**
     * @return list<string>
     */
    private function titles(?ClassMetadataFactory $serializerMetadata): array
    {
        $analyzer = new SensitiveDataExposureAnalyzer(
            PlatformAnalyzerTestHelper::createTestEntityManager([__DIR__ . '/../../Fixtures/Entity/SerializerGroupsTest']),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            serializerMetadata: $serializerMetadata,
        );

        $titles = array_values(array_filter(
            array_map(static fn ($issue): string => (string) $issue->getTitle(), $analyzer->analyzeMetadata()->toArray()),
            static fn (string $title): bool => str_starts_with($title, 'Unprotected sensitive field'),
        ));
        sort($titles);

        return $titles;
    }
}
