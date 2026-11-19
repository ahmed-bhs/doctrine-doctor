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

    /** @var array<string, array{exact: array<string, string>, patterns: list<array{0: string, 1: string}>, sources: array<string, string>}> */
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
        $whole = self::lookup($catalog, $text);

        if (null !== $whole) {
            return $whole;
        }

        // A description is often built from several sentences: translate them one by one.
        $sentences = preg_split('/(?<=[.!?])(\s+)(?=[A-Z0-9`"\'(])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        if (\count($sentences) < 3) {
            return $text;
        }

        $translated = '';
        foreach ($sentences as $i => $part) {
            $translated .= 1 === $i % 2 ? $part : (self::lookup($catalog, $part) ?? $part);
        }

        return $translated;
    }

    /**
     * Translates a sprintf format written in a template, before its values are put in.
     * Unlike trans(), the format is looked up as is, so a translation may reorder the
     * placeholders (%1$s, %2$s).
     */
    public static function format(string $format): string
    {
        if (self::DEFAULT_LOCALE === self::$locale) {
            return $format;
        }

        return self::catalog(self::$locale)['sources'][$format] ?? $format;
    }

    /**
     * Captions of the Before/After code comparison, keyed by their English text: the panel script
     * reads them from code comments. They are stored in the catalog as "note: <English text>".
     *
     * @return array<string, string>
     */
    public static function notes(): array
    {
        if (self::DEFAULT_LOCALE === self::$locale) {
            return [];
        }

        $notes = [];
        foreach (self::catalog(self::$locale)['sources'] as $source => $target) {
            if (str_starts_with($source, 'note: ')) {
                $notes[substr($source, 6)] = $target;
            }
        }

        return $notes;
    }

    /**
     * Translates a "// Before: ...", "// After: ..." or "// Or ..." comment of a code example, for output
     * that shows the code as is (the CLI). Other lines are returned unchanged.
     */
    public static function codeComments(string $code): string
    {
        if (self::DEFAULT_LOCALE === self::$locale) {
            return $code;
        }

        return (string) preg_replace_callback(
            '/^([ \t]*(?:\/\/|#|--)[ \t]*)(Before|After|Or)\b([ \t]*[:,]?[ \t]*)(.*)$/m',
            static function (array $match): string {
                $note = '' === $match[4] ? null : self::trans('note: ' . $match[4]);
                $caption = null === $note || str_starts_with($note, 'note: ') ? $match[4] : $note;

                return $match[1] . self::trans($match[2]) . $match[3] . $caption;
            },
            $code,
        );
    }

    /**
     * @param array{exact: array<string, string>, patterns: list<array{0: string, 1: string}>, sources: array<string, string>} $catalog
     */
    private static function lookup(array $catalog, string $text): ?string
    {
        $key = trim($text);
        $found = self::find($catalog, $key);

        if (null === $found && str_ends_with($key, '.')) {
            $bare = self::find($catalog, rtrim($key, '.'));
            $found = null === $bare ? null : rtrim($bare, '.') . '.';
        }

        return null === $found ? null : str_replace($key, $found, $text);
    }

    /**
     * @param array{exact: array<string, string>, patterns: list<array{0: string, 1: string}>, sources: array<string, string>} $catalog
     */
    private static function find(array $catalog, string $key): ?string
    {

        if (isset($catalog['exact'][$key])) {
            return $catalog['exact'][$key];
        }

        foreach ($catalog['patterns'] as [$regex, $target]) {
            if (1 === preg_match($regex, $key, $matches)) {
                array_shift($matches);

                try {
                    return vsprintf($target, $matches);
                } catch (\ValueError) {
                    // A broken catalog entry must not break the page: keep the English text.
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * @return array{exact: array<string, string>, patterns: list<array{0: string, 1: string}>, sources: array<string, string>}
     */
    private static function catalog(string $locale): array
    {
        if (isset(self::$catalogs[$locale])) {
            return self::$catalogs[$locale];
        }

        $file = dirname(__DIR__, 2) . '/translations/' . basename($locale) . '.php';
        $entries = is_file($file) ? require $file : [];
        $catalog = ['exact' => [], 'patterns' => [], 'sources' => []];

        foreach (is_array($entries) ? $entries : [] as $source => $target) {
            if (!is_string($source) || !is_string($target)) {
                continue;
            }

            $catalog['sources'][$source] = $target;

            if (1 !== preg_match('/%(?:\d+\$)?[-+0]*\d*(?:\.\d+)?[sdfu]/', $source)) {
                $catalog['exact'][$source] = $target;
                continue;
            }

            $parts = preg_split('/(%%|%(?:\d+\$)?[-+0]*\d*(?:\.\d+)?[sdfu])/', $source, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $regex = '';
            foreach ($parts as $part) {
                // A value never spans a sentence end, so a one-sentence entry cannot swallow the next sentence.
                $regex .= 1 === preg_match('/^%(?!%)/', $part) ? '((?:(?![.!?]\\s+[A-Z0-9`"\'(]).)+?)' : preg_quote(str_replace('%%', '%', $part), '/');
            }

            $catalog['patterns'][] = ['/^' . $regex . '$/su', preg_replace('/%(?:\d+\$)?[-+0]*\d*(?:\.\d+)?[dfu]/', '%s', $target) ?? $target];
        }

        return self::$catalogs[$locale] = $catalog;
    }
}
