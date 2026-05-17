<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Template;

use AhmedBhs\DoctrineDoctor\Template\Renderer\PhpTemplateRenderer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PhpTemplateRendererPathTraversalTest extends TestCase
{
    private PhpTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new PhpTemplateRenderer(
            __DIR__ . '/../../../src/Template/Suggestions',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function maliciousTemplateNameProvider(): iterable
    {
        yield 'parent traversal'        => ['../../etc/passwd'];
        yield 'encoded parent traversal' => ['..%2Fetc%2Fpasswd'];
        yield 'absolute path'           => ['/etc/passwd'];
        yield 'null byte injection'     => ["Performance/n_plus_one\0.php"];
        yield 'spaces'                  => ['Performance/ n_plus_one'];
        yield 'special chars'           => ['Performance/n_plus_one;rm'];
    }

    #[Test]
    #[DataProvider('maliciousTemplateNameProvider')]
    public function it_rejects_malicious_template_names(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->renderer->render($name, []);
    }
}
