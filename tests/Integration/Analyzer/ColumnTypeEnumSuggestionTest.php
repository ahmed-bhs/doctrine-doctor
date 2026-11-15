<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\ColumnTypeAnalyzer;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\EnumCandidateTest\Document;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use PHPUnit\Framework\Attributes\Test;

final class ColumnTypeEnumSuggestionTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is not available');
        }

        parent::setUp();
        $this->createSchema([Document::class]);

        for ($i = 1; $i <= 120; ++$i) {
            $this->connection->insert('enum_document', [
                'id' => $i,
                'status' => 0 === $i % 2 ? 'draft' : 'sent',
                'currencyCode' => 0 === $i % 2 ? 'EUR' : 'USD',
                'mimeType' => 0 === $i % 2 ? 'application/pdf' : 'image/png',
                'kind' => 'invoice',
            ]);
        }
    }

    #[Test]
    public function it_suggests_an_enum_once_and_only_for_a_closed_set(): void
    {
        $analyzer = new ColumnTypeAnalyzer($this->entityManager, PlatformAnalyzerTestHelper::createSuggestionFactory(), new IssueFactory());

        $titles = array_values(array_filter(
            array_map(static fn ($issue): string => $issue->getTitle(), $analyzer->analyzeMetadata()->toArray()),
            static fn (string $title): bool => str_contains($title, 'enum-like'),
        ));

        // status: closed set, reported once (not again for the mapped superclass).
        // currencyCode, mimeType: open lists. kind: a single value proves nothing.
        self::assertSame(['String field with enum-like values: Document::$status'], $titles);
    }
}
