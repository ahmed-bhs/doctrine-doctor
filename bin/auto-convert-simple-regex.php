#!/usr/bin/env php
<?php

/**
 * Script: Conversion automatique des regex simples → str_contains()
 *
 * Convertit automatiquement les patterns simples avec backup
 *
 * Usage: php bin/auto-convert-simple-regex.php [--dry-run]
 */

declare(strict_types=1);

class SimpleRegexConverter
{
    private const CONVERSIONS = [
        // Pattern regex => [keyword, case_sensitive]
        '/ORDER BY/i' => ['ORDER BY', false],
        '/GROUP BY/i' => ['GROUP BY', false],
        '/WHERE/i' => ['WHERE', false],
        '/LIMIT/i' => ['LIMIT', false],
        '/DISTINCT/i' => ['DISTINCT', false],
        '/HAVING/i' => ['HAVING', false],
        '/UNION/i' => ['UNION', false],
        '/LEFT JOIN/i' => ['LEFT JOIN', false],
        '/INNER JOIN/i' => ['INNER JOIN', false],
        '/RIGHT JOIN/i' => ['RIGHT JOIN', false],
        '/\\sJOIN\\s/i' => [' JOIN ', false],
    ];

    private bool $dryRun = false;
    private array $changes = [];

    public function __construct(bool $dryRun = false)
    {
        $this->dryRun = $dryRun;
    }

    public function convertFile(string $filePath): int
    {
        $content = file_get_contents($filePath);
        $originalContent = $content;
        $changesInFile = 0;

        foreach (self::CONVERSIONS as $regexPattern => $replacement) {
            [$keyword, $caseSensitive] = $replacement;

            // Detect various preg_match patterns
            $patterns = [
                // preg_match('/PATTERN/i', $var)
                sprintf("/preg_match\(\s*'%s'\s*,\s*(\\\$\w+)\s*\)/", preg_quote($regexPattern, '/')),
                // preg_match("/PATTERN/i", $var)
                sprintf('/preg_match\(\s*"%s"\s*,\s*(\$\w+)\s*\)/', preg_quote($regexPattern, '/')),
            ];

            foreach ($patterns as $searchPattern) {
                if (preg_match_all($searchPattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                    foreach ($matches as $match) {
                        $fullMatch = $match[0][0];
                        $variable = $match[1][0];

                        // Generate replacement
                        if ($caseSensitive) {
                            $replacement = sprintf("str_contains(%s, '%s')", $variable, $keyword);
                        } else {
                            $replacement = sprintf("str_contains(strtoupper(%s), '%s')", $variable, strtoupper($keyword));
                        }

                        // Replace
                        $content = str_replace($fullMatch, $replacement, $content);
                        $changesInFile++;

                        $this->changes[] = [
                            'file' => $filePath,
                            'old' => $fullMatch,
                            'new' => $replacement,
                        ];
                    }
                }
            }
        }

        // Write back if changes made and not dry run
        if ($changesInFile > 0 && !$this->dryRun) {
            // Backup original
            $backupPath = $filePath . '.regex-backup';
            file_put_contents($backupPath, $originalContent);

            // Write converted
            file_put_contents($filePath, $content);
        }

        return $changesInFile;
    }

    public function convertDirectory(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $changes = $this->convertFile($file->getPathname());
                if ($changes > 0) {
                    $status = $this->dryRun ? '🔍 Would convert' : '✅ Converted';
                    echo sprintf(
                        "%s %d patterns in %s\n",
                        $status,
                        $changes,
                        str_replace(dirname(dirname($file->getPathname())) . '/', '', $file->getPathname())
                    );
                }
            }
        }
    }

    public function getChanges(): array
    {
        return $this->changes;
    }

    public function generateChangeReport(): string
    {
        $report = "# Regex Conversion Report\n\n";
        $report .= "Date: " . date('Y-m-d H:i:s') . "\n";
        $report .= "Total changes: " . count($this->changes) . "\n\n";

        $fileGroups = [];
        foreach ($this->changes as $change) {
            $file = str_replace(dirname(dirname($change['file'])) . '/', '', $change['file']);
            if (!isset($fileGroups[$file])) {
                $fileGroups[$file] = [];
            }
            $fileGroups[$file][] = $change;
        }

        foreach ($fileGroups as $file => $changes) {
            $report .= "## $file\n\n";
            foreach ($changes as $change) {
                $report .= "```diff\n";
                $report .= "- " . $change['old'] . "\n";
                $report .= "+ " . $change['new'] . "\n";
                $report .= "```\n\n";
            }
        }

        return $report;
    }

    public function restoreBackups(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir)
        );

        $restored = 0;
        foreach ($iterator as $file) {
            if (str_ends_with($file->getFilename(), '.regex-backup')) {
                $originalFile = str_replace('.regex-backup', '', $file->getPathname());
                copy($file->getPathname(), $originalFile);
                unlink($file->getPathname());
                $restored++;
                echo "✅ Restored: $originalFile\n";
            }
        }

        echo "\n✨ Restored $restored files from backup\n";
    }
}

// Main execution
$dryRun = in_array('--dry-run', $argv, true);
$restore = in_array('--restore', $argv, true);

$srcDir = __DIR__ . '/../src';

if ($restore) {
    echo "🔄 Restoring backups...\n\n";
    $converter = new SimpleRegexConverter();
    $converter->restoreBackups($srcDir);
    exit(0);
}

$converter = new SimpleRegexConverter($dryRun);

if ($dryRun) {
    echo "🔍 DRY RUN MODE - No files will be modified\n";
    echo "Remove --dry-run flag to apply changes\n\n";
}

echo "🔧 Converting simple regex patterns to str_contains()...\n\n";

$converter->convertDirectory($srcDir);

// Generate report
if (count($converter->getChanges()) > 0) {
    $report = $converter->generateChangeReport();
    $reportFile = __DIR__ . '/../docs/REGEX_CONVERSION_REPORT.md';
    file_put_contents($reportFile, $report);

    echo "\n📊 Summary:\n";
    echo "- Total changes: " . count($converter->getChanges()) . "\n";
    echo "- Report saved to: $reportFile\n";

    if (!$dryRun) {
        echo "\n⚠️  Backups created with .regex-backup extension\n";
        echo "To restore: php " . __FILE__ . " --restore\n";
    }
} else {
    echo "\n✨ No simple regex patterns found to convert\n";
}
