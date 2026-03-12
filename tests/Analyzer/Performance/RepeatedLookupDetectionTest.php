<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Performance;

use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlPatternDetector;
use AhmedBhs\DoctrineDoctor\Analyzer\Performance\NPlusOneAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RepeatedLookupDetectionTest extends TestCase
{
    private NPlusOneAnalyzer $analyzer;

    private SqlPatternDetector $patternDetector;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity',
        ]);

        $this->analyzer = new NPlusOneAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            5,
        );

        $this->patternDetector = new SqlPatternDetector();
    }

    #[Test]
    public function it_detects_repeated_lookup_by_slug(): void
    {
        $builder = QueryDataBuilder::create();

        for ($i = 0; $i < 5; ++$i) {
            $builder->addQuery(
                'SELECT t0.id AS id_1, t0.slug AS slug_2, t0.value AS value_3 FROM configuration_item t0 WHERE t0.slug = ?',
                0.5,
            );
        }

        $issues = $this->analyzer->analyze($builder->build());
        $issuesArray = $issues->toArray();

        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('lookup', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_detects_repeated_lookup_by_email(): void
    {
        $builder = QueryDataBuilder::create();

        for ($i = 0; $i < 6; ++$i) {
            $builder->addQuery(
                'SELECT t0.id AS id_1, t0.email AS email_2, t0.name AS name_3 FROM users t0 WHERE t0.email = ?',
                0.3,
            );
        }

        $issues = $this->analyzer->analyze($builder->build());
        $issuesArray = $issues->toArray();

        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('lookup', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_detects_repeated_lookup_by_code(): void
    {
        $builder = QueryDataBuilder::create();

        for ($i = 0; $i < 5; ++$i) {
            $builder->addQuery(
                'SELECT t0.id AS id_1, t0.code AS code_2 FROM country t0 WHERE t0.code = ?',
                0.2,
            );
        }

        $issues = $this->analyzer->analyze($builder->build());

        self::assertCount(1, $issues->toArray());
    }

    #[Test]
    public function it_includes_lookup_description_with_actionable_advice(): void
    {
        $builder = QueryDataBuilder::create();

        for ($i = 0; $i < 5; ++$i) {
            $builder->addQuery(
                'SELECT t0.id AS id_1, t0.slug AS slug_2 FROM configuration_item t0 WHERE t0.slug = ?',
                0.5,
            );
        }

        $issues = $this->analyzer->analyze($builder->build());
        $issuesArray = $issues->toArray();

        self::assertStringContainsString('Repeated Lookup', $issuesArray[0]->getDescription());
        self::assertStringContainsString('IN query', $issuesArray[0]->getDescription());
    }

    // --- Pattern Detector unit tests ---

    #[Test]
    public function pattern_detector_detects_slug_lookup(): void
    {
        $result = $this->patternDetector->detectRepeatedLookupPattern(
            'SELECT t0.id AS id_1, t0.slug AS slug_2 FROM configuration_item t0 WHERE t0.slug = ?',
        );

        self::assertNotNull($result);
        self::assertSame('configuration_item', $result['table']);
        self::assertSame('slug', $result['column']);
    }

    #[Test]
    public function pattern_detector_detects_email_lookup(): void
    {
        $result = $this->patternDetector->detectRepeatedLookupPattern(
            "SELECT t0.id FROM users t0 WHERE t0.email = 'test@example.com'",
        );

        self::assertNotNull($result);
        self::assertSame('users', $result['table']);
        self::assertSame('email', $result['column']);
    }

    // --- False positive prevention ---

    #[Test]
    public function it_does_not_classify_id_lookup_as_repeated_lookup(): void
    {
        $result = $this->patternDetector->detectRepeatedLookupPattern(
            'SELECT t0.id AS id_1, t0.name AS name_2 FROM users t0 WHERE t0.id = ?',
        );

        self::assertNull($result, 'WHERE id = ? should be classified as proxy, not lookup');
    }

    #[Test]
    public function it_does_not_classify_foreign_key_as_repeated_lookup(): void
    {
        $result = $this->patternDetector->detectRepeatedLookupPattern(
            'SELECT t0.id AS id_1, t0.title AS title_2 FROM articles t0 WHERE t0.category_id = ?',
        );

        self::assertNull($result, 'WHERE category_id = ? should be classified as collection N+1, not lookup');
    }

    #[Test]
    public function it_does_not_classify_query_with_joins_as_repeated_lookup(): void
    {
        $result = $this->patternDetector->detectRepeatedLookupPattern(
            'SELECT t0.id FROM users t0 INNER JOIN user_role ur ON t0.id = ur.user_id WHERE t0.email = ?',
        );

        self::assertNull($result, 'Query with JOINs should not be classified as simple repeated lookup');
    }

    #[Test]
    public function it_does_not_classify_query_without_where_as_repeated_lookup(): void
    {
        $result = $this->patternDetector->detectRepeatedLookupPattern(
            'SELECT t0.id, t0.slug FROM configuration_item t0',
        );

        self::assertNull($result);
    }

    #[Test]
    public function it_does_not_classify_multi_condition_where_as_repeated_lookup(): void
    {
        $result = $this->patternDetector->detectRepeatedLookupPattern(
            'SELECT t0.id FROM users t0 WHERE t0.email = ? AND t0.status = ?',
        );

        self::assertNull($result, 'Multi-condition WHERE with two non-key columns should not match');
    }

    #[Test]
    public function it_does_not_detect_below_threshold(): void
    {
        $builder = QueryDataBuilder::create();

        for ($i = 0; $i < 4; ++$i) {
            $builder->addQuery(
                'SELECT t0.id AS id_1, t0.slug AS slug_2 FROM configuration_item t0 WHERE t0.slug = ?',
                0.5,
            );
        }

        $issues = $this->analyzer->analyze($builder->build());

        self::assertCount(0, $issues->toArray(), 'Should not detect N+1 below threshold of 5');
    }

    #[Test]
    public function it_does_not_classify_insert_as_repeated_lookup(): void
    {
        $result = $this->patternDetector->detectRepeatedLookupPattern(
            "INSERT INTO configuration_item (slug, value) VALUES ('test', 'value')",
        );

        self::assertNull($result);
    }

    #[Test]
    public function it_does_not_classify_update_as_repeated_lookup(): void
    {
        $result = $this->patternDetector->detectRepeatedLookupPattern(
            "UPDATE configuration_item SET value = 'new' WHERE slug = 'test'",
        );

        self::assertNull($result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonLookupColumnProvider(): array
    {
        return [
            'primary key id' => ['SELECT t0.id FROM users t0 WHERE t0.id = ?'],
            'foreign key user_id' => ['SELECT t0.id FROM orders t0 WHERE t0.user_id = ?'],
            'foreign key category_id' => ['SELECT t0.id FROM articles t0 WHERE t0.category_id = ?'],
            'foreign key parent_id' => ['SELECT t0.id FROM categories t0 WHERE t0.parent_id = ?'],
        ];
    }

    #[Test]
    #[DataProvider('nonLookupColumnProvider')]
    public function it_does_not_match_id_or_foreign_key_columns(string $sql): void
    {
        $result = $this->patternDetector->detectRepeatedLookupPattern($sql);

        self::assertNull($result, sprintf('Should not classify as lookup: %s', $sql));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function lookupColumnProvider(): array
    {
        return [
            'slug column' => ['SELECT t0.id FROM items t0 WHERE t0.slug = ?', 'items', 'slug'],
            'email column' => ['SELECT t0.id FROM users t0 WHERE t0.email = ?', 'users', 'email'],
            'code column' => ['SELECT t0.id FROM countries t0 WHERE t0.code = ?', 'countries', 'code'],
            'username column' => ['SELECT t0.id FROM users t0 WHERE t0.username = ?', 'users', 'username'],
            'token column' => ['SELECT t0.id FROM sessions t0 WHERE t0.token = ?', 'sessions', 'token'],
            'locale column' => ['SELECT t0.id FROM translations t0 WHERE t0.locale = ?', 'translations', 'locale'],
        ];
    }

    #[Test]
    #[DataProvider('lookupColumnProvider')]
    public function it_detects_common_lookup_columns(string $sql, string $expectedTable, string $expectedColumn): void
    {
        $result = $this->patternDetector->detectRepeatedLookupPattern($sql);

        self::assertNotNull($result, sprintf('Should detect lookup pattern for: %s', $sql));
        self::assertSame($expectedTable, $result['table']);
        self::assertSame($expectedColumn, $result['column']);
    }
}
