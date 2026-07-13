<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\AiMate;

use AhmedBhs\DoctrineDoctor\AiMate\TraceSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TraceSanitizerTest extends TestCase
{
    private TraceSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new TraceSanitizer('/app');
    }

    #[Test]
    public function it_returns_empty_array_for_null_trace(): void
    {
        self::assertSame([], $this->sanitizer->sanitize(null));
    }

    #[Test]
    public function it_returns_empty_array_for_empty_trace(): void
    {
        self::assertSame([], $this->sanitizer->sanitize([]));
    }

    #[Test]
    public function it_relativizes_application_paths_against_project_dir(): void
    {
        $sanitized = $this->sanitizer->sanitize([
            ['file' => '/app/src/Controller/ProductController.php', 'line' => 42],
        ]);

        self::assertSame('src/Controller/ProductController.php', $sanitized[0]['file']);
        self::assertSame(42, $sanitized[0]['line']);
    }

    #[Test]
    public function it_excludes_internal_framework_frames(): void
    {
        $sanitized = $this->sanitizer->sanitize([
            ['file' => '/app/vendor/symfony/http-kernel/Kernel.php', 'line' => 10],
            ['file' => '/app/var/cache/dev/container.php', 'line' => 5],
            ['file' => '/app/src/Service/OrderService.php', 'line' => 88],
        ]);

        self::assertCount(1, $sanitized);
        self::assertSame('src/Service/OrderService.php', $sanitized[0]['file']);
    }

    #[Test]
    public function it_falls_back_to_internal_frames_when_no_application_frame_exists(): void
    {
        $sanitized = $this->sanitizer->sanitize([
            ['file' => '/app/vendor/symfony/http-kernel/Kernel.php', 'line' => 10],
        ]);

        self::assertCount(1, $sanitized);
        self::assertSame('vendor/symfony/http-kernel/Kernel.php', $sanitized[0]['file']);
    }

    #[Test]
    public function it_keeps_class_and_function_when_present(): void
    {
        $sanitized = $this->sanitizer->sanitize([
            [
                'file' => '/app/src/Controller/ProductController.php',
                'line' => 42,
                'class' => 'App\\Controller\\ProductController',
                'function' => '__invoke',
            ],
        ]);

        self::assertArrayHasKey('class', $sanitized[0]);
        self::assertArrayHasKey('function', $sanitized[0]);
        self::assertSame('App\\Controller\\ProductController', $sanitized[0]['class']);
        self::assertSame('__invoke', $sanitized[0]['function']);
    }

    #[Test]
    public function it_skips_frames_without_a_file(): void
    {
        $sanitized = $this->sanitizer->sanitize([
            ['line' => 10, 'function' => 'call_user_func'],
            ['file' => '/app/src/Kernel.php', 'line' => 3],
        ]);

        self::assertCount(1, $sanitized);
        self::assertSame('src/Kernel.php', $sanitized[0]['file']);
    }

    #[Test]
    public function it_limits_the_number_of_returned_frames(): void
    {
        $trace = [];

        for ($i = 1; $i <= 8; ++$i) {
            $trace[] = ['file' => sprintf('/app/src/Step%d.php', $i), 'line' => $i];
        }

        $sanitized = $this->sanitizer->sanitize($trace);

        self::assertCount(5, $sanitized);
        self::assertSame('src/Step1.php', $sanitized[0]['file']);
    }

    #[Test]
    public function it_defaults_line_to_zero_when_missing_or_not_an_integer(): void
    {
        $sanitized = $this->sanitizer->sanitize([
            ['file' => '/app/src/A.php'],
            ['file' => '/app/src/B.php', 'line' => 'not-a-number'],
        ]);

        self::assertSame(0, $sanitized[0]['line']);
        self::assertSame(0, $sanitized[1]['line']);
    }
}
