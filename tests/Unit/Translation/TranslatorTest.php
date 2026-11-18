<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Translation;

use AhmedBhs\DoctrineDoctor\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    protected function tearDown(): void
    {
        Translator::setLocale('en');
    }

    public function testEnglishIsReturnedUnchanged(): void
    {
        Translator::setLocale('en');

        self::assertSame('Unused JOIN Detected', Translator::trans('Unused JOIN Detected'));
    }

    public function testExactEntryIsTranslated(): void
    {
        Translator::setLocale('fr');

        self::assertSame('Jointure inutilisée', Translator::trans('Unused JOIN Detected'));
    }

    public function testFormattedTextIsTranslatedWithItsValues(): void
    {
        Translator::setLocale('fr');

        self::assertSame(
            'Entités chargées en entier : 100 requêtes sur app_loyalty_account',
            Translator::trans('Full Entity Loading: 100 queries on app_loyalty_account'),
        );
    }

    public function testSentencesAreTranslatedOneByOne(): void
    {
        Translator::setLocale('fr');

        self::assertSame(
            'Jointure inutilisée. Something else.',
            Translator::trans('Unused JOIN Detected. Something else.'),
        );
    }

    public function testMissingEntryFallsBackToEnglish(): void
    {
        Translator::setLocale('fr');

        self::assertSame('Something not translated', Translator::trans('Something not translated'));
    }

    public function testUnknownOrInvalidLocaleFallsBackToEnglish(): void
    {
        Translator::setLocale('../etc');
        self::assertSame('en', Translator::getLocale());

        Translator::setLocale('de');
        self::assertSame('Unused JOIN Detected', Translator::trans('Unused JOIN Detected'));
    }
}
