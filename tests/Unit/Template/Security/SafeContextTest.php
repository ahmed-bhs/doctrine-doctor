<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Template\Security;

use AhmedBhs\DoctrineDoctor\Template\Security\SafeContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AhmedBhs\DoctrineDoctor\Template\Security\SafeContext
 */
final class SafeContextTest extends TestCase
{
    public function test_auto_escapes_string_values(): void
    {
        $context = new SafeContext([
            'username' => '<script>alert("XSS")</script>',
        ]);

        self::assertSame(
            '&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;',
            $context['username'],
        );
    }

    public function test_raw_returns_unescaped_value(): void
    {
        $context = new SafeContext([
            'html' => '<strong>Bold</strong>',
        ]);

        self::assertSame('<strong>Bold</strong>', $context->raw('html'));
    }

    public function test_array_access_auto_escapes(): void
    {
        $context = new SafeContext([
            'name' => '<b>Test</b>',
        ]);

        self::assertSame('&lt;b&gt;Test&lt;/b&gt;', $context['name']);
    }

    public function test_recursively_escapes_arrays(): void
    {
        $context = new SafeContext([
            'items' => [
                '<script>',
                'safe',
                '<img>',
            ],
        ]);

        $expected = [
            '&lt;script&gt;',
            'safe',
            '&lt;img&gt;',
        ];

        self::assertSame($expected, $context['items']);
    }

    public function test_preserves_non_string_types(): void
    {
        $context = new SafeContext([
            'count'   => 42,
            'price'   => 19.99,
            'active'  => true,
            'value'   => null,
        ]);

        self::assertSame(42, $context['count']);
        self::assertSame(19.99, $context['price']);
        self::assertTrue($context['active']);
        self::assertNull($context['value']);
    }

    public function test_throws_exception_for_undefined_variable(): void
    {
        $context = new SafeContext([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Undefined context variable: missing');

        /** @phpstan-ignore expr.resultUnused */
        $context['missing']; // Triggers exception
    }

    public function test_has_checks_variable_existence(): void
    {
        $context = new SafeContext([
            'exists' => 'value',
        ]);

        self::assertTrue($context->has('exists'));
        self::assertFalse($context->has('missing'));
    }

    public function test_keys_returns_all_keys(): void
    {
        $context = new SafeContext([
            'a' => 1,
            'b' => 2,
            'c' => 3,
        ]);

        self::assertSame(['a', 'b', 'c'], $context->keys());
    }

    public function test_is_immutable(): void
    {
        $context = new SafeContext(['key' => 'value']);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('SafeContext is immutable');

        $context['key'] = 'new value';
    }

    public function test_cannot_unset_values(): void
    {
        $context = new SafeContext(['key' => 'value']);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('SafeContext is immutable');

        unset($context['key']);
    }

    public function test_offset_exists_works(): void
    {
        $context = new SafeContext(['key' => 'value']);

        self::assertTrue(isset($context['key']));
        self::assertFalse(isset($context['missing']));
    }

    /**
     * XSS Prevention Test Cases.
     */
    public function test_prevents_xss_in_common_attack_vectors(): void
    {
        $attacks = [
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
            '<svg/onload=alert(1)>',
            'javascript:alert(1)',
            '<iframe src="javascript:alert(1)">',
            '<body onload=alert(1)>',
            '"><script>alert(1)</script>',
            '\'"--><script>alert(1)</script>',
        ];

        foreach ($attacks as $attack) {
            $context = new SafeContext(['input' => $attack]);
            $escaped = $context['input'];

            // Verify HTML tags are escaped (< becomes &lt;, > becomes &gt;)
            // This prevents browser from interpreting them as HTML
            self::assertStringNotContainsString('<script>', $escaped, 'Script tag should be escaped');
            self::assertStringNotContainsString('<img', $escaped, 'Img tag should be escaped');
            self::assertStringNotContainsString('<svg', $escaped, 'SVG tag should be escaped');
            self::assertStringNotContainsString('<iframe', $escaped, 'Iframe tag should be escaped');
            self::assertStringNotContainsString('<body', $escaped, 'Body tag should be escaped');

            // Verify escaped output contains HTML entities
            if (str_contains($attack, '<')) {
                self::assertStringContainsString('&lt;', $escaped, 'Should contain escaped < character');
            }
            if (str_contains($attack, '>')) {
                self::assertStringContainsString('&gt;', $escaped, 'Should contain escaped > character');
            }
        }
    }
}
