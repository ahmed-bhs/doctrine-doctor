<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\AiMate;

use AhmedBhs\DoctrineDoctor\AiMate\Capability\DoctrineDoctorIssuesTool;
use AhmedBhs\DoctrineDoctor\AiMate\DoctrineDoctorMcpSanitizer;
use AhmedBhs\DoctrineDoctor\AiMate\TraceSanitizer;
use AhmedBhs\DoctrineDoctor\Tests\Fixtures\AiMate\FixtureDoctrineDoctorCollector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\CollectorRegistry;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\ProfilerDataProvider;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class DoctrineDoctorIssuesToolTest extends TestCase
{
    private string $profilerDir;

    private FileProfilerStorage $storage;

    protected function setUp(): void
    {
        $this->profilerDir = sys_get_temp_dir() . '/dd-mate-tool-' . uniqid('', true);
        $this->storage = new FileProfilerStorage('file:' . $this->profilerDir);
    }

    protected function tearDown(): void
    {
        $this->storage->purge();
    }

    #[Test]
    public function it_returns_stats_and_sanitized_issues_for_a_stored_profile(): void
    {
        $this->storeProfile('abc123', new FixtureDoctrineDoctorCollector());

        $result = $this->createTool()->getIssues(token: 'abc123');

        self::assertSame(['total' => 1, 'critical' => 1], $result['stats']);
        self::assertCount(1, $result['issues']);
        self::assertSame('slow_query', $result['issues'][0]['type']);
    }

    #[Test]
    public function it_uses_the_latest_profile_when_no_token_is_given(): void
    {
        $this->storeProfile('latest-token', new FixtureDoctrineDoctorCollector());

        $result = $this->createTool()->getIssues();

        self::assertCount(1, $result['issues']);
    }

    #[Test]
    public function it_reports_an_error_when_no_profiles_exist(): void
    {
        self::assertSame(['error' => 'No profiler profiles found'], $this->createTool()->getIssues());
    }

    #[Test]
    public function it_reports_an_error_for_an_unknown_token(): void
    {
        self::assertSame(
            ['error' => 'Profile not found for token: missing'],
            $this->createTool()->getIssues(token: 'missing'),
        );
    }

    #[Test]
    public function it_reports_an_error_when_the_doctrine_doctor_collector_is_absent(): void
    {
        $this->storeProfile('no-dd', collector: null);

        $result = $this->createTool()->getIssues(token: 'no-dd');

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('doctrine_doctor collector not found', $result['error']);
    }

    private function createTool(): DoctrineDoctorIssuesTool
    {
        return new DoctrineDoctorIssuesTool(
            new ProfilerDataProvider($this->profilerDir, new CollectorRegistry()),
            new DoctrineDoctorMcpSanitizer(new TraceSanitizer('/app')),
        );
    }

    private function storeProfile(string $token, ?FixtureDoctrineDoctorCollector $collector): void
    {
        $profile = new Profile($token);
        $profile->setMethod('GET');
        $profile->setUrl('http://localhost/');
        $profile->setStatusCode(200);
        $profile->setIp('127.0.0.1');
        $profile->setTime(time());

        if (null !== $collector) {
            $profile->addCollector($collector);
        }

        $this->storage->write($profile);
    }
}
