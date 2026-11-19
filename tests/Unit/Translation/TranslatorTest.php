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
use AhmedBhs\DoctrineDoctor\Translation\TwigTranslationExtension;
use AhmedBhs\DoctrineDoctor\ValueObject\SuggestionContentBlock;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    protected function tearDown(): void
    {
        Translator::setLocale('en');
    }

    public function test_english_is_returned_unchanged(): void
    {
        Translator::setLocale('en');

        self::assertSame('Unused JOIN Detected', Translator::trans('Unused JOIN Detected'));
    }

    public function test_exact_entry_is_translated(): void
    {
        Translator::setLocale('fr');

        self::assertSame('Jointure inutilisée', Translator::trans('Unused JOIN Detected'));
    }

    public function test_formatted_text_is_translated_with_its_values(): void
    {
        Translator::setLocale('fr');

        self::assertSame(
            'Entités chargées en entier : 100 requêtes sur app_loyalty_account',
            Translator::trans('Full Entity Loading: 100 queries on app_loyalty_account'),
        );
    }

    public function test_sentences_are_translated_one_by_one(): void
    {
        Translator::setLocale('fr');

        self::assertSame(
            'Jointure inutilisée. Something else.',
            Translator::trans('Unused JOIN Detected. Something else.'),
        );
    }

    public function test_missing_entry_falls_back_to_english(): void
    {
        Translator::setLocale('fr');

        self::assertSame('Something not translated', Translator::trans('Something not translated'));
    }

    public function test_unknown_or_invalid_locale_falls_back_to_english(): void
    {
        Translator::setLocale('../etc');
        self::assertSame('en', Translator::getLocale());

        Translator::setLocale('de');
        self::assertSame('Unused JOIN Detected', Translator::trans('Unused JOIN Detected'));
    }

    public function test_template_format_is_translated_before_its_values_are_put_in(): void
    {
        require_once dirname(__DIR__, 3) . '/src/Template/helpers.php';
        $format = 'If children must outlive their parent, remove <code>cascade: [\'remove\']</code> from <code>$%s</code> instead.';

        Translator::setLocale('en');
        self::assertSame(sprintf($format, 'items'), t($format, 'items'));

        Translator::setLocale('fr');
        self::assertSame('Si les enfants doivent survivre à leur parent, retirez plutôt <code>cascade: [\'remove\']</code> de <code>$items</code>.', t($format, 'items'));
        self::assertSame('Untranslated %s', t('Untranslated %s'));
    }

    public function test_a_one_sentence_entry_does_not_swallow_the_next_sentence(): void
    {
        Translator::setLocale('fr');

        $text = 'Property Supplier::$leadTimeDays is typed string but the mapping expects integer. '
            . 'Doctrine can see a change on every flush and issue needless UPDATEs, and values can differ from what the code expects.';

        self::assertStringNotContainsString('Doctrine can see', Translator::trans($text));
    }

    public function test_html_description_is_translated_without_decoding_tags(): void
    {
        Translator::setLocale('fr');

        $html = 'A query on table &quot;sylius_taxon&quot; filters by &quot;enabled&quot;, which leads no index. It is cheap on a small table and becomes a full scan as the table grows.';
        self::assertStringStartsWith('Une requête sur la table « sylius_taxon »', TwigTranslationExtension::transHtml($html));

        // A quote entity inside a tag stays encoded, so the attribute is not broken.
        self::assertSame('<a title="x &quot; y">z</a>', TwigTranslationExtension::transHtml('<a title="x &quot; y">z</a>'));
    }

    public function test_content_block_prose_is_translated_but_not_its_code(): void
    {
        Translator::setLocale('fr');

        self::assertStringContainsString('Important :', SuggestionContentBlock::heading('Important:', 4)->toHtml());
        self::assertStringContainsString('Load the full entity', SuggestionContentBlock::code('// Load the full entity when you need to modify it')->toHtml());
    }

    public function test_a_percent_followed_by_a_space_is_not_a_placeholder(): void
    {
        Translator::setLocale('fr');

        // "50% for" once read as a "% f" placeholder, and vsprintf failed on the translation.
        self::assertSame('Variable (0 à 50 % pour les requêtes cachables)', Translator::trans('Varies (0-50% for cacheable queries)'));
    }
}
