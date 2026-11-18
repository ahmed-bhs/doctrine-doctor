<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Translation;

/**
 * Translates the texts shown to the user, with no dependency on symfony/translation.
 *
 * Catalogs live in translations/<locale>.php and map an English source to its translation.
 * A source may contain sprintf placeholders (%s, %d, %.2f...): it then matches an already
 * formatted text and the captured values are put back into the translation.
 * English is the default and the fallback: an unknown locale or a missing entry returns the text unchanged.
 */
final class Translator
{
    private const string DEFAULT_LOCALE = 'en';

    private static string $locale = self::DEFAULT_LOCALE;

    /** @var array<string, array{exact: array<string, string>, patterns: list<array{0: string, 1: string}>}> */
    private static array $catalogs = [];

    public static function setLocale(string $locale): void
    {
        $locale = strtolower(trim($locale));
        self::$locale = 1 === preg_match('/^[a-z]{2}(?:_[a-z]{2})?$/', $locale) ? $locale : self::DEFAULT_LOCALE;
    }

    public static function getLocale(): string
    {
        return self::$locale;
    }

    public static function trans(string $text): string
    {
        if (self::DEFAULT_LOCALE === self::$locale || '' === trim($text)) {
            return $text;
        }

        $catalog = self::catalog(self::$locale);

        if (isset($catalog['exact'][$text])) {
            return $catalog['exact'][$text];
        }

        foreach ($catalog['patterns'] as [$regex, $target]) {
            if (1 === preg_match($regex, $text, $matches)) {
                array_shift($matches);

                return vsprintf($target, $matches);
            }
        }

        return $text;
    }

    /**
     * @return array{exact: array<string, string>, patterns: list<array{0: string, 1: string}>}
     */
    private static function catalog(string $locale): array
    {
        if (isset(self::$catalogs[$locale])) {
            return self::$catalogs[$locale];
        }

        $file = dirname(__DIR__, 2) . '/translations/' . basename($locale) . '.php';
        $entries = is_file($file) ? require $file : [];
        $catalog = ['exact' => [], 'patterns' => []];

        foreach (is_array($entries) ? $entries : [] as $source => $target) {
            if (!is_string($source) || !is_string($target)) {
                continue;
            }

            if (1 !== preg_match('/%(?:\d+\$)?[-+ 0]*\d*(?:\.\d+)?[sdfu]/', $source)) {
                $catalog['exact'][$source] = $target;
                continue;
            }

            $parts = preg_split('/(%(?:\d+\$)?[-+ 0]*\d*(?:\.\d+)?[sdfu])/', $source, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $regex = '';
            foreach ($parts as $part) {
                $regex .= 1 === preg_match('/^%/', $part) ? '(.+?)' : preg_quote($part, '/');
            }

            $catalog['patterns'][] = ['/^' . $regex . '$/su', preg_replace('/%(?:\d+\$)?[-+ 0]*\d*(?:\.\d+)?[dfu]/', '%s', $target) ?? $target];
        }

        return self::$catalogs[$locale] = $catalog;
    }
}
