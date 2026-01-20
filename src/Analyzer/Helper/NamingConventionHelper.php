<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Helper;

/**
 * Helper class for naming convention operations.
 * Handles string transformations and validations for database naming.
 */
final class NamingConventionHelper
{
    /**
     * Common singular to plural mappings.
     */
    private const PLURAL_EXCEPTIONS = [
        'person' => 'people',
        'child'  => 'children',
        'man'    => 'men',
        'woman'  => 'women',
        'tooth'  => 'teeth',
        'foot'   => 'feet',
        'mouse'  => 'mice',
        'goose'  => 'geese',
    ];

    /**
     * Check if string is in snake_case.
     */
    public function isSnakeCase(string $str): bool
    {
        // snake_case: lowercase letters, numbers, and underscores only
        return 1 === preg_match('/^[a-z0-9_]+$/', $str);
    }

    /**
     * Convert string to snake_case.
     */
    public function toSnakeCase(string $str): string
    {
        // Remove special characters first
        $str = preg_replace('/[^a-zA-Z0-9_]/', '_', $str);

        // Convert camelCase/PascalCase to snake_case
        $str = preg_replace('/([a-z])([A-Z])/', '$1_$2', (string) $str);
        $str = preg_replace('/([A-Z])([A-Z][a-z])/', '$1_$2', (string) $str);

        // Lowercase and remove duplicate underscores
        $str = strtolower((string) $str);
        $str = preg_replace('/_+/', '_', $str);

        return trim((string) $str, '_');
    }

    /**
     * Check if table name is plural.
     */
    public function isPlural(string $tableName): bool
    {
        // Remove underscores and check last word
        $parts    = explode('_', $tableName);
        $lastWord = end($parts);

        // Check common exceptions
        if (in_array($lastWord, self::PLURAL_EXCEPTIONS, true)) {
            return false;
        }

        // Simple heuristic: ends with 's' (not perfect but good enough)
        return str_ends_with($lastWord, 's')
               || str_ends_with($lastWord, 'ies')
               || str_ends_with($lastWord, 'es');
    }

    /**
     * Convert plural to singular (simple implementation).
     */
    public function toSingular(string $tableName): string
    {
        $parts    = explode('_', $tableName);
        $lastWord = array_pop($parts);

        $lastWord = $this->applySingularizationRules($lastWord);

        $parts[] = $lastWord;

        return implode('_', $parts);
    }

    /**
     * Check if name contains special characters.
     */
    public function hasSpecialCharacters(string $name): bool
    {
        return 1 === preg_match('/[^a-zA-Z0-9_]/', $name);
    }

    /**
     * Remove special characters.
     */
    public function removeSpecialCharacters(string $name): string
    {
        $result = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        return $result ?? $name;
    }

    /**
     * Apply singularization rules to a word.
     */
    private function applySingularizationRules(string $word): string
    {
        // Try exception mappings first
        $singularWord = $this->applyExceptionMapping($word);
        if ($singularWord !== $word) {
            return $singularWord;
        }

        // Apply suffix-based rules
        return $this->applySuffixRules($word);
    }

    /**
     * Apply exception mapping (plural → singular).
     */
    private function applyExceptionMapping(string $word): string
    {
        $reverseExceptions = array_flip(self::PLURAL_EXCEPTIONS);

        return $reverseExceptions[$word] ?? $word;
    }

    /**
     * Apply suffix-based singularization rules.
     */
    private function applySuffixRules(string $word): string
    {
        // categories → category
        if (str_ends_with($word, 'ies') && strlen($word) > 3) {
            return substr($word, 0, -3) . 'y';
        }

        // classes → class
        if (str_ends_with($word, 'ses') && strlen($word) > 3) {
            return substr($word, 0, -2);
        }

        // boxes → box
        if (str_ends_with($word, 'xes') && strlen($word) > 3) {
            return substr($word, 0, -2);
        }

        // churches → church
        if (str_ends_with($word, 'ches') && strlen($word) > 4) {
            return substr($word, 0, -2);
        }

        // users → user
        if (str_ends_with($word, 's') && strlen($word) > 1) {
            return substr($word, 0, -1);
        }

        return $word;
    }
}
