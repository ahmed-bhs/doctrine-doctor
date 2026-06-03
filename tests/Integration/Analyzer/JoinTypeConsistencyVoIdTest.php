<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\JoinTypeConsistencyAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JoinTypeConsistencyVoIdTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }
    }

    #[Test]
    public function it_does_not_flag_count_inner_join_on_many_to_one_with_value_object_id(): void
    {
        $sql = 'SELECT COUNT(r0_.id) AS sclr_0 '
            . 'FROM request_referencing_organization r0_ '
            . 'INNER JOIN organization_with_vo_id o1_ ON r0_.organization_id = o1_.id';

        $queries = QueryDataBuilder::create()->addQuery($sql, 1.0)->build();

        $issues = $this->createAnalyzer()->analyze($queries)->toArray();

        $aggregationIssues = array_filter(
            $issues,
            static fn ($issue): bool => str_contains($issue->getTitle(), 'INNER JOIN'),
        );

        self::assertCount(0, $aggregationIssues, 'A ManyToOne join whose target has a Value-Object identifier never duplicates rows and must not be flagged');
    }

    private function createAnalyzer(): JoinTypeConsistencyAnalyzer
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/JoinTypeVoIdTest',
        ]);

        return new JoinTypeConsistencyAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );
    }
}
