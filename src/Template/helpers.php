<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

use AhmedBhs\DoctrineDoctor\Translation\Translator;
use Doctrine\SqlFormatter\HtmlHighlighter;
use Doctrine\SqlFormatter\SqlFormatter;

/**
 * Format SQL with syntax highlighting for display in templates.
 * Generates HTML with <pre class="highlight highlight-sql"> and colored spans.
 * @param string $sql The SQL query to format
 * @return string Formatted HTML with syntax highlighting
 */
function formatSqlWithHighlight(string $sql): string
{
    static $formatter = null;

    $formatter ??= new SqlFormatter(new HtmlHighlighter([
        HtmlHighlighter::HIGHLIGHT_PRE            => 'class="highlight highlight-sql"',
        HtmlHighlighter::HIGHLIGHT_QUOTE          => 'class="string"',
        HtmlHighlighter::HIGHLIGHT_BACKTICK_QUOTE => 'class="string"',
        HtmlHighlighter::HIGHLIGHT_RESERVED       => 'class="keyword"',
        HtmlHighlighter::HIGHLIGHT_BOUNDARY       => 'class="symbol"',
        HtmlHighlighter::HIGHLIGHT_NUMBER         => 'class="number"',
        HtmlHighlighter::HIGHLIGHT_WORD           => 'class="word"',
        HtmlHighlighter::HIGHLIGHT_ERROR          => 'class="error"',
        HtmlHighlighter::HIGHLIGHT_COMMENT        => 'class="comment"',
        HtmlHighlighter::HIGHLIGHT_VARIABLE       => 'class="variable"',
    ], false));

    return $formatter->format($sql);
}

/**
 * HTML escape function for safe output.
 * @param string $str String to escape
 * @return string Escaped HTML
 */
function escape(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Context-aware escaping function.
 * @param string $str     String to escape
 * @param string $context Escape context: 'html', 'attr', 'js', 'css', 'url'
 * @return string Escaped string
 */
function escapeContext(string $str, string $context = 'html'): string
{
    return match ($context) {
        'html'     => htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'attr'     => htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'js'       => json_encode($str, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
        // Pattern: Match non-alphanumeric/dash characters
        'css'      => preg_replace('/[^a-zA-Z0-9\-_]/', '', $str) ?? '',
        'url'      => rawurlencode($str),
        default    => htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    };
}

/**
 * Translate a text of a template, then put its values in.
 * The format is written by the template author and may hold markup (<code>, <strong>);
 * the values must already be escaped by the caller. The French text lives in translations/fr.php.
 * @param string $format English sprintf format
 * @param string|int|float|null ...$values Escaped values
 * @return string The translated text
 */
function t(string $format, string|int|float|null ...$values): string
{
    $translated = Translator::format($format);

    return [] === $values ? $translated : vsprintf($translated, $values);
}

/**
 * Translate a text received from an analyzer, already formatted with its values.
 * @param mixed $text The text, null or a scalar
 * @return string The translated plain text, to escape before output
 */
function tr(mixed $text): string
{
    // The context may arrive HTML-escaped: decode it so the catalog matches, the template escapes the result.
    return Translator::trans(html_entity_decode(is_scalar($text) ? (string) $text : '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * Map an issue severity onto the Bootstrap-style alert class used by the panel.
 * Templates receive the severity through the 'severity' context key, injected by
 * SuggestionFactory, so the banner always matches the severity the analyzer reported.
 * @param string $severity One of critical, warning, info
 * @return string The alert modifier class
 */
function severityAlertClass(string $severity): string
{
    return match ($severity) {
        'critical' => 'alert-danger',
        'warning'  => 'alert-warning',
        default    => 'alert-info',
    };
}

/**
 * Render the heading that opens a suggestion panel.
 * @param string $title Human readable suggestion title
 * @return string The header markup
 */
function suggestionHeader(string $title): string
{
    return '<div class="suggestion-header"><h4>' . htmlspecialchars(Translator::trans($title), ENT_NOQUOTES | ENT_HTML5, 'UTF-8') . "</h4></div>\n";
}

/**
 * Render the banner stating what was detected, coloured after the issue severity.
 * @param string $severity One of critical, warning, info
 * @param string $html     Already escaped banner markup
 * @return string The alert markup
 */
function suggestionAlert(string $severity, string $html): string
{
    return '<div class="alert ' . severityAlertClass($severity) . '">' . $html . "</div>\n";
}

/**
 * Render a titled block of example code.
 * @param string $title    Block heading, omitted when empty
 * @param string $code     Raw code, escaped here
 * @param string $language Highlighter language hint
 * @return string The code block markup
 */
function suggestionCodeBlock(string $title, string $code, string $language = 'php'): string
{
    $heading = '' === $title ? '' : '<h4>' . escape(Translator::trans($title)) . "</h4>\n";

    return $heading
        . '<div class="query-item"><pre><code class="language-' . escapeContext($language, 'css') . '">'
        . escape($code)
        . "</code></pre></div>\n";
}

/**
 * Render a link to the upstream documentation.
 * @param string $url   Absolute documentation URL
 * @param string $label Link text
 * @return string The link markup
 */
function suggestionDocLink(string $url, string $label): string
{
    // The label is element text, so quotes need no entity encoding: escaping them
    // would render &apos; in the panel. The href stays attribute-escaped.
    return '<p><a href="' . escape($url) . '" target="_blank" rel="noopener noreferrer" class="doc-link">'
        . htmlspecialchars(Translator::trans($label), ENT_NOQUOTES | ENT_HTML5, 'UTF-8')
        . '</a></p>';
}
