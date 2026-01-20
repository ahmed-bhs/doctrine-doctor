<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\ValueObject\Helper;

use Webmozart\Assert\Assert;

/**
 * Formats markdown-like content to HTML.
 * Supports **bold**, `code`, lists, and line breaks.
 */
final class MarkdownFormatter
{
    /**
     * Format markdown text to HTML.
     */
    public static function format(string $text): string
    {
        $paragraphs = explode("\n\n", $text);
        $html       = '';

        Assert::isIterable($paragraphs, '$paragraphs must be iterable');

        foreach ($paragraphs as $paragraph) {
            $html .= self::formatParagraph(trim($paragraph));
        }

        return $html;
    }

    /**
     * Format a single paragraph (either as list or regular text).
     */
    private static function formatParagraph(string $paragraph): string
    {
        if (self::isEmptyContent($paragraph)) {
            return '';
        }

        $lines = explode("\n", $paragraph);

        if (self::isListParagraph($lines)) {
            return self::formatListParagraph($lines);
        }

        return self::formatRegularParagraph($paragraph);
    }

    /**
     * Check if content is empty.
     */
    private static function isEmptyContent(string $content): bool
    {
        return '' === $content || '0' === $content;
    }

    /**
     * Check if lines constitute a list (all lines start with - or •).
     * @param string[] $lines
     */
    private static function isListParagraph(array $lines): bool
    {
        if ([] === $lines) {
            return false;
        }

        Assert::isIterable($lines, '$lines must be iterable');

        foreach ($lines as $line) {
            $line = trim($line);

            if (self::isEmptyContent($line)) {
                continue;
            }

            // Pattern: Simple pattern match: /^[-•]\s/
            if (1 !== preg_match('/^[-•]\s/', $line)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Format lines as an HTML list.
     * @param string[] $lines
     */
    private static function formatListParagraph(array $lines): string
    {
        $html = '<ul>';

        Assert::isIterable($lines, '$lines must be iterable');

        foreach ($lines as $line) {
            $line = trim($line);

            if (self::isEmptyContent($line)) {
                continue;
            }

            $html .= self::formatListItem($line);
        }

        return $html . '</ul>';
    }

    /**
     * Format a single list item.
     */
    private static function formatListItem(string $line): string
    {
        $line = (string) preg_replace('/^[-•]\s+/', '', $line);
        $line = self::applyInlineMarkdown($line);

        return '<li>' . $line . '</li>';
    }

    /**
     * Format as regular paragraph.
     */
    private static function formatRegularParagraph(string $paragraph): string
    {
        $paragraph = self::applyInlineMarkdown($paragraph);

        return nl2br($paragraph);
    }

    /**
     * Apply inline markdown formatting (bold and code).
     */
    private static function applyInlineMarkdown(string $text): string
    {
        $text = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        // Pattern: Extract inline code markdown
        $text = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

        return $text;
    }
}
