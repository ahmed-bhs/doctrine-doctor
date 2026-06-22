<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\CollectionJoinDetector;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Analyzer\UnusedEagerLoadAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\JoinTypeVoIdTest\MemberWithOrganizations;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\JoinTypeVoIdTest\OrganizationWithVoId;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\JoinTypeVoIdTest\RequestDocument;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\JoinTypeVoIdTest\RequestReferencingOrganization;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;
use PHPUnit\Framework\Attributes\Test;

/**
 * UnusedEagerLoadAnalyzer used to carry its own copy of CollectionJoinDetector's
 * logic, comparing SQL column names against getIdentifierFieldNames() instead
 * of getIdentifierColumnNames(). For a ManyToOne join to an entity with an
 * embedded value-object identifier, this made the join match neither side of
 * the PK comparison and fall through to the table-wide canBeCollection()
 * fallback, which could find an unrelated ManyToMany elsewhere in the schema
 * and misclassify a single ManyToOne join as a second collection join.
 */
final class UnusedEagerLoadAnalyzerIntegrationTest extends DatabaseTestCase
{
    private UnusedEagerLoadAnalyzer $unusedEagerLoadAnalyzer;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->unusedEagerLoadAnalyzer = new UnusedEagerLoadAnalyzer(
            entityManager: $this->entityManager,
            issueFactory: PlatformAnalyzerTestHelper::createIssueFactory(),
            suggestionFactory: PlatformAnalyzerTestHelper::createSuggestionFactory(),
            collectionJoinDetector: new CollectionJoinDetector($this->entityManager, new SqlStructureExtractor()),
        );

        $this->createSchema([
            OrganizationWithVoId::class,
            RequestReferencingOrganization::class,
            RequestDocument::class,
            MemberWithOrganizations::class,
        ]);
    }

    #[Test]
    public function it_does_not_count_a_many_to_one_join_to_a_vo_identifier_entity_as_a_second_collection_join(): void
    {
        // Two JOINs: organization_with_vo_id (ManyToOne — single row, not a
        // collection) and request_document (OneToMany — a real collection).
        // The threshold is 2 collection JOINs; the correct count here is 1.
        // Misclassifying the ManyToOne join pushes the count to 2 and fires
        // the over-eager-loading warning for a query that doesn't deserve it.
        $sql = 'SELECT DISTINCT r0_.id AS id_0, o1_.name AS name_1, d2_.id AS id_2 '
            . 'FROM request_referencing_organization r0_ '
            . 'LEFT JOIN organization_with_vo_id o1_ ON r0_.organization_id = o1_.id '
            . 'LEFT JOIN request_document d2_ ON r0_.id = d2_.request_id';

        $queryDataCollection = QueryDataCollection::fromArray([
            new QueryData(
                sql: $sql,
                executionTime: QueryExecutionTime::fromMilliseconds(1),
                params: [],
                backtrace: ['file' => __FILE__, 'line' => __LINE__], // @phpstan-ignore-line argument.type
            ),
        ]);

        $issueCollection = $this->unusedEagerLoadAnalyzer->analyze($queryDataCollection);

        self::assertCount(0, $issueCollection, 'Only request_document is a real collection join (1, below the threshold of 2); organization_with_vo_id is ManyToOne and must not be counted.');
    }
}
