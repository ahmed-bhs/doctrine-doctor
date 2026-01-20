<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

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

    if (null === $formatter) {
        $formatter = new SqlFormatter(new HtmlHighlighter([
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
    }

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
