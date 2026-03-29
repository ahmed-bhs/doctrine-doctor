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
use AhmedBhs\DoctrineDoctor\Analyzer\Integrity\ManyToManyWithExtraColumnsAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ManyToManyTest\EnrollmentOwner;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ManyToManyTest\EnrollmentTarget;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ManyToManyTest\TeacherOwner;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\ManyToManyTest\TeacherTarget;
use AhmedBhs\DoctrineDoctor\Tests\Integration\DatabaseTestCase;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use PHPUnit\Framework\Attributes\Test;

final class ManyToManyWithExtraColumnsAnalyzerTest extends DatabaseTestCase
{
    private ManyToManyWithExtraColumnsAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = new ManyToManyWithExtraColumnsAnalyzer(
            $this->entityManager,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
        );
    }

    #[Test]
    public function it_implements_analyzer_interface(): void
    {
        self::assertInstanceOf(AnalyzerInterface::class, $this->analyzer);
    }

    #[Test]
    public function it_detects_many_to_many_with_extra_column(): void
    {
        $this->createSchema([TeacherOwner::class, TeacherTarget::class, EnrollmentOwner::class, EnrollmentTarget::class]);

        // Add extra columns to orderform2m_productform2m join table
        $this->connection->executeStatement(
            'ALTER TABLE enrollmentowner_enrollmenttarget ADD COLUMN quantity INTEGER DEFAULT 1',
        );

        $issues = $this->analyzer->analyzeMetadata();
        $issuesArray = $issues->toArray();

        $orderProductIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'products'),
        );

        self::assertNotEmpty($orderProductIssues, 'Should detect join table with extra columns');
    }

    #[Test]
    public function it_does_not_flag_many_to_many_without_extra_columns(): void
    {
        $this->createSchema([TeacherOwner::class, TeacherTarget::class]);

        $issues = $this->analyzer->analyzeMetadata();
        $issuesArray = $issues->toArray();

        $studentCourseIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains((string) $issue->getTitle(), 'courses'),
        );

        self::assertEmpty($studentCourseIssues, 'Should not flag ManyToMany without extra columns');
    }

    #[Test]
    public function it_ignores_inverse_side_associations(): void
    {
        $this->createSchema([TeacherOwner::class, TeacherTarget::class]);

        $issues = $this->analyzer->analyzeMetadata();
        $issuesArray = $issues->toArray();

        // Course->students is the inverse side (mappedBy: 'courses')
        // It should not be analyzed
        $courseStudentsIssues = array_filter(
            $issuesArray,
            fn ($issue) => str_contains($issue->getDescription(), 'Course')
                && str_contains($issue->getDescription(), 'students'),
        );

        self::assertEmpty($courseStudentsIssues, 'Should not flag inverse side associations');
    }

    #[Test]
    public function it_does_not_flag_when_join_table_does_not_exist(): void
    {
        // Don't create schema - join tables won't exist
        $issues = $this->analyzer->analyzeMetadata();
        $issuesArray = $issues->toArray();

        self::assertEmpty($issuesArray, 'Should not flag when join tables do not exist');
    }

    #[Test]
    public function it_returns_warning_severity(): void
    {
        $this->createSchema([EnrollmentOwner::class, EnrollmentTarget::class]);

        $this->connection->executeStatement(
            'ALTER TABLE enrollmentowner_enrollmenttarget ADD COLUMN quantity INTEGER DEFAULT 1',
        );

        $issues = $this->analyzer->analyzeMetadata();
        $issuesArray = $issues->toArray();

        self::assertNotEmpty($issuesArray);
        foreach ($issuesArray as $issue) {
            self::assertEquals(Severity::WARNING, $issue->getSeverity());
        }
    }

    #[Test]
    public function it_provides_suggestion(): void
    {
        $this->createSchema([EnrollmentOwner::class, EnrollmentTarget::class]);

        $this->connection->executeStatement(
            'ALTER TABLE enrollmentowner_enrollmenttarget ADD COLUMN quantity INTEGER DEFAULT 1',
        );

        $issues = $this->analyzer->analyzeMetadata();
        $issuesArray = $issues->toArray();

        self::assertNotEmpty($issuesArray);
        foreach ($issuesArray as $issue) {
            $suggestion = $issue->getSuggestion();
            self::assertNotNull($suggestion, 'Every issue should have a suggestion');

            $code = $suggestion->getCode();
            self::assertStringContainsString('OneToMany', $code);
            self::assertStringContainsString('Enrollment', $code);
        }
    }

    #[Test]
    public function it_includes_extra_column_names_in_description(): void
    {
        $this->createSchema([EnrollmentOwner::class, EnrollmentTarget::class]);

        $this->connection->executeStatement(
            'ALTER TABLE enrollmentowner_enrollmenttarget ADD COLUMN quantity INTEGER DEFAULT 1',
        );
        $this->connection->executeStatement(
            'ALTER TABLE enrollmentowner_enrollmenttarget ADD COLUMN discount_percent DECIMAL(5, 2) DEFAULT 0',
        );

        $issues = $this->analyzer->analyzeMetadata();
        $issuesArray = $issues->toArray();

        self::assertNotEmpty($issuesArray);
        foreach ($issuesArray as $issue) {
            $description = $issue->getDescription();
            self::assertStringContainsString('quantity', strtolower($description));
            self::assertStringContainsString('discount_percent', strtolower($description));
        }
    }
}
