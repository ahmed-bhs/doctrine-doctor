<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests to validate regex patterns used across analyzers.
 * Prevents regex compilation errors in production.
 */
final class RegexValidationTest extends TestCase
{
    /**
     * Test that all regex patterns compile correctly.
     */
    #[Test]
    #[DataProvider('regexPatternsProvider')]
    public function it_validates_regex_patterns_compile(string $pattern, string $description): void
    {
        // Suppress warnings to catch them as exceptions
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, $errno);
        });

        try {
            // Try to compile the regex
            $result = @preg_match($pattern, '');

            self::assertNotFalse($result, sprintf(
                'Regex pattern failed to compile: %s (%s)',
                $pattern,
                $description,
            ));
        } catch (\ErrorException $e) {
            self::fail(sprintf(
                'Regex compilation error for %s: %s. Pattern: %s',
                $description,
                $e->getMessage(),
                $pattern,
            ));
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Test regex patterns with actual sample data.
     */
    #[Test]
    #[DataProvider('regexWithSamplesProvider')]
    public function it_matches_expected_patterns(string $pattern, string $sample, bool $shouldMatch): void
    {
        $result = preg_match($pattern, $sample);

        if ($shouldMatch) {
            self::assertSame(1, $result, sprintf(
                'Pattern should match: %s with sample: %s',
                $pattern,
                $sample,
            ));
        } else {
            self::assertSame(0, $result, sprintf(
                'Pattern should NOT match: %s with sample: %s',
                $pattern,
                $sample,
            ));
        }
    }

    /**
     * Provide all regex patterns used in the codebase.
     *
     * @return array<string, array{string, string}>
     */
    public static function regexPatternsProvider(): array
    {
        return [
            // MissingIndexAnalyzer & NPlusOneAnalyzer - String literal matching
            'string_literal_with_backslash' => [
                "/'(?:[^'\\\\]|\\\\.)*'/",
                'Match string literals with escaped characters',
            ],

            // DQLValidationAnalyzer - Entity class references in FROM
            'dql_from_entity_class' => [
                '/FROM\s+([\w\\\\]+)/i',
                'Match entity class in FROM clause with namespace',
            ],

            // DQLValidationAnalyzer - Entity class references in JOIN
            'dql_join_entity_class' => [
                '/JOIN\s+([\w\\\\]+)/i',
                'Match entity class in JOIN clause with namespace',
            ],

            // Whitespace normalization
            'whitespace_normalization' => [
                '/\s+/',
                'Match multiple whitespace characters',
            ],

            // Numeric literals
            'numeric_literal' => [
                '/\b(\d+)\b/',
                'Match numeric literals',
            ],

            // IN clause normalization
            'in_clause' => [
                '/IN\s*\([^)]+\)/i',
                'Match IN clause with parameters',
            ],

            // Equals placeholder normalization
            'equals_placeholder' => [
                '/=\s*\?/',
                'Match equals sign with placeholder',
            ],

            // Table name with alias extraction
            'table_with_alias' => [
                '/(?:FROM|JOIN)\s+([`\w]+)\s+(?:AS\s+)?(\w+)\b/i',
                'Match table name and alias in FROM/JOIN',
            ],

            // WHERE clause column extraction
            'where_column' => [
                '/(?:WHERE|AND|OR)\s+(?:\w+\.)?`?(\w+)`?\s*(?:[=<>!]|LIKE|IN|IS|BETWEEN)/i',
                'Match column in WHERE clause',
            ],

            // ORDER BY clause
            'order_by' => [
                '/ORDER\s+BY\s+/i',
                'Match ORDER BY clause',
            ],

            // LIMIT clause
            'limit_clause' => [
                '/LIMIT\s+\d+/i',
                'Match LIMIT clause',
            ],

            // DQL SELECT statement
            'dql_select' => [
                '/^\s*SELECT/i',
                'Match SELECT statement at start',
            ],

            // SQL injection patterns
            'sql_concatenation' => [
                '/["\'].*?\$\w+.*?["\']/s',
                'Match potential SQL injection via concatenation',
            ],
        ];
    }

    /**
     * Provide regex patterns with sample data to test matching.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function regexWithSamplesProvider(): array
    {
        return [
            // String literals
            'simple_string' => [
                "/'(?:[^'\\\\]|\\\\.)*'/",
                "'hello world'",
                true,
            ],
            'string_with_escaped_quote' => [
                "/'(?:[^'\\\\]|\\\\.)*'/",
                "'hello\\'world'",
                true,
            ],
            'string_with_backslash' => [
                "/'(?:[^'\\\\]|\\\\.)*'/",
                "'path\\\\to\\\\file'",
                true,
            ],

            // Entity class with namespace
            'entity_from_full_namespace' => [
                '/FROM\s+([\w\\\\]+)/i',
                'FROM App\\Entity\\User',
                true,
            ],
            'entity_from_simple' => [
                '/FROM\s+([\w\\\\]+)/i',
                'FROM User',
                true,
            ],
            'entity_join_with_namespace' => [
                '/JOIN\s+([\w\\\\]+)/i',
                'JOIN App\\Entity\\Product',
                true,
            ],

            // Table names
            'table_name_no_quotes' => [
                '/(?:FROM|JOIN)\s+([`\w]+)\s+(?:AS\s+)?(\w+)\b/i',
                'FROM users u',
                true,
            ],
            'table_name_with_quotes' => [
                '/(?:FROM|JOIN)\s+([`\w]+)\s+(?:AS\s+)?(\w+)\b/i',
                'FROM `users` u',
                true,
            ],
            'table_name_with_as' => [
                '/(?:FROM|JOIN)\s+([`\w]+)\s+(?:AS\s+)?(\w+)\b/i',
                'FROM users AS u',
                true,
            ],

            // WHERE columns
            'where_column_equals' => [
                '/(?:WHERE|AND|OR)\s+(?:\w+\.)?`?(\w+)`?\s*(?:[=<>!]|LIKE|IN|IS|BETWEEN)/i',
                'WHERE id = 1',
                true,
            ],
            'where_column_with_table' => [
                '/(?:WHERE|AND|OR)\s+(?:\w+\.)?`?(\w+)`?\s*(?:[=<>!]|LIKE|IN|IS|BETWEEN)/i',
                'AND u.email = ?',
                true,
            ],
            'where_column_like' => [
                '/(?:WHERE|AND|OR)\s+(?:\w+\.)?`?(\w+)`?\s*(?:[=<>!]|LIKE|IN|IS|BETWEEN)/i',
                'WHERE name LIKE "%test%"',
                true,
            ],

            // SELECT statement
            'select_statement' => [
                '/^\s*SELECT/i',
                'SELECT * FROM users',
                true,
            ],
            'select_with_spaces' => [
                '/^\s*SELECT/i',
                '  SELECT id FROM products',
                true,
            ],
            'not_select' => [
                '/^\s*SELECT/i',
                'UPDATE users SET name = "test"',
                false,
            ],

            // ORDER BY
            'order_by_present' => [
                '/ORDER\s+BY\s+/i',
                'SELECT * FROM users ORDER BY name',
                true,
            ],
            'order_by_not_present' => [
                '/ORDER\s+BY\s+/i',
                'SELECT * FROM users',
                false,
            ],

            // LIMIT
            'limit_present' => [
                '/LIMIT\s+\d+/i',
                'SELECT * FROM users LIMIT 10',
                true,
            ],
            'limit_not_present' => [
                '/LIMIT\s+\d+/i',
                'SELECT * FROM users',
                false,
            ],

            // IN clause
            'in_clause_single' => [
                '/IN\s*\([^)]+\)/i',
                'WHERE id IN (1, 2, 3)',
                true,
            ],
            'in_clause_placeholder' => [
                '/IN\s*\([^)]+\)/i',
                'WHERE id IN (?)',
                true,
            ],
        ];
    }

    /**
     * Test that regex replacements work correctly.
     */
    #[Test]
    public function it_normalizes_queries_correctly(): void
    {
        $sql = "SELECT * FROM users WHERE name = 'John' AND id = 123";

        // Normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', trim($sql));
        self::assertNotNull($normalized);

        // Replace string literals
        $normalized = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $normalized);
        self::assertNotNull($normalized);
        self::assertStringContainsString('name = ?', $normalized);

        // Replace numeric literals
        $normalized = preg_replace('/\b(\d+)\b/', '?', $normalized);
        self::assertNotNull($normalized);
        self::assertStringContainsString('id = ?', $normalized);

        // Final result should be normalized
        self::assertSame(
            'SELECT * FROM USERS WHERE NAME = ? AND ID = ?',
            strtoupper($normalized),
        );
    }

    /**
     * Test edge cases that previously caused issues.
     */
    #[Test]
    public function it_handles_edge_cases(): void
    {
        // Backslash in namespace
        $pattern = '/FROM\s+([\w\\\\]+)/i';
        self::assertSame(
            1,
            preg_match($pattern, 'FROM App\\Entity\\User'),
            'Should match namespace with backslashes',
        );

        // Escaped quote in string
        $pattern = "/'(?:[^'\\\\]|\\\\.)*'/";
        self::assertSame(
            1,
            preg_match($pattern, "'It\\'s working'"),
            'Should match string with escaped quote',
        );

        // Multiple backslashes (Windows paths)
        $pattern = "/'(?:[^'\\\\]|\\\\.)*'/";
        self::assertSame(
            1,
            preg_match($pattern, "'C:\\\\path\\\\to\\\\file'"),
            'Should match Windows path with backslashes',
        );
    }
}
