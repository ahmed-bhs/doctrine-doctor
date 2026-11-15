<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\MissingSoftDeleteFilterAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Filter\SoftDeleteFilter;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MissingSoftDeleteFilterAnalyzerTest extends TestCase
{
    #[Test]
    public function it_flags_a_soft_deleted_entity_when_no_filter_hides_deleted_rows(): void
    {
        $em = PlatformAnalyzerTestHelper::createTestEntityManager([__DIR__ . '/../Fixtures/Entity/SoftDeleteFilterTest']);

        $issues = array_values(new MissingSoftDeleteFilterAnalyzer($em, PlatformAnalyzerTestHelper::createSuggestionFactory())->analyzeMetadata()->toArray());

        self::assertCount(1, $issues);
        self::assertSame('Soft Delete Without Filter: ArchivedSupplier::$deletedAt', $issues[0]->getTitle());
    }

    #[Test]
    public function it_stays_silent_when_a_soft_delete_filter_is_enabled(): void
    {
        $em = PlatformAnalyzerTestHelper::createTestEntityManager([__DIR__ . '/../Fixtures/Entity/SoftDeleteFilterTest']);
        $em->getConfiguration()->addFilter('soft_delete', SoftDeleteFilter::class);
        $em->getFilters()->enable('soft_delete');

        self::assertSame([], new MissingSoftDeleteFilterAnalyzer($em, PlatformAnalyzerTestHelper::createSuggestionFactory())->analyzeMetadata()->toArray());
    }
}
