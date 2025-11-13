#!/usr/bin/env php
<?php

/**
 * Script: Ajouter automatiquement des commentaires aux regex non documentés
 *
 * Analyse les patterns regex et génère des commentaires explicatifs
 *
 * Usage: php bin/add-regex-documentation.php [--dry-run] [--apply]
 */

declare(strict_types=1);

class RegexDocumentationGenerator
{
    private const COMMON_PATTERNS = [
        // SQL patterns
        '/FROM\s+/i' => 'Extract table name from SQL FROM clause',
        '/JOIN\s+/i' => 'Detect JOIN in SQL query',
        '/WHERE\s+/i' => 'Detect WHERE clause in SQL',
        '/SELECT\s+/i' => 'Detect SELECT statement',
        '/IN\s*\([^)]+\)/i' => 'Detect IN clause with values',
        '/LIMIT\s+/i' => 'Detect LIMIT clause',
        '/OFFSET\s+/i' => 'Detect OFFSET clause',
        '/ORDER BY/i' => 'Detect ORDER BY clause',
        '/GROUP BY/i' => 'Detect GROUP BY clause',

        // String literals
        "/'[^']*'/" => 'Match single-quoted strings',
        '/"[^"]*"/' => 'Match double-quoted strings',
        "/'(?:[^'\\\\\\\\]|\\\\\\\\.)*'/" => 'Match single-quoted strings with escapes',

        // Security patterns
        '/UNION/i' => 'Detect SQL UNION (potential injection)',
        '/OR\s+1\s*=\s*1/i' => 'Detect SQL tautology (injection pattern)',
        '/--/' => 'Detect SQL comment (potential injection)',

        // PHP patterns
        '/\$_(?:GET|POST|REQUEST|COOKIE|SERVER)/i' => 'Detect superglobal access',
        '/json_encode\s*\(/i' => 'Detect json_encode() call',
        '/serialize\s*\(/i' => 'Detect serialize() call',

        // Markdown patterns
        '/\*\*([^*]+)\*\*/' => 'Extract bold markdown text',
        '/`([^`]+)`/' => 'Extract inline code markdown',

        // Naming conventions
        '/[^a-zA-Z0-9_]/' => 'Match non-alphanumeric characters (validation)',
        '/[^a-zA-Z0-9\-_]/' => 'Match non-alphanumeric/dash characters',

        // Numbers
        '/\d+/' => 'Match numeric values',
        '/(\d+)\s+(?:queries?|executions?)/i' => 'Extract query count from text',
    ];

    private bool $dryRun = false;
    private array $changes = [];

    public function __construct(bool $dryRun = false)
    {
        $this->dryRun = $dryRun;
    }

    public function processFile(string $filePath): int
    {
        if (!file_exists($filePath)) {
            return 0;
        }

        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);
        $changesCount = 0;

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

                // Check if already documented
                if ($this->isPatternDocumented($lines, $lineNumber)) {
                    continue;
                }

                // Generate documentation
                $doc = $this->generateDocumentation($pattern);
                if ($doc) {
                    $this->changes[] = [
                        'file' => $filePath,
                        'line' => $lineNumber,
                        'pattern' => $pattern,
                        'documentation' => $doc,
                    ];
                    $changesCount++;
                }
            }
        }

        return $changesCount;
    }

    private function isPatternDocumented(array $lines, int $lineNumber): bool
    {
        // Check 3 lines before
        for ($i = max(0, $lineNumber - 4); $i < $lineNumber - 1; $i++) {
            if (isset($lines[$i])) {
                $line = trim($lines[$i]);
                if (str_starts_with($line, '//') || str_starts_with($line, '*')) {
                    return true;
                }
            }
        }
        return false;
    }

    private function generateDocumentation(string $pattern): ?string
    {
        // Try to match against common patterns
        foreach (self::COMMON_PATTERNS as $commonPattern => $description) {
            if ($this->patternsMatch($pattern, $commonPattern)) {
                return "// Pattern: $description";
            }
        }

        // Generate generic description based on pattern characteristics
        if (str_contains($pattern, 'FROM') || str_contains($pattern, 'SELECT')) {
            return "// Pattern: SQL query structure extraction";
        }

        if (str_contains($pattern, 'JOIN')) {
            return "// Pattern: SQL JOIN detection/extraction";
        }

        if (str_contains($pattern, 'UNION') || str_contains($pattern, 'OR.*1.*=.*1')) {
            return "// Pattern: SQL injection detection";
        }

        if (str_contains($pattern, '[^') || str_contains($pattern, '[a-zA-Z')) {
            return "// Pattern: Character validation/sanitization";
        }

        if (str_contains($pattern, '(?:') || str_contains($pattern, '\\s*\\(')) {
            return "// Pattern: Complex structure extraction (consider using parser)";
        }

        if (preg_match('/\\\\\$/', $pattern)) {
            return "// Pattern: Variable/interpolation detection";
        }

        return "// Pattern: " . $this->describePattern($pattern);
    }

    private function patternsMatch(string $pattern1, string $pattern2): bool
    {
        // Normalize patterns for comparison
        $p1 = strtolower(str_replace(['\\s', '\\b', '\\w', '+', '*', '?'], '', $pattern1));
        $p2 = strtolower(str_replace(['\\s', '\\b', '\\w', '+', '*', '?'], '', $pattern2));

        return str_contains($p1, trim($p2, '/')) || str_contains($p2, trim($p1, '/'));
    }

    private function describePattern(string $pattern): string
    {
        $length = strlen($pattern);

        if ($length < 20) {
            return "Simple pattern match: $pattern";
        } elseif ($length < 50) {
            return "Pattern match: " . substr($pattern, 0, 40) . "...";
        } else {
            return "Complex pattern match (consider documenting manually)";
        }
    }

    public function generateReport(): string
    {
        $report = "# Regex Documentation Report\n\n";
        $report .= "Date: " . date('Y-m-d H:i:s') . "\n";
        $report .= "Undocumented patterns found: " . count($this->changes) . "\n\n";

        if (empty($this->changes)) {
            $report .= "✅ All patterns are documented!\n";
            return $report;
        }

        $report .= "## Patterns Needing Documentation\n\n";

        $fileGroups = [];
        foreach ($this->changes as $change) {
            $file = str_replace(dirname(dirname($change['file'])) . '/', '', $change['file']);
            if (!isset($fileGroups[$file])) {
                $fileGroups[$file] = [];
            }
            $fileGroups[$file][] = $change;
        }

        foreach ($fileGroups as $file => $changes) {
            $report .= "### $file\n\n";
            foreach ($changes as $change) {
                $report .= sprintf(
                    "**Line %d**\n```\n%s\n```\nPattern: `%s`\n\n",
                    $change['line'],
                    $change['documentation'],
                    substr($change['pattern'], 0, 60) . (strlen($change['pattern']) > 60 ? '...' : '')
                );
            }
            $report .= "\n";
        }

        return $report;
    }

    public function applyDocumentation(string $dir): int
    {
        if ($this->dryRun) {
            echo "🔍 DRY RUN MODE - No files will be modified\n\n";
        }

        $totalChanges = 0;

        // Group changes by file
        $fileGroups = [];
        foreach ($this->changes as $change) {
            $file = $change['file'];
            if (!isset($fileGroups[$file])) {
                $fileGroups[$file] = [];
            }
            $fileGroups[$file][] = $change;
        }

        foreach ($fileGroups as $file => $changes) {
            if (!file_exists($file)) {
                continue;
            }

            $lines = file($file, FILE_IGNORE_NEW_LINES);

            // Sort changes by line number (descending) to avoid line shifts
            usort($changes, fn($a, $b) => $b['line'] <=> $a['line']);

            foreach ($changes as $change) {
                $lineIndex = $change['line'] - 1;

                // Get indentation of target line
                $targetLine = $lines[$lineIndex] ?? '';
                preg_match('/^(\s*)/', $targetLine, $indentMatch);
                $indent = $indentMatch[1] ?? '';

                // Insert documentation comment above
                $docLine = $indent . $change['documentation'];
                array_splice($lines, $lineIndex, 0, [$docLine]);

                $totalChanges++;
            }

            if (!$this->dryRun) {
                // Backup
                $backupFile = $file . '.doc-backup';
                copy($file, $backupFile);

                // Write modified content
                file_put_contents($file, implode("\n", $lines) . "\n");

                echo "✅ Documented " . count($changes) . " patterns in " .
                     str_replace(dirname(dirname($file)) . '/', '', $file) . "\n";
            } else {
                echo "🔍 Would document " . count($changes) . " patterns in " .
                     str_replace(dirname(dirname($file)) . '/', '', $file) . "\n";
            }
        }

        return $totalChanges;
    }

    public function getChanges(): array
    {
        return $this->changes;
    }
}

// Main execution
$dryRun = in_array('--dry-run', $argv, true);
$apply = in_array('--apply', $argv, true);

$srcDir = __DIR__ . '/../src';

if (!is_dir($srcDir)) {
    echo "Error: src directory not found\n";
    exit(1);
}

$generator = new RegexDocumentationGenerator($dryRun);

echo "🔍 Scanning for undocumented regex patterns...\n\n";

// Scan all PHP files
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir)
);

$totalFiles = 0;
$totalPatterns = 0;

foreach ($iterator as $file) {
    if ($file->getExtension() === 'php') {
        $count = $generator->processFile($file->getPathname());
        if ($count > 0) {
            $totalFiles++;
            $totalPatterns += $count;
        }
    }
}

// Generate report
$report = $generator->generateReport();
$reportFile = __DIR__ . '/../docs/REGEX_DOCUMENTATION_REPORT.md';
file_put_contents($reportFile, $report);

echo "📊 Summary:\n";
echo "- Files with undocumented patterns: $totalFiles\n";
echo "- Total undocumented patterns: $totalPatterns\n";
echo "- Report saved to: $reportFile\n\n";

if ($totalPatterns > 0) {
    echo $report;

    if ($apply) {
        echo "\n📝 Applying documentation...\n\n";
        $applied = $generator->applyDocumentation($srcDir);

        echo "\n✨ Applied $applied documentation comments\n";

        if (!$dryRun) {
            echo "⚠️  Backups created with .doc-backup extension\n";
            echo "To restore: find src -name '*.doc-backup' -exec bash -c 'mv \"\$0\" \"\${0%.doc-backup}\"' {} \\;\n";
        }
    } else {
        echo "💡 To apply documentation, run with --apply flag\n";
        echo "   php bin/add-regex-documentation.php --apply\n";
        echo "   (use --dry-run to preview changes)\n";
    }
} else {
    echo "✅ All regex patterns are already documented!\n";
}
