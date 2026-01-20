<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Utils;

use Webmozart\Assert\Assert;

/**
 * Utility class to add inline code highlighting to issue descriptions.
 * Usage in analyzers:
 * ```php
 * use AhmedBhs\DoctrineDoctor\Utils\DescriptionHighlighter;
 * $description = DescriptionHighlighter::highlight(
 *     'Field {field} in {class} has cascade={value}',
 *     [
 *         'field' => 'employees',
 *         'class' => 'App\Entity\Department',
 *         'value' => '"remove"',
 *     ]
 * );
 * ```
 */
final class DescriptionHighlighter
{
    /**
     * Highlight code elements in description text.
     * Replaces {placeholders} with highlighted HTML code tags.
     * @param string $template Description template with {placeholders}
     * @param array<string, mixed> $values Values to replace placeholders
     * @return string HTML string with code highlighting
     */
    public static function highlight(string $template, array $values = []): string
    {
        Assert::stringNotEmpty($template, 'Template cannot be empty');

        $description = $template;

        Assert::isIterable($values, '$values must be iterable');

        foreach ($values as $key => $value) {
            $highlighted = self::highlightValue($value);
            $description = str_replace('{' . $key . '}', $highlighted, $description);
        }

        return $description;
    }

    /**
     * Highlight as SQL keyword (SELECT, DELETE, etc.)
     */
    public static function keyword(string $text): string
    {
        return sprintf('<code>%s</code>', htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Highlight as method call (find(), flush(), etc.)
     */
    public static function method(string $text): string
    {
        return sprintf('<code>%s</code>', htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Highlight as value ("remove", "UTC", etc.)
     */
    public static function value(string $text): string
    {
        return sprintf('<code>%s</code>', htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Highlight as class name (App\Entity\User)
     */
    public static function class(string $text): string
    {
        return sprintf('<code>%s</code>', htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Highlight as database object (mysql.time_zone_name)
     */
    public static function dbObject(string $text): string
    {
        return sprintf('<code>%s</code>', htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Highlight as generic code (field names, etc.)
     */
    public static function code(string $text): string
    {
        return sprintf('<code>%s</code>', htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Auto-detect and highlight a value based on its content.
     */
    private static function highlightValue(mixed $value): string
    {
        $str = (string) $value;

        // Class names (contains backslashes)
        if (str_contains($str, '\\')) {
            return self::class($str);
        }

        // Method calls (ends with ())
        if (str_ends_with($str, '()')) {
            return self::method($str);
        }

        // Quoted strings
        if (1 === preg_match('/^["\'].*["\']$/', $str)) {
            return self::value($str);
        }

        // SQL keywords (uppercase words)
        if (1 === preg_match('/^[A-Z_]+$/', $str)) {
            return self::keyword($str);
        }

        // Database objects (contains dot)
        if (str_contains($str, '.') && !str_contains($str, ' ')) {
            return self::dbObject($str);
        }

        // Default: simple code
        return self::code($str);
    }
}
