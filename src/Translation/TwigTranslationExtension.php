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
        ];
    }
}
