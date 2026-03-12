<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer\Security;

use AhmedBhs\DoctrineDoctor\Analyzer\Security\HardcodedDatabaseCredentialsAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HardcodedDatabaseCredentialsAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/doctrine-doctor-hardcoded-creds-' . uniqid();
        mkdir($this->tempDir);
        mkdir($this->tempDir . '/config');
        mkdir($this->tempDir . '/config/packages');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_does_not_flag_env_backed_doctrine_url_in_yaml(): void
    {
        file_put_contents($this->tempDir . '/config/packages/doctrine.yaml', <<<'YAML'
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
YAML);

        $analyzer = new HardcodedDatabaseCredentialsAnalyzer(
            $this->createStub(Connection::class),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            $this->tempDir,
        );

        $issues = $analyzer->analyze(QueryDataBuilder::create()->build());

        self::assertCount(0, $issues, 'A Doctrine URL sourced from %env()% must not be reported as hardcoded.');
    }

    #[Test]
    public function it_flags_literal_database_url_in_yaml(): void
    {
        file_put_contents($this->tempDir . '/config/packages/doctrine.yaml', <<<'YAML'
doctrine:
    dbal:
        url: 'mysql://app_user:secret@db:3306/app'
YAML);

        $analyzer = new HardcodedDatabaseCredentialsAnalyzer(
            $this->createStub(Connection::class),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            $this->tempDir,
        );

        $issues = $analyzer->analyze(QueryDataBuilder::create()->build());

        self::assertCount(1, $issues);
        self::assertStringContainsString('Hardcoded Database URL', $issues->first()?->getTitle() ?? '');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $scanResult = scandir($dir);
        if (false === $scanResult) {
            return;
        }

        foreach (array_diff($scanResult, ['.', '..']) as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }
}
