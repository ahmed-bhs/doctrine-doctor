<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Integrity;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\MissingVersionFieldForConcurrencyAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MissingVersionFieldForConcurrencyAnalyzerTest extends TestCase
{
    private MissingVersionFieldForConcurrencyAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../../Fixtures/Entity/MissingVersionFieldTest',
        ]);

        $this->analyzer = new MissingVersionFieldForConcurrencyAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        self::assertInstanceOf(AnalyzerInterface::class, $this->analyzer);
    }

    #[Test]
    public function it_detects_entity_with_for_update_and_no_version(): void
    {
        $queryData = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM AccountNoVersion WHERE id = 1 FOR UPDATE')
            ->build();

        $issues = $this->analyzer->analyze($queryData);

        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $issue = $issuesArray[0];
        self::assertInstanceOf(IntegrityIssue::class, $issue);
        self::assertStringContainsString('AccountNoVersion', $issue->getTitle());
    }

    #[Test]
    public function it_detects_entity_with_high_frequency_updates(): void
    {
        $queryData = QueryDataBuilder::create()
            ->addQuery('UPDATE AccountNoVersion SET email = ? WHERE id = 1')
            ->addQuery('UPDATE AccountNoVersion SET balance = ? WHERE id = 1')
            ->build();

        $issues = $this->analyzer->analyze($queryData);

        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $issue = $issuesArray[0];
        self::assertStringContainsString('AccountNoVersion', $issue->getTitle());
    }

    #[Test]
    public function it_detects_entity_in_explicit_transaction(): void
    {
        $queryData = QueryDataBuilder::create()
            ->addQuery('START TRANSACTION')
            ->addQuery('UPDATE AccountNoVersion SET balance = ? WHERE id = 1')
            ->addQuery('COMMIT')
            ->build();

        $issues = $this->analyzer->analyze($queryData);

        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $issue = $issuesArray[0];
        self::assertStringContainsString('AccountNoVersion', $issue->getTitle());
    }

    #[Test]
    public function it_detects_entity_with_begin_transaction(): void
    {
        $queryData = QueryDataBuilder::create()
            ->addQuery('BEGIN')
            ->addQuery('UPDATE AccountNoVersion SET email = ? WHERE id = 1')
            ->addQuery('COMMIT')
            ->build();

        $issues = $this->analyzer->analyze($queryData);

        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
    }

    #[Test]
    public function it_does_not_flag_entity_with_version_field(): void
    {
        $queryData = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM AccountVersioned WHERE id = 1 FOR UPDATE')
            ->build();

        $issues = $this->analyzer->analyze($queryData);

        $issuesArray = $issues->toArray();
        self::assertEmpty($issuesArray);
    }

    #[Test]
    public function it_does_not_flag_entity_without_write_signals(): void
    {
        $queryData = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM ReadOnlyRecord WHERE id = 1')
            ->addQuery('SELECT * FROM ReadOnlyRecord WHERE created_at > ?')
            ->build();

        $issues = $this->analyzer->analyze($queryData);

        $issuesArray = $issues->toArray();
        self::assertEmpty($issuesArray);
    }

    #[Test]
    public function it_does_not_flag_when_no_queries(): void
    {
        $queryData = QueryDataCollection::empty();

        $issues = $this->analyzer->analyze($queryData);

        $issuesArray = $issues->toArray();
        self::assertEmpty($issuesArray);
    }

    #[Test]
    public function it_returns_warning_severity(): void
    {
        $queryData = QueryDataBuilder::create()
            ->addQuery('UPDATE AccountNoVersion SET balance = ? WHERE id = 1')
            ->addQuery('UPDATE AccountNoVersion SET email = ? WHERE id = 1')
            ->build();

        $issues = $this->analyzer->analyze($queryData);

        $issuesArray = $issues->toArray();
        self::assertNotEmpty($issuesArray);

        foreach ($issuesArray as $issue) {
            self::assertEquals(Severity::WARNING, $issue->getSeverity());
        }
    }

    #[Test]
    public function it_provides_suggestion(): void
    {
        $queryData = QueryDataBuilder::create()
            ->addQuery('START TRANSACTION')
            ->addQuery('UPDATE AccountNoVersion SET balance = ? WHERE id = 1')
            ->build();

        $issues = $this->analyzer->analyze($queryData);

        $issuesArray = $issues->toArray();
        self::assertNotEmpty($issuesArray);

        foreach ($issuesArray as $issue) {
            $suggestion = $issue->getSuggestion();
            self::assertNotNull($suggestion);

            $code = $suggestion->getCode();
            self::assertStringContainsString('ORM\Version', $code);
        }
    }
}
