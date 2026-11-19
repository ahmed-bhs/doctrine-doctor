<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Command;

use AhmedBhs\DoctrineDoctor\Analyzer\StaticAnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Command\AnalyzeCommand;
use AhmedBhs\DoctrineDoctor\Issue\IntegrityIssue;
use AhmedBhs\DoctrineDoctor\Service\IssueDeduplicator;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\Entity\BaseOrderWithInit;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * CI reporting: locations, stable IDs, baselines and GitHub annotations.
 */
final class AnalyzeCommandReportTest extends TestCase
{
    private string $baseline;

    protected function setUp(): void
    {
        $this->baseline = sys_get_temp_dir() . '/dd-baseline-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->baseline);
    }

    #[Test]
    public function it_reports_the_file_and_line_of_the_field(): void
    {
        $tester = $this->tester('A description.');
        $tester->execute(['--fail-on' => 'never']);

        $display = $tester->getDisplay();
        self::assertStringContainsString('tests/Fixtures/Entity/BaseOrderWithInit.php', $display);
        self::assertMatchesRegularExpression('/\s32\s+.*Items collection/', $display);
    }

    #[Test]
    public function it_keeps_the_same_id_when_only_the_description_changes(): void
    {
        $first = $this->tester('Password of user root is empty.');
        $first->execute(['--fail-on' => 'never', '--error-format' => 'json']);
        $second = $this->tester('Password of user ci is empty.');
        $second->execute(['--fail-on' => 'never', '--error-format' => 'json']);

        self::assertSame($this->ids($first), $this->ids($second));
    }

    #[Test]
    public function it_ignores_findings_listed_in_the_baseline(): void
    {
        $tester = $this->tester('A description.');
        self::assertSame(0, $tester->execute(['--generate-baseline' => $this->baseline]));
        self::assertFileExists($this->baseline);

        self::assertSame(1, $tester->execute([]));
        self::assertSame(0, $tester->execute(['--baseline' => $this->baseline]));
        self::assertStringContainsString('1 ignored by the baseline', $tester->getDisplay());
    }

    #[Test]
    public function it_prints_github_annotations_with_file_and_line(): void
    {
        $tester = $this->tester('First sentence. Second sentence.');
        $tester->execute(['--fail-on' => 'never', '--error-format' => 'github']);

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/^::warning file=tests\/Fixtures\/Entity\/BaseOrderWithInit\.php,line=32,title=Doctrine Doctor%3A Items collection::First sentence\. Second sentence\.%0Aphp bin\/console doctrine:doctor:analyze --show-suggestion=[a-f0-9]{10}$/m', $display);
    }

    #[Test]
    public function it_rejects_an_unknown_error_format(): void
    {
        self::assertSame(2, $this->tester('x')->execute(['--error-format' => 'xml']));
    }

    #[Test]
    public function it_prefers_an_explicit_line_to_a_class_read_from_the_title(): void
    {
        $locator = new \AhmedBhs\DoctrineDoctor\Issue\FindingLocator(\dirname(__DIR__, 3));
        $issue = new \AhmedBhs\DoctrineDoctor\Issue\PerformanceIssue([
            'type' => 'collection_filtered_in_php',
            'title' => 'Collection Filtered in PHP: BaseOrderWithInit::items()',
            'description' => 'A description.',
            'severity' => 'warning',
            'file' => \dirname(__DIR__, 3) . '/tests/Fixtures/Entity/BaseOrderWithInit.php',
            'line' => 40,
            'queries' => [],
        ]);

        $location = $locator->locate($issue);

        self::assertSame('tests/Fixtures/Entity/BaseOrderWithInit.php', $location->file);
        self::assertSame(40, $location->line);
    }

    #[Test]
    public function it_prints_the_type_and_the_fix_like_phpstan(): void
    {
        $tester = $this->tester('A description.');
        $tester->execute(['--fail-on' => 'never']);

        $display = $tester->getDisplay();
        self::assertStringContainsString('🪪  test_finding', $display);
        self::assertMatchesRegularExpression('/💡 Details: --show-suggestion=[a-f0-9]{10}/', $display);
    }

    #[Test]
    public function it_shows_the_requested_suggestion_alone_even_when_the_finding_is_in_the_baseline(): void
    {
        $tester = $this->tester('A description.');
        $tester->execute(['--fail-on' => 'never']);
        self::assertSame(1, preg_match('/--show-suggestion=([a-f0-9]{10})/', $tester->getDisplay(), $matches));
        $id = $matches[1];

        $baseline = (string) tempnam(sys_get_temp_dir(), 'dd-baseline');
        $tester->execute(['--generate-baseline' => $baseline]);
        $tester->execute(['--baseline' => $baseline, '--show-suggestion' => $id]);
        unlink($baseline);

        self::assertStringContainsString('Suggestion for ' . $id, $tester->getDisplay());
        self::assertStringNotContainsString('Findings', $tester->getDisplay());
        self::assertSame(0, $tester->getStatusCode());
    }

    #[Test]
    public function it_keeps_a_finding_in_the_baseline_when_only_its_type_was_renamed(): void
    {
        $tester = $this->tester('A description.');
        $baseline = (string) tempnam(sys_get_temp_dir(), 'dd-baseline');
        $tester->execute(['--generate-baseline' => $baseline]);

        // The baseline of an older version: another type, so another ID, same title and file.
        $data = json_decode((string) file_get_contents($baseline), true);
        $data['findings'][0]['id'] = '0000000000';
        $data['findings'][0]['type'] = 'Old Label';
        file_put_contents($baseline, json_encode($data));

        $tester->execute(['--baseline' => $baseline]);
        unlink($baseline);

        self::assertStringContainsString('No findings detected', $tester->getDisplay());
        self::assertStringNotContainsString('no longer match', $tester->getDisplay());
    }

    private function tester(string $description): CommandTester
    {
        $analyzer = new readonly class($description) implements StaticAnalyzerInterface {
            public function __construct(
                private string $description,
            ) {
            }

            public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
            {
                return IssueCollection::fromArray([
                    new IntegrityIssue([
                        'type' => 'test_finding',
                        'title' => 'Items collection',
                        'description' => $this->description,
                        'severity' => 'warning',
                        'entity' => BaseOrderWithInit::class,
                        'field' => 'items',
                        'queries' => [],
                    ]),
                ]);
            }
        };

        return new CommandTester(new AnalyzeCommand([$analyzer], new IssueDeduplicator(), \dirname(__DIR__, 3)));
    }

    /**
     * @return list<string>
     */
    private function ids(CommandTester $tester): array
    {
        $json = json_decode($tester->getDisplay(), true);
        self::assertIsArray($json);

        return array_column($json['findings'], 'id');
    }

    #[Test]
    public function a_long_message_is_cut_after_a_whole_sentence_rather_than_at_a_french_colon(): void
    {
        $method = new \ReflectionMethod(AnalyzeCommand::class, 'firstSentences');
        $command = new AnalyzeCommand([], new IssueDeduplicator(), \dirname(__DIR__, 3));
        $text = 'Customer::$restockRequests est le côté inverse de StockAlert::$customer, et rien en dehors de l\'entité ne le lit. '
            . 'Doctrine n\'écrit que le côté propriétaire : cette collection ne fait qu\'ajouter du code à garder synchronisé.';

        self::assertSame(
            'Customer::$restockRequests est le côté inverse de StockAlert::$customer, et rien en dehors de l\'entité ne le lit.',
            $method->invoke($command, $text),
        );
    }
}
