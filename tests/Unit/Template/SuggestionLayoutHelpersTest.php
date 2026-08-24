<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SuggestionLayoutHelpersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/src/Template/helpers.php';
    }

    #[Test]
    #[DataProvider('markupProvider')]
    public function it_escapes_markup_coming_from_analysed_code(string $rendered): void
    {
        self::assertStringNotContainsString('<script>', $rendered);
        self::assertStringContainsString('&lt;script&gt;', $rendered);
    }

    #[Test]
    public function it_escapes_quotes_inside_the_documentation_url(): void
    {
        $rendered = suggestionDocLink('https://example.test" onmouseover="alert(1)', 'Docs');

        self::assertStringNotContainsString('onmouseover="alert', $rendered);
    }

    #[Test]
    public function it_keeps_apostrophes_readable_in_visible_text(): void
    {
        self::assertStringContainsString("Marco Pivetta's article", suggestionDocLink('https://example.test', "Marco Pivetta's article"));
        self::assertStringContainsString("Doctrine's mapping", suggestionHeader("Doctrine's mapping"));
    }

    #[Test]
    #[DataProvider('severityProvider')]
    public function it_maps_a_severity_onto_its_alert_class(string $severity, string $expected): void
    {
        self::assertSame($expected, severityAlertClass($severity));
        self::assertStringContainsString($expected, suggestionAlert($severity, 'Detected'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function markupProvider(): iterable
    {
        require_once dirname(__DIR__, 3) . '/src/Template/helpers.php';

        $payload = '<script>alert(1)</script>';

        yield 'header'     => [suggestionHeader($payload)];
        yield 'code block' => [suggestionCodeBlock('Title', $payload)];
        yield 'doc link'   => [suggestionDocLink('https://example.test', $payload)];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function severityProvider(): iterable
    {
        yield 'critical' => ['critical', 'alert-danger'];
        yield 'warning'  => ['warning', 'alert-warning'];
        yield 'info'     => ['info', 'alert-info'];
        yield 'unknown'  => ['nonsense', 'alert-info'];
    }
}
