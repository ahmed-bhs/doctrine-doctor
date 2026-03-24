<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Integration\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\Security\HardcodedDatabaseCredentialsAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HardcodedDatabaseCredentialsAnalyzerIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/doctrine-doctor-hardcoded-creds-it-' . uniqid();
        mkdir($this->tempDir);
        mkdir($this->tempDir . '/config');
        mkdir($this->tempDir . '/config/packages');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_avoids_false_positive_when_runtime_connection_is_env_resolved_but_yaml_uses_env(): void
    {
        file_put_contents($this->tempDir . '/config/packages/doctrine.yaml', <<<'YAML'
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
YAML);

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('getParams')
            ->willReturn([
                'url' => 'mysql://demo_user:demo_pass@127.0.0.1:3306/demo',
            ]);

        $analyzer = new HardcodedDatabaseCredentialsAnalyzer(
            $connection,
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            $this->tempDir,
        );

        $issues = $analyzer->analyze(QueryDataCollection::empty());

        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_detects_hardcoded_user_and_password_in_connection_map(): void
    {
        file_put_contents($this->tempDir . '/config/packages/doctrine.yaml', <<<'YAML'
doctrine:
    dbal:
        connections:
            default:
                host: db
                user: app_user
                password: secret
YAML);

        $analyzer = new HardcodedDatabaseCredentialsAnalyzer(
            self::createStub(Connection::class),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            $this->tempDir,
        );

        $issues = $analyzer->analyze(QueryDataCollection::empty());

        self::assertCount(1, $issues);
        self::assertStringContainsString('user, password, host', $issues->first()?->getTitle() ?? '');
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
