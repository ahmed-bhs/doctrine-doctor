<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Analyzer\Concern;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Concern\MetadataAnalyzerTrait;
use AhmedBhs\DoctrineDoctor\Analyzer\MetadataAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetadataAnalyzerTrait::class)]
final class MetadataAnalyzerTraitTest extends TestCase
{
    #[Test]
    public function it_delegates_analyze_to_analyze_metadata(): void
    {
        $expectedCollection = IssueCollection::fromArray([]);

        $analyzer = new class($expectedCollection) implements MetadataAnalyzerInterface {
            use MetadataAnalyzerTrait;

            public function __construct(
                private readonly IssueCollection $collection,
            ) {
            }

            public function analyzeMetadata(): IssueCollection
            {
                return $this->collection;
            }
        };

        $result = $analyzer->analyze(QueryDataCollection::empty());

        self::assertSame($expectedCollection, $result);
    }

    #[Test]
    public function it_ignores_query_data_collection_parameter(): void
    {
        $expectedCollection = IssueCollection::fromArray([]);

        $analyzer = new class($expectedCollection) implements MetadataAnalyzerInterface {
            use MetadataAnalyzerTrait;

            public function __construct(
                private readonly IssueCollection $collection,
            ) {
            }

            public function analyzeMetadata(): IssueCollection
            {
                return $this->collection;
            }
        };

        $queryData = QueryDataCollection::fromArray([
            [
                'sql' => 'SELECT * FROM users',
                'executionTime' => 0.01,
                'params' => [],
            ],
        ]);

        $result = $analyzer->analyze($queryData);

        self::assertSame($expectedCollection, $result);
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        $analyzer = new class() implements MetadataAnalyzerInterface {
            use MetadataAnalyzerTrait;

            public function analyzeMetadata(): IssueCollection
            {
                return IssueCollection::fromArray([]);
            }
        };

        self::assertInstanceOf(AnalyzerInterface::class, $analyzer);
        self::assertInstanceOf(MetadataAnalyzerInterface::class, $analyzer);
    }
}
