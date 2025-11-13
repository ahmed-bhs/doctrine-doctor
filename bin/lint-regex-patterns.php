#!/usr/bin/env php
<?php

/**
 * Script: Linter pour patterns regex
 *
 * Vérifie que les nouveaux patterns regex respectent les bonnes pratiques:
 * - Pas de regex pour simple keyword detection
 * - Patterns complexes documentés
 * - Préférer les parsers quand disponibles
 *
 * Usage: php bin/lint-regex-patterns.php [file...]
 * Usage avec git: git diff --name-only | php bin/lint-regex-patterns.php --stdin
 */

declare(strict_types=1);

class RegexPatternLinter
{
    private const BAD_PATTERNS = [
        // Patterns simples qui devraient utiliser str_contains()
        '/ORDER BY/i' => 'Use str_contains(strtoupper($sql), \'ORDER BY\') instead',
        '/GROUP BY/i' => 'Use str_contains(strtoupper($sql), \'GROUP BY\') instead',
        '/WHERE/i' => 'Use str_contains(strtoupper($sql), \'WHERE\') instead',
        '/LIMIT/i' => 'Use str_contains(strtoupper($sql), \'LIMIT\') instead',
        '/DISTINCT/i' => 'Use str_contains(strtoupper($sql), \'DISTINCT\') instead',
        '/HAVING/i' => 'Use str_contains(strtoupper($sql), \'HAVING\') instead',
        '/\\sJOIN\\s/i' => 'Use str_contains() or SqlStructureExtractor::extractJoins()',
        '/LEFT JOIN/i' => 'Use SqlStructureExtractor::extractJoins() for JOIN parsing',
        '/INNER JOIN/i' => 'Use SqlStructureExtractor::extractJoins() for JOIN parsing',
    ];

    private const COMPLEX_INDICATORS = [
        '(?:' => 'Non-capturing groups indicate complex pattern',
        '[^' => 'Negated character classes indicate complex pattern',
        '\\s*\\(' => 'Complex whitespace + parentheses pattern',
        '.*?' => 'Non-greedy wildcards indicate complex pattern',
    ];

    private array $errors = [];
    private array $warnings = [];

    public function lintFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            $this->errors[] = [
                'file' => $filePath,
                'line' => 0,
                'message' => 'File not found',
                'severity' => 'error',
            ];
            return false;
        }

        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        // Find all preg_* usages
        if (preg_match_all(
            '/preg_(match|match_all|replace)\s*\(\s*([\'"])(.+?)\2/s',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($matches[3] as $index => $match) {
                $pattern = $match[0];
                $offset = $match[1];
                $lineNumber = substr_count($content, "\n", 0, $offset) + 1;

                // Check for bad patterns
                if (isset(self::BAD_PATTERNS[$pattern])) {
                    $this->errors[] = [
                        'file' => $filePath,
                        'line' => $lineNumber,
                        'message' => 'Simple keyword detection using regex',
                        'suggestion' => self::BAD_PATTERNS[$pattern],
                        'pattern' => $pattern,
                        'severity' => 'error',
                    ];
                }

                // Check for complex patterns without documentation
                $isComplex = $this->isComplexPattern($pattern);
                if ($isComplex) {
                    $isDocumented = $this->isPatternDocumented($lines, $lineNumber);
                    if (!$isDocumented) {
                        $this->warnings[] = [
                            'file' => $filePath,
                            'line' => $lineNumber,
                            'message' => 'Complex regex pattern without documentation',
                            'suggestion' => 'Add a comment explaining what this pattern matches',
                            'pattern' => substr($pattern, 0, 60) . (strlen($pattern) > 60 ? '...' : ''),
                            'severity' => 'warning',
                        ];
                    }
                }

                // Check for JOIN extraction with regex
                if (stripos($pattern, 'join') !== false && str_contains($pattern, '(')) {
                    $this->errors[] = [
                        'file' => $filePath,
                        'line' => $lineNumber,
                        'message' => 'Complex JOIN extraction with regex',
                        'suggestion' => 'Use SqlStructureExtractor::extractJoins() instead',
                        'pattern' => substr($pattern, 0, 60) . '...',
                        'severity' => 'error',
                    ];
                }
            }
        }

        return empty($this->errors);
    }

    private function isComplexPattern(string $pattern): bool
    {
        foreach (self::COMPLEX_INDICATORS as $indicator => $reason) {
            if (str_contains($pattern, $indicator)) {
                return true;
            }
        }
        return false;
    }

    private function isPatternDocumented(array $lines, int $lineNumber): bool
    {
        // Check 5 lines before
        for ($i = max(0, $lineNumber - 6); $i < $lineNumber - 1; $i++) {
            if (isset($lines[$i])) {
                $line = trim($lines[$i]);
                // Consider it documented if there's a comment
                if (str_starts_with($line, '//') || str_starts_with($line, '*')) {
                    return true;
                }
            }
        }
        return false;
    }

    public function lintDirectory(string $dir): bool
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir)
        );

        $hasErrors = false;
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                if (!$this->lintFile($file->getPathname())) {
                    $hasErrors = true;
                }
            }
        }

        return !$hasErrors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function printReport(): void
    {
        if (empty($this->errors) && empty($this->warnings)) {
            echo "✅ No regex pattern issues found\n";
            return;
        }

        if (!empty($this->errors)) {
            echo "❌ Errors:\n\n";
            foreach ($this->errors as $error) {
                echo sprintf(
                    "  %s:%d\n",
                    $error['file'],
                    $error['line']
                );
                echo sprintf("    ❌ %s\n", $error['message']);
                if (isset($error['pattern'])) {
                    echo sprintf("       Pattern: %s\n", $error['pattern']);
                }
                if (isset($error['suggestion'])) {
                    echo sprintf("       💡 %s\n", $error['suggestion']);
                }
                echo "\n";
            }
        }

        if (!empty($this->warnings)) {
            echo "⚠️  Warnings:\n\n";
            foreach ($this->warnings as $warning) {
                echo sprintf(
                    "  %s:%d\n",
                    $warning['file'],
                    $warning['line']
                );
                echo sprintf("    ⚠️  %s\n", $warning['message']);
                if (isset($warning['pattern'])) {
                    echo sprintf("       Pattern: %s\n", $warning['pattern']);
                }
                if (isset($warning['suggestion'])) {
                    echo sprintf("       💡 %s\n", $warning['suggestion']);
                }
                echo "\n";
            }
        }

        // Summary
        echo "📊 Summary:\n";
        echo sprintf("  - Errors: %d\n", count($this->errors));
        echo sprintf("  - Warnings: %d\n", count($this->warnings));
    }
}

// Main execution
$linter = new RegexPatternLinter();

if (in_array('--stdin', $argv, true)) {
    // Read files from stdin (useful with git diff)
    $files = [];
    while ($line = fgets(STDIN)) {
        $file = trim($line);
        if (file_exists($file) && str_ends_with($file, '.php')) {
            $files[] = $file;
        }
    }

    if (empty($files)) {
        echo "No PHP files to lint\n";
        exit(0);
    }

    foreach ($files as $file) {
        $linter->lintFile($file);
    }
} elseif (count($argv) > 1 && $argv[1] !== '--stdin') {
    // Lint specific files
    $files = array_slice($argv, 1);
    foreach ($files as $file) {
        if (is_dir($file)) {
            $linter->lintDirectory($file);
        } else {
            $linter->lintFile($file);
        }
    }
} else {
    // Lint entire src directory
    $srcDir = __DIR__ . '/../src';
    if (!is_dir($srcDir)) {
        echo "Error: src directory not found\n";
        exit(1);
    }

    echo "🔍 Linting regex patterns in src/...\n\n";
    $linter->lintDirectory($srcDir);
}

$linter->printReport();

// Exit with error code if errors found
exit(empty($linter->getErrors()) ? 0 : 1);
