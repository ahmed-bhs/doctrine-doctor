<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Infrastructure\Strategy\MySQL;

use AhmedBhs\DoctrineDoctor\Factory\SuggestionFactory;
use AhmedBhs\DoctrineDoctor\Infrastructure\Strategy\MySQL\Analyzer\MySQLCollationAnalyzer;
use AhmedBhs\DoctrineDoctor\Issue\IssueInterface;
use AhmedBhs\DoctrineDoctor\Template\Renderer\TwigTemplateRenderer;
use AhmedBhs\DoctrineDoctor\Utils\DatabasePlatformDetector;
use AhmedBhs\DoctrineDoctor\ValueObject\Severity;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Unit tests for view collation detection in MySQLCollationAnalyzer.
 *
 * A CREATE VIEW statement freezes the session collation into the literals of its
 * definition. Querying such a column against a literal from a connection using a
 * different collation of the same character set raises error 1267.
 */
final class MySQLCollationAnalyzerViewTest extends TestCase
{
    private const string CONNECTION_COLLATION = 'utf8mb4_unicode_ci';

    #[Test]
    public function it_reports_view_columns_that_differ_from_the_connection_collation(): void
    {
        $analyzer = $this->createAnalyzer([
            [
                'TABLE_NAME'         => 'vue_pdfs',
                'COLUMN_NAME'        => 'access',
                'COLLATION_NAME'     => 'utf8mb4_general_ci',
                'CHARACTER_SET_NAME' => 'utf8mb4',
            ],
        ]);

        $issues = $this->collectViewIssues($analyzer);

        self::assertCount(1, $issues);
        self::assertStringContainsString('1 view columns', $issues[0]->getTitle());
    }

    #[Test]
    public function it_names_the_offending_view_and_column(): void
    {
        $analyzer = $this->createAnalyzer([
            [
                'TABLE_NAME'         => 'vue_pdfs',
                'COLUMN_NAME'        => 'access',
                'COLLATION_NAME'     => 'utf8mb4_general_ci',
                'CHARACTER_SET_NAME' => 'utf8mb4',
            ],
        ]);

        $description = $this->collectViewIssues($analyzer)[0]->getDescription();

        self::assertStringContainsString('vue_pdfs.access', $description);
        self::assertStringContainsString('utf8mb4_general_ci', $description);
        self::assertStringContainsString(self::CONNECTION_COLLATION, $description);
    }

    #[Test]
    public function it_flags_the_issue_as_critical(): void
    {
        $analyzer = $this->createAnalyzer([
            [
                'TABLE_NAME'         => 'vue_pdfs',
                'COLUMN_NAME'        => 'access',
                'COLLATION_NAME'     => 'utf8mb4_general_ci',
                'CHARACTER_SET_NAME' => 'utf8mb4',
            ],
        ]);

        $issue = $this->collectViewIssues($analyzer)[0];

        self::assertSame(Severity::critical()->getValue(), $issue->getSeverity()->getValue());
    }

    #[Test]
    public function it_reports_nothing_when_every_view_matches_the_connection_collation(): void
    {
        $analyzer = $this->createAnalyzer([]);

        self::assertSame([], $this->collectViewIssues($analyzer));
    }

    #[Test]
    public function it_summarises_when_more_than_five_columns_are_affected(): void
    {
        $mismatches = [];

        for ($i = 1; $i <= 7; ++$i) {
            $mismatches[] = [
                'TABLE_NAME'         => 'vue_' . $i,
                'COLUMN_NAME'        => 'label',
                'COLLATION_NAME'     => 'utf8mb4_general_ci',
                'CHARACTER_SET_NAME' => 'utf8mb4',
            ];
        }

        $description = $this->collectViewIssues($this->createAnalyzer($mismatches))[0]->getDescription();

        self::assertStringContainsString('and 2 more', $description);
    }

    /**
     * @return array<int, IssueInterface>
     */
    private function collectViewIssues(MySQLCollationAnalyzer $analyzer): array
    {
        $issues = [];

        foreach ($analyzer->analyze() as $issue) {
            if (str_contains($issue->getTitle(), 'view columns')) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * @param array<int, array<string, string>> $viewMismatches
     */
    private function createAnalyzer(array $viewMismatches): MySQLCollationAnalyzer
    {
        $connection = $this->createMock(Connection::class);
        $detector   = $this->createMock(DatabasePlatformDetector::class);

        $viewResult       = $this->createMock(Result::class);
        $collationResult  = $this->createMock(Result::class);
        $fallbackResult   = $this->createMock(Result::class);

        $connection->method('getDatabase')->willReturn('app');
        $connection->method('executeQuery')->willReturnCallback(
            function (string $sql) use ($viewResult, $collationResult, $fallbackResult): Result {
                if (str_contains($sql, 'information_schema.VIEWS')) {
                    return $viewResult;
                }

                if (str_contains($sql, '@@collation_connection AS collation_connection')) {
                    return $collationResult;
                }

                return $fallbackResult;
            },
        );

        $detector->method('fetchAllAssociative')->willReturnCallback(
            static fn (Result $result): array => $result === $viewResult ? $viewMismatches : [],
        );

        $detector->method('fetchAssociative')->willReturnCallback(
            static fn (Result $result): array => $result === $collationResult
                ? ['collation_connection' => self::CONNECTION_COLLATION]
                : ['DEFAULT_COLLATION_NAME' => self::CONNECTION_COLLATION],
        );

        return new MySQLCollationAnalyzer(
            $connection,
            new SuggestionFactory(new TwigTemplateRenderer(new Environment(new ArrayLoader([])))),
            $detector,
        );
    }
}
