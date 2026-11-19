<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Translation;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Exposes the translator to the profiler templates: {{ issue.getTitle()|dd_trans }}.
 */
final class TwigTranslationExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('dd_trans', Translator::trans(...)),
            new TwigFilter('dd_trans_html', self::transHtml(...)),
            // {{ '%d shown / %d total'|dd_format(shown, total) }}: the format is translated before its values are put in.
            // The translated format alone, for the panel script to fill: {{ '%d shown'|dd_format_raw|json_encode|raw }}.
            new TwigFilter('dd_format_raw', Translator::format(...)),
            new TwigFilter('dd_format', static fn (string $format, string|int|float|null ...$values): string => vsprintf(Translator::format($format), $values)),
        ];
    }

    /**
     * Translates an issue description that is already HTML. Its text may hold &quot; where the
     * catalog has a plain quote: quotes are decoded in the text only, never inside a tag, so an
     * attribute cannot be broken and the result stays safe to print raw.
     */
    public static function transHtml(string $html): string
    {
        $plain = preg_replace_callback(
            '/(<[^>]*>)|([^<]+)/',
            static fn (array $match): string => '' !== $match[1] ? $match[1] : str_replace(['&quot;', '&#039;', '&#39;', '&apos;'], ['"', "'", "'", "'"], $match[2]),
            $html,
        );

        return Translator::trans($plain ?? $html);
    }
}
