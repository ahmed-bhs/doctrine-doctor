<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer\Helper;

use AhmedBhs\DoctrineDoctor\Analyzer\Helper\CollectionJoinDetector;
use AhmedBhs\DoctrineDoctor\Analyzer\Parser\SqlStructureExtractor;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\JoinTypeVoIdTest\MemberWithOrganizations;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\JoinTypeVoIdTest\OrganizationWithVoId;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\JoinTypeVoIdTest\RequestReferencingOrganization;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Reproduces the reported false positive: a ManyToOne join to an entity whose
 * identifier is an embedded value object (PK field path "id.value", DB column
 * "id"), where some unrelated entity elsewhere in the schema references the
 * same target via ManyToMany/OneToMany. Comparing field paths against SQL
 * column names made every join condition match neither side, falling through
 * to canBeCollection()'s table-wide fallback — which found the unrelated
 * ManyToMany and answered "yes, it's a collection" for a join that wasn't.
 */
final class CollectionJoinDetectorIntegrationTest extends DatabaseTestCase
{
    private CollectionJoinDetector $collectionJoinDetector;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();

        $this->collectionJoinDetector = new CollectionJoinDetector(
            $this->entityManager,
            new SqlStructureExtractor(),
        );

        $this->createSchema([
            OrganizationWithVoId::class,
            RequestReferencingOrganization::class,
            MemberWithOrganizations::class,
        ]);
    }

    #[Test]
    public function it_does_not_classify_a_many_to_one_join_as_a_collection_join_even_when_the_target_has_an_embedded_vo_identifier(): void
    {
        $metadataMap = $this->collectionJoinDetector->buildMetadataMap();

        $sql = 'SELECT r0_.id AS id_0 FROM request_referencing_organization r0_ '
            . 'LEFT JOIN organization_with_vo_id o1_ ON r0_.organization_id = o1_.id';

        $isCollectionJoin = $this->collectionJoinDetector->isForeignKeyInJoinedTable(
            $sql,
            'request_referencing_organization',
            'organization_with_vo_id',
            $metadataMap,
        );

        self::assertFalse(
            $isCollectionJoin,
            'A ManyToOne join must not be classified as a collection join just because some unrelated entity references the same table via ManyToMany.',
        );
    }

    #[Test]
    public function it_still_classifies_a_real_many_to_many_join_as_a_collection_join_on_the_same_vo_identifier_target(): void
    {
        $metadataMap = $this->collectionJoinDetector->buildMetadataMap();

        $sql = 'SELECT m0_.id AS id_0 FROM member_with_organizations m0_ '
            . 'INNER JOIN member_with_organizations_organization_with_vo_id j1_ ON m0_.id = j1_.member_with_organizations_id '
            . 'LEFT JOIN organization_with_vo_id o2_ ON o2_.id = j1_.organization_with_vo_id_id';

        $isCollectionJoin = $this->collectionJoinDetector->isForeignKeyInJoinedTable(
            $sql,
            'member_with_organizations',
            'organization_with_vo_id',
            $metadataMap,
        );

        self::assertTrue(
            $isCollectionJoin,
            'A genuine ManyToMany join to an embedded-VO-identifier entity must still be classified as a collection join.',
        );
    }
}
