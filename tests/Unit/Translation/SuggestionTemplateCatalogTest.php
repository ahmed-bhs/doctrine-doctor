<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A suggestion template exists once, in English; its texts are translated in translations/fr.php.
 * This test keeps both in step: an English text changed in a template without its French entry fails here.
 */
final class SuggestionTemplateCatalogTest extends TestCase
{
    private const array TRANSLATED_CALLS = ['t' => 0, 'suggestionHeader' => 0, 'suggestionCodeBlock' => 0, 'suggestionDocLink' => 1];

    /** @return iterable<string, array{string, string}> */
    public static function templateTexts(): iterable
    {
        $base = dirname(__DIR__, 3) . '/src/Template/Suggestions';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($base) + 1);
            foreach (array_unique(self::literalArguments((string) file_get_contents($file->getPathname()))) as $text) {
                yield $relative . ': ' . $text => [$relative, $text];
            }
        }
    }

    #[DataProvider('templateTexts')]
    public function test_every_template_text_has_a_french_translation(string $template, string $text): void
    {
        $catalog = require dirname(__DIR__, 3) . '/translations/fr.php';

        self::assertArrayHasKey($text, $catalog, sprintf('%s: no French entry in translations/fr.php for "%s"', $template, $text));

        $translation = $catalog[$text];
        self::assertSame(self::placeholderCount($text), self::placeholderCount($translation), sprintf('Placeholders differ for "%s"', $text));
        self::assertSame(self::tags($text), self::tags($translation), sprintf('Markup differs for "%s"', $text));
    }

    /** @return list<string> */
    private static function literalArguments(string $source): array
    {
        $tokens = array_values(array_filter(token_get_all($source), static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $texts = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || T_STRING !== $token[0] || !isset(self::TRANSLATED_CALLS[$token[1]]) || '(' !== ($tokens[$i + 1] ?? null)) {
                continue;
            }

            // Walk to the wanted argument, at depth 1 of this call.
            $wanted = self::TRANSLATED_CALLS[$token[1]];
            $argument = 0;
            $depth = 0;
            for ($j = $i + 1; isset($tokens[$j]); ++$j) {
                $current = $tokens[$j];
                if (in_array($current, ['(', '['], true)) {
                    ++$depth;
                } elseif (in_array($current, [')', ']'], true)) {
                    if (0 === --$depth) {
                        break;
                    }
                } elseif (',' === $current && 1 === $depth) {
                    ++$argument;
                } elseif (1 === $depth && $argument === $wanted && is_array($current) && T_CONSTANT_ENCAPSED_STRING === $current[0]) {
                    $next = $tokens[$j + 1] ?? null;
                    // Only a whole literal argument, not one concatenated with a variable.
                    if (',' === $next || ')' === $next) {
                        $text = eval('return ' . $current[1] . ';');
                        // An empty code block title shows nothing to translate.
                        if ('' !== $text) {
                            $texts[] = $text;
                        }
                    }
                }
            }
        }

        return $texts;
    }

    private static function placeholderCount(string $text): int
    {
        return (int) preg_match_all('/%(?:\d+\$)?[-+ 0]*\d*(?:\.\d+)?[sdfu]/', $text);
    }

    /** @return list<string> */
    private static function tags(string $text): array
    {
        preg_match_all('/<\/?[a-z][a-z0-9]*/i', $text, $matches);
        $tags = $matches[0];
        sort($tags);

        return $tags;
    }
}
