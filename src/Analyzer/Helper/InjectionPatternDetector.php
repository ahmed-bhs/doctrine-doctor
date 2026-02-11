<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Helper;

use PhpMyAdmin\SqlParser\Components\Condition;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;

/**
 * Detects SQL/DQL injection patterns in queries using SQL parser.
 *
 * This class uses phpmyadmin/sql-parser for robust SQL analysis instead of
 * fragile regex patterns. The parser provides accurate detection of:
 * - Literal values in WHERE clauses
 * - LIKE patterns without parameters
 * - Suspicious string values
 *
 * For malformed SQL (potential injection attempts), we fall back to regex
 * since parsers cannot handle invalid SQL.
 */
class InjectionPatternDetector
{
    /**
     * Safe enum/status values that shouldn't be flagged as injection risks.
     */
    private const array SAFE_LITERAL_VALUES = [
        'active', 'inactive', 'pending', 'completed', 'cancelled', 'deleted',
        'published', 'draft', 'archived', 'suspended', 'approved', 'rejected',
        'enabled', 'disabled', 'open', 'closed', 'processing', 'shipped',
        'yes', 'no', 'true', 'false', 'y', 'n', '1', '0',
        'user', 'admin', 'guest', 'member', 'subscriber', 'customer',
        'public', 'private', 'internal', 'external',
    ];

    /**
     * @return array{risk_level: int, indicators: list<string>}
     */
    public function detectInjectionRisk(string $sql): array
    {
        $riskLevel = 0;
        $indicators = [];

        $parsedData = $this->parseSql($sql);

        if ($this->hasNumericValueInQuotes($sql, $parsedData)) {
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

        if ($this->hasUnparameterizedLike($sql, $parsedData)) {
            ++$riskLevel;
            $indicators[] = 'LIKE clause without parameter';
        }

        if ($this->hasLiteralStringInWhere($sql, $parsedData)) {
            $riskLevel += 2;
            $indicators[] = 'WHERE clause with literal string instead of parameter';
        }

        if ($this->hasMultipleConditionsWithLiterals($sql, $parsedData)) {
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
     * Uses parser to find literals, then checks if they contain suspicious numbers.
     *
     * @param array{literals?: list<string>, has_like?: bool, like_values?: list<string>, condition_count?: int, parsed?: bool} $parsedData
     */
    public function hasNumericValueInQuotes(string $sql, array $parsedData = []): bool
    {
        if (!empty($parsedData['literals'])) {
            return array_any($parsedData['literals'], fn ($value) => $this->isSuspiciousNumericValue($value));
        }

        if (1 !== preg_match("/['\"]([^'\"]*\d+[^'\"]*)['\"]/", $sql, $matches)) {
            return false;
        }

        return $this->isSuspiciousNumericValue($matches[1]);
    }

    /**
     * Pattern 2: SQL injection keywords in quoted strings.
     *
     * Detects classic injection attempts - this stays as regex because
     * these patterns are specifically looking for malformed/attack SQL.
     */
    public function hasSQLInjectionKeywords(string $sql): bool
    {
        if (1 === preg_match("/'.*(?:UNION|OR\s+1\s*=\s*1|AND\s+1\s*=\s*1|--|\#|\/\*).*'/i", $sql)) {
            return true;
        }

        if (1 === preg_match("/OR\s+['\"]?1['\"]?\s*=\s*['\"]?1['\"]?/i", $sql)) {
            return true;
        }

        if (1 === preg_match("/AND\s+['\"]?1['\"]?\s*=\s*['\"]?1['\"]?/i", $sql)) {
            return true;
        }

        return false;
    }

    /**
     * Pattern 3: SQL comment syntax in strings.
     *
     * Stays as regex - looking for attack patterns in malformed SQL.
     */
    public function hasCommentSyntaxInString(string $sql): bool
    {
        return 1 === preg_match("/['\"].*(?:--|#|\/\*).*['\"]/", $sql);
    }

    /**
     * Pattern 4: Multiple consecutive quotes (escape attempts).
     *
     * Stays as regex - looking for escape attempts.
     */
    public function hasConsecutiveQuotes(string $sql): bool
    {
        return 1 === preg_match("/'{2,}|(\"){2,}/", $sql);
    }

    /**
     * Pattern 5: LIKE clause without parameter binding.
     *
     * Uses parser to accurately detect LIKE clauses with literal values.
     *
     * @param array{literals?: list<string>, has_like?: bool, like_values?: list<string>, condition_count?: int, parsed?: bool} $parsedData
     */
    public function hasUnparameterizedLike(string $sql, array $parsedData = []): bool
    {
        if (!empty($parsedData['parsed']) && !empty($parsedData['has_like'])) {
            foreach ($parsedData['like_values'] ?? [] as $value) {
                if (str_contains($value, '%') || str_contains($value, '_')) {
                    return true;
                }
            }
            return false;
        }

        return 1 === preg_match("/LIKE\s+['\"][^?:]*%[^?:]*['\"]/i", $sql);
    }

    /**
     * Pattern 6: WHERE clause with literal strings instead of parameters.
     *
     * Uses parser to find literals and checks against safe values list.
     *
     * @param array{literals?: list<string>, has_like?: bool, like_values?: list<string>, condition_count?: int, parsed?: bool} $parsedData
     */
    public function hasLiteralStringInWhere(string $sql, array $parsedData = []): bool
    {
        if (!empty($parsedData['literals'])) {
            return array_any($parsedData['literals'], fn ($value) => $this->isSuspiciousLiteralValue($value));
        }

        if (1 !== preg_match("/WHERE\s+[^=]+\s*=\s*'([^'?:]+)'/i", $sql, $matches)) {
            return false;
        }

        return $this->isSuspiciousLiteralValue($matches[1]);
    }

    /**
     * Pattern 7: Multiple OR/AND conditions with literal strings.
     *
     * Uses parser to count conditions with literals.
     *
     * @param array{literals?: list<string>, has_like?: bool, like_values?: list<string>, condition_count?: int, parsed?: bool} $parsedData
     */
    public function hasMultipleConditionsWithLiterals(string $sql, array $parsedData = []): bool
    {
        if (!empty($parsedData['parsed'])) {
            $suspiciousLiterals = array_filter(
                $parsedData['literals'] ?? [],
                $this->isSuspiciousLiteralValue(...),
            );

            return count($suspiciousLiterals) >= 2;
        }

        return 1 === preg_match("/(?:WHERE|AND|OR)\s+[^=]+\s*=\s*'[^']*'\s+(?:OR|AND)\s+/i", $sql);
    }

    /**
     * Get descriptive name for a specific pattern.
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

    /**
     * Parse SQL and extract useful data for analysis.
     *
     * @return array{literals: list<string>, has_like: bool, like_values: list<string>, condition_count: int, parsed: bool}
     */
    private function parseSql(string $sql): array
    {
        $result = [
            'literals' => [],
            'has_like' => false,
            'like_values' => [],
            'condition_count' => 0,
            'parsed' => false,
        ];

        try {
            $parser = new Parser($sql);

            if (empty($parser->statements)) {
                return $result;
            }

            $statement = $parser->statements[0];

            if (!$statement instanceof SelectStatement) {
                return $result;
            }

            $result['parsed'] = true;

            if (null !== $statement->where) {
                $result = $this->extractWhereData($statement->where, $result);
            }

            return $result;
        } catch (\Throwable) {
            return $result;
        }
    }

    /**
     * Extract data from WHERE conditions.
     *
     * @param Condition[] $conditions
     * @param array{literals: list<string>, has_like: bool, like_values: list<string>, condition_count: int, parsed: bool} $result
     * @return array{literals: list<string>, has_like: bool, like_values: list<string>, condition_count: int, parsed: bool}
     */
    private function extractWhereData(array $conditions, array $result): array
    {
        foreach ($conditions as $condition) {
            if (!$condition instanceof Condition) {
                continue;
            }

            $expr = $condition->expr ?? '';
            if ('' === $expr) {
                continue;
            }

            ++$result['condition_count'];

            if (false !== stripos($expr, 'LIKE')) {
                $result['has_like'] = true;

                $rightOperand = $condition->rightOperand ?? '';
                if ('' !== $rightOperand && 1 === preg_match("/^['\"](.*)['\"]\$/", $rightOperand, $matches)) {
                    $result['like_values'][] = $matches[1];
                } elseif (1 === preg_match("/LIKE\s+['\"]([^'\"]*)['\"]/" . 'i', $expr, $matches)) {
                    $result['like_values'][] = $matches[1];
                }
            }

            $rightOperand = $condition->rightOperand ?? '';
            if ('' !== $rightOperand && 1 === preg_match("/^['\"](.*)['\"]\$/", $rightOperand, $matches)) {
                $result['literals'][] = $matches[1];
            } elseif (1 === preg_match_all("/=\s*['\"]([^'\"]*)['\"]/", $expr, $matches)) {
                foreach ($matches[1] as $literal) {
                    $result['literals'][] = $literal;
                }
            }
        }

        return $result;
    }

    /**
     * Check if a value is a suspicious numeric string.
     */
    private function isSuspiciousNumericValue(string $value): bool
    {
        if (1 !== preg_match('/\d/', $value)) {
            return false;
        }

        if (1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            return false;
        }

        if (1 === preg_match('/^\d{4}-\d{2}-\d{2}/', $value) || 1 === preg_match('/^\d{2}\/\d{2}\/\d{4}/', $value)) {
            return false;
        }

        if (1 === preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            return false;
        }

        if (1 === preg_match('/^\d+\.\d+(\.\d+)?$/', $value)) {
            return false;
        }

        if (1 === preg_match('/^\d{1,10}$/', $value)) {
            return false;
        }

        return true;
    }

    /**
     * Check if a literal value is suspicious (not a safe enum/status).
     */
    private function isSuspiciousLiteralValue(string $value): bool
    {
        $normalizedValue = strtolower(trim($value));

        if ('' === $normalizedValue) {
            return false;
        }

        if (in_array($normalizedValue, self::SAFE_LITERAL_VALUES, true)) {
            return false;
        }

        if (strlen($normalizedValue) <= 10 && 1 === preg_match('/^[a-z]+$/', $normalizedValue)) {
            return false;
        }

        return true;
    }
}
