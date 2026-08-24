<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Template\Renderer;

use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(PhpTemplateRenderer::class)]
final class TemplateGracefulDegradationTest extends TestCase
{
    private PhpTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new PhpTemplateRenderer(self::templateDirectory(), new NullLogger());
    }

    #[Test]
    #[DataProvider('allTemplatesProvider')]
    public function it_renders_without_error_when_context_is_empty(string $templateName): void
    {
        $errors = [];
        set_error_handler(static function (int $level, string $message) use (&$errors): bool {
            $errors[] = $message;

            return true;
        });

        try {
            $result = $this->renderer->render($templateName, []);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $errors, sprintf('Template %s emitted PHP errors on empty context', $templateName));
        self::assertNotSame('', $result['code']);
        self::assertNotSame('', $result['description']);
    }

    #[Test]
    public function it_discovers_every_shipped_template(): void
    {
        self::assertGreaterThan(100, iterator_count(self::allTemplatesProvider()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allTemplatesProvider(): iterable
    {
        $directory = self::templateDirectory();
        $iterator  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        $names = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $names[] = substr($file->getPathname(), strlen($directory) + 1, -4);
        }

        sort($names);

        foreach ($names as $name) {
            yield $name => [$name];
        }
    }

    private static function templateDirectory(): string
    {
        return dirname(__DIR__, 4) . '/src/Template/Suggestions';
    }
}
