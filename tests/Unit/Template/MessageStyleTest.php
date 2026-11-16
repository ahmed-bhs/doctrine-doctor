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
use PHPUnit\Framework\TestCase;

/**
 * Guards the message rules of docs/rules/messages.md: no emoji in user-facing strings,
 * and suggestion templates short enough to be read in the profiler.
 */
final class MessageStyleTest extends TestCase
{
    private const string EMOJI = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B50}\x{2B55}]/u';

    private const int MAX_TEMPLATE_LINES = 90;

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        foreach (glob(__DIR__ . '/../../../src/Template/Suggestions/*/*.php') ?: [] as $file) {
            yield basename(dirname($file)) . '/' . basename($file) => [$file];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function messageSources(): iterable
    {
        $root = __DIR__ . '/../../../src/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . 'Analyzer'));
        foreach ($files as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                yield substr($file->getPathname(), strlen($root)) => [$file->getPathname()];
            }
        }
    }

    #[DataProvider('templates')]
    public function test_template_has_no_emoji(string $file): void
    {
        self::assertSame(0, preg_match(self::EMOJI, $this->stringsOf($file), $m), sprintf('Emoji "%s" in %s', $m[0] ?? '', $file));
    }

    #[DataProvider('templates')]
    public function test_template_stays_short(string $file): void
    {
        $lines = count(file($file) ?: []);
        self::assertLessThanOrEqual(self::MAX_TEMPLATE_LINES, $lines, sprintf('%s has %d lines (max %d), see docs/rules/messages.md', $file, $lines, self::MAX_TEMPLATE_LINES));
    }

    #[DataProvider('messageSources')]
    public function test_analyzer_strings_have_no_emoji(string $file): void
    {
        self::assertSame(0, preg_match(self::EMOJI, $this->stringsOf($file), $m), sprintf('Emoji "%s" in a string of %s', $m[0] ?? '', $file));
    }

    /**
     * String literals and inline HTML only: emoji in code comments are not shown to users.
     */
    private function stringsOf(string $file): string
    {
        $out = '';
        foreach (\PhpToken::tokenize((string) file_get_contents($file)) as $token) {
            if (in_array($token->id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                $out .= $token->text . "\n";
            }
        }

        return $out;
    }
}
