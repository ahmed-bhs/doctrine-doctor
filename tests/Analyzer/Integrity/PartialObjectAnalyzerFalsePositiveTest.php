<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\PartialObjectAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PartialObjectAnalyzerFalsePositiveTest extends TestCase
{
    private PartialObjectAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new PartialObjectAnalyzer(5);
    }

    #[Test]
    public function it_falsely_flags_intentional_full_entity_load_for_write_operations(): void
    {
        $builder = QueryDataBuilder::create();

        for ($i = 0; $i < 6; ++$i) {
            $builder->addQuery('SELECT u FROM User u WHERE u.status = ?', 0.5);
        }

        $collection = $builder->build();
        $issues = $this->analyzer->analyze($collection);

        $partialIssues = array_filter(
            $issues->toArray(),
            static fn ($issue): bool => str_contains($issue->getTitle(), 'Partial')
                || str_contains($issue->getTitle(), 'Full Entity'),
        );

        self::assertGreaterThanOrEqual(1, \count($partialIssues), 'Known false positive: DQL SELECT u FROM User u is flagged as full entity load, but the entity may need to be fully loaded for subsequent persist/flush operations');
    }
}
