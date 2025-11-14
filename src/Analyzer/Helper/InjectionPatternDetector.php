<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Helper;

/**
 * Detects SQL/DQL injection patterns in queries.
 *
 * This class encapsulates all injection detection logic with well-documented,
 * testable methods. Each pattern has its own method for better maintainability.
 *
 * Why regex here? Injection attacks create MALFORMED/INVALID SQL that parsers
 * cannot handle. Regex patterns are the appropriate tool for detecting malicious
 * patterns in potentially invalid SQL.
 */
class InjectionPatternDetector
{
    /**
     * @return array{risk_level: int, indicators: list<string>}
     */
    public function detectInjectionRisk(string $sql): array
    {
        $riskLevel = 0;
        $indicators = [];

        // Run all detection methods
        if ($this->hasNumericValueInQuotes($sql)) {
            ++$riskLevel;
            $indicators[] = 'Numeric value in quotes (possible concatenation)';
        }

        if ($this->hasSQLInjectionKeywords($sql)) {
            $riskLevel += 3;
            $indicators[] = 'SQL injection keywords detected in string';
        }

        if ($this->hasCommentSyntaxInString($sql)) {
            $riskLevel += 2;
            $indicators[] = 'SQL comment syntax in string value';
        }

        if ($this->hasConsecutiveQuotes($sql)) {
            ++$riskLevel;
            $indicators[] = 'Consecutive quotes detected';
        }

        if ($this->hasUnparameterizedLike($sql)) {
            ++$riskLevel;
            $indicators[] = 'LIKE clause without parameter';
        }

        if ($this->hasLiteralStringInWhere($sql)) {
            $riskLevel += 2;
            $indicators[] = 'WHERE clause with literal string instead of parameter';
        }

        if ($this->hasMultipleConditionsWithLiterals($sql)) {
            $riskLevel += 3;
            $indicators[] = 'Multiple conditions with literal strings (possible injection)';
        }

        return [
            'risk_level' => $riskLevel,
            'indicators' => $indicators,
        ];
    }

    /**
     * Pattern 1: Numeric values inside quoted strings.
     *
     * Example: WHERE username = '123' or password = "456"
     * This might indicate string concatenation instead of parameter binding.
     *
     * Safe: WHERE id = 123 (unquoted number)
     * Suspicious: WHERE id = '123' (quoted number - why?)
     */
    public function hasNumericValueInQuotes(string $sql): bool
    {
        return 1 === preg_match("/['\"][^'\"]*\d+[^'\"]*['\"]/", $sql);
    }

    /**
     * Pattern 2: SQL injection keywords in quoted strings.
     *
     * Detects classic injection attempts:
     * - UNION attacks: ' UNION SELECT ...
     * - Tautologies: ' OR 1=1 --, ' AND 1=1 --
     * - Comments: --, #, C-style comments
     *
     * Example attack: username = '' OR 1=1 --'
     */
    public function hasSQLInjectionKeywords(string $sql): bool
    {
        return 1 === preg_match("/'.*(?:UNION|OR\s+1\s*=\s*1|AND\s+1\s*=\s*1|--|\#|\/\*).*'/i", $sql);
    }

    /**
     * Pattern 3: SQL comment syntax in strings.
     *
     * Detects attempts to comment out parts of the query:
     * - -- (double dash)
     * - # (hash)
     * - C-style comments (slash-star)
     *
     * Example: WHERE username = 'admin'--' AND password = '...'
     * The comment causes the password check to be ignored.
     */
    public function hasCommentSyntaxInString(string $sql): bool
    {
        return 1 === preg_match("/['\"].*(?:--|#|\/\*).*['\"]/", $sql);
    }

    /**
     * Pattern 4: Multiple consecutive quotes (escape attempts).
     *
     * Detects attempts to break out of quoted strings:
     * - '' (double single quote - SQL escape)
     * - "" (double double quote)
     * - ''' (triple quotes - breaking out)
     *
     * Example: username = 'admin'' OR '1'='1'
     */
    public function hasConsecutiveQuotes(string $sql): bool
    {
        return 1 === preg_match("/'{2,}|(\"){2,}/", $sql);
    }

    /**
     * Pattern 5: LIKE clause without parameter binding.
     *
     * Example suspicious: WHERE name LIKE '%admin%'
     * Should be: WHERE name LIKE ? (with param '%admin%')
     *
     * Why suspicious? Direct value in LIKE can contain injection:
     * LIKE '%' OR 1=1 --%'
     */
    public function hasUnparameterizedLike(string $sql): bool
    {
        return 1 === preg_match("/LIKE\s+['\"][^?:]*%[^?:]*['\"]/i", $sql);
    }

    /**
     * Pattern 6: WHERE clause with literal strings instead of parameters.
     *
     * Detects WHERE conditions using hardcoded strings instead of ? or :param.
     *
     * Example bad: WHERE username = 'admin'
     * Should be: WHERE username = ? (or :username)
     *
     * This is a strong indicator of string concatenation vulnerability.
     */
    public function hasLiteralStringInWhere(string $sql): bool
    {
        return 1 === preg_match("/WHERE\s+[^=]+\s*=\s*'[^'?:]+'/i", $sql);
    }

    /**
     * Pattern 7: Multiple OR/AND conditions with literal strings.
     *
     * This is VERY suspicious - indicates complex injection attempt.
     *
     * Example attack:
     * WHERE username = 'x' OR 1=1 AND password = 'y'
     *
     * Should be:
     * WHERE username = ? AND password = ?
     */
    public function hasMultipleConditionsWithLiterals(string $sql): bool
    {
        return 1 === preg_match("/(?:WHERE|AND|OR)\s+[^=]+\s*=\s*'[^']*'\s+(?:OR|AND)\s+/i", $sql);
    }

    /**
     * Get descriptive name for a specific pattern.
     * Useful for documentation and error messages.
     */
    public function getPatternDescription(string $patternName): string
    {
        return match ($patternName) {
            'numeric_in_quotes' => 'Numeric values in quoted strings indicate possible concatenation',
            'injection_keywords' => 'Classic SQL injection keywords (UNION, OR 1=1, comments)',
            'comment_syntax' => 'SQL comment syntax attempting to bypass security',
            'consecutive_quotes' => 'Multiple quotes attempting to escape string boundaries',
            'unparameterized_like' => 'LIKE clause with direct values instead of parameters',
            'literal_in_where' => 'WHERE clause using literal strings instead of parameter binding',
            'multiple_conditions' => 'Multiple conditions with literals - complex injection attempt',
            default => 'Unknown pattern',
        };
    }
}
