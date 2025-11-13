#!/usr/bin/env php
<?php

/**
 * Script: Analyse automatique des patterns regex dans le code
 *
 * Détecte:
 * - Les regex simples remplaçables par str_contains()
 * - Les regex complexes nécessitant un parser
 * - Les regex sans documentation
 *
 * Usage: php bin/analyze-regex-patterns.php [--fix]
 */

declare(strict_types=1);

$autoloadFiles = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
];

foreach ($autoloadFiles as $autoloadFile) {
    if (file_exists($autoloadFile)) {
        require_once $autoloadFile;
        break;
    }
}

class RegexPatternAnalyzer
{
    private const SIMPLE_KEYWORD_PATTERNS = [
        // Patterns SQL simples remplaçables par str_contains()
        '/ORDER BY/i' => ['replacement' => 'str_contains', 'keyword' => 'ORDER BY'],
        '/GROUP BY/i' => ['replacement' => 'str_contains', 'keyword' => 'GROUP BY'],
        '/WHERE/i' => ['replacement' => 'str_contains', 'keyword' => 'WHERE'],
        '/LIMIT/i' => ['replacement' => 'str_contains', 'keyword' => 'LIMIT'],
        '/DISTINCT/i' => ['replacement' => 'str_contains', 'keyword' => 'DISTINCT'],
        '/HAVING/i' => ['replacement' => 'str_contains', 'keyword' => 'HAVING'],
        '/UNION/i' => ['replacement' => 'str_contains', 'keyword' => 'UNION'],
        '/JOIN/i' => ['replacement' => 'str_contains', 'keyword' => 'JOIN'],
        '/LEFT JOIN/i' => ['replacement' => 'str_contains', 'keyword' => 'LEFT JOIN'],
        '/INNER JOIN/i' => ['replacement' => 'str_contains', 'keyword' => 'INNER JOIN'],
    ];

    private const COMPLEX_PATTERNS = [
        // Patterns complexes nécessitant un parser
        'JOIN extraction' => '/\\b(LEFT|INNER|RIGHT).*JOIN/i',
        'String literals' => '/\'(?:[^\'\\\\]|\\\\.)*\'/',
        'Subqueries' => '/\(.*SELECT.*\)/is',
    ];

    private array $results = [
        'simple' => [],      // Remplaçables par str_contains()
        'complex' => [],     // Nécessitent parser
        'documented' => [],  // Bien documentés
        'undocumented' => [], // Sans doc
    ];

    public function analyzeDirectory(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $this->analyzeFile($file->getPathname());
            }
        }
    }

    private function analyzeFile(string $filePath): void
    {
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        // Détecter tous les preg_match, preg_match_all, preg_replace
        if (preg_match_all(
            '/preg_(match|match_all|replace)\s*\(\s*([\'"])(.+?)\2/s',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($matches[3] as $index => $match) {
                $pattern = $match[0];
                $offset = $match[1];
                $type = $matches[1][$index][0];

                // Trouver le numéro de ligne
                $lineNumber = substr_count($content, "\n", 0, $offset) + 1;

                // Vérifier si documenté (commentaire dans les 3 lignes précédentes)
                $isDocumented = $this->isPatternDocumented($lines, $lineNumber);

                // Classifier le pattern
                $classification = $this->classifyPattern($pattern);

                $result = [
                    'file' => str_replace(dirname(__DIR__) . '/', '', $filePath),
                    'line' => $lineNumber,
                    'type' => $type,
                    'pattern' => $pattern,
                    'classification' => $classification,
                    'documented' => $isDocumented,
                    'suggestion' => $this->getSuggestion($pattern, $classification),
                ];

                if ($classification === 'simple') {
                    $this->results['simple'][] = $result;
                } elseif ($classification === 'complex') {
                    $this->results['complex'][] = $result;
                }

                if ($isDocumented) {
                    $this->results['documented'][] = $result;
                } else {
                    $this->results['undocumented'][] = $result;
                }
            }
        }
    }

    private function isPatternDocumented(array $lines, int $lineNumber): bool
    {
        // Vérifier 3 lignes avant
        for ($i = max(0, $lineNumber - 4); $i < $lineNumber - 1; $i++) {
            if (isset($lines[$i]) && str_contains($lines[$i], '//')) {
                return true;
            }
        }
        return false;
    }

    private function classifyPattern(string $pattern): string
    {
        // Simple keyword detection
        foreach (self::SIMPLE_KEYWORD_PATTERNS as $simplePattern => $replacement) {
            if ($pattern === trim($simplePattern, '/')) {
                return 'simple';
            }
        }

        // Complex patterns
        if (str_contains($pattern, '(.*') ||
            str_contains($pattern, '[^') ||
            str_contains($pattern, '(?:') ||
            str_contains($pattern, '\\s*\\(')) {
            return 'complex';
        }

        return 'medium';
    }

    private function getSuggestion(string $pattern, string $classification): string
    {
        if ($classification === 'simple') {
            foreach (self::SIMPLE_KEYWORD_PATTERNS as $simplePattern => $info) {
                if ($pattern === trim($simplePattern, '/')) {
                    return "Replace with: str_contains(strtoupper(\$sql), '{$info['keyword']}')";
                }
            }
        } elseif ($classification === 'complex') {
            if (str_contains($pattern, 'JOIN')) {
                return 'Use SqlStructureExtractor::extractJoins()';
            }
            if (str_contains($pattern, 'SELECT') || str_contains($pattern, 'FROM')) {
                return 'Use SqlStructureExtractor';
            }
        }

        return 'Review manually';
    }

    public function generateReport(): string
    {
        $report = "# Regex Pattern Analysis Report\n";
        $report .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";

        // Summary
        $report .= "## Summary\n\n";
        $report .= sprintf("- **Simple patterns** (replaceable): %d\n", count($this->results['simple']));
        $report .= sprintf("- **Complex patterns** (need parser): %d\n", count($this->results['complex']));
        $report .= sprintf("- **Undocumented patterns**: %d ⚠️\n", count($this->results['undocumented']));
        $report .= sprintf("- **Documented patterns**: %d ✅\n\n", count($this->results['documented']));

        // Simple patterns (quick wins)
        if (!empty($this->results['simple'])) {
            $report .= "## ⚡ Simple Patterns (Quick Wins)\n\n";
            $report .= "These can be replaced with `str_contains()` for better readability:\n\n";

            foreach ($this->results['simple'] as $result) {
                $report .= sprintf(
                    "- `%s:%d` - Pattern: `%s`\n  → %s\n\n",
                    $result['file'],
                    $result['line'],
                    $result['pattern'],
                    $result['suggestion']
                );
            }
        }

        // Complex patterns
        if (!empty($this->results['complex'])) {
            $report .= "## 🔧 Complex Patterns (Need Parser)\n\n";
            $report .= "These patterns are complex and should use a proper parser:\n\n";

            foreach ($this->results['complex'] as $result) {
                $report .= sprintf(
                    "- `%s:%d` - Pattern: `%s`\n  → %s\n\n",
                    $result['file'],
                    $result['line'],
                    substr($result['pattern'], 0, 80) . (strlen($result['pattern']) > 80 ? '...' : ''),
                    $result['suggestion']
                );
            }
        }

        // Undocumented patterns
        if (!empty($this->results['undocumented'])) {
            $report .= "## ⚠️ Undocumented Patterns\n\n";
            $report .= "These patterns lack documentation:\n\n";

            foreach ($this->results['undocumented'] as $result) {
                $report .= sprintf(
                    "- `%s:%d` - Add comment explaining the pattern\n",
                    $result['file'],
                    $result['line']
                );
            }
        }

        return $report;
    }

    public function generateFixScript(): string
    {
        $script = "#!/usr/bin/env php\n<?php\n\n";
        $script .= "/**\n";
        $script .= " * Auto-generated fix script for simple regex replacements\n";
        $script .= " * Generated: " . date('Y-m-d H:i:s') . "\n";
        $script .= " */\n\n";

        $fileReplacements = [];

        foreach ($this->results['simple'] as $result) {
            $file = $result['file'];
            $pattern = $result['pattern'];

            // Extraire le keyword
            foreach (self::SIMPLE_KEYWORD_PATTERNS as $simplePattern => $info) {
                if ($pattern === trim($simplePattern, '/')) {
                    if (!isset($fileReplacements[$file])) {
                        $fileReplacements[$file] = [];
                    }

                    $fileReplacements[$file][] = [
                        'old' => "preg_match('/$pattern', \$sql)",
                        'new' => "str_contains(strtoupper(\$sql), '{$info['keyword']}')",
                        'line' => $result['line'],
                    ];
                    break;
                }
            }
        }

        foreach ($fileReplacements as $file => $replacements) {
            $script .= "\n// File: $file\n";
            $script .= "\$file = __DIR__ . '/../$file';\n";
            $script .= "\$content = file_get_contents(\$file);\n\n";

            foreach ($replacements as $replacement) {
                $script .= sprintf(
                    "// Line %d\n\$content = str_replace(\n    %s,\n    %s,\n    \$content\n);\n\n",
                    $replacement['line'],
                    var_export($replacement['old'], true),
                    var_export($replacement['new'], true)
                );
            }

            $script .= "file_put_contents(\$file, \$content);\n";
            $script .= "echo \"✅ Fixed: $file\\n\";\n";
        }

        return $script;
    }

    public function getResults(): array
    {
        return $this->results;
    }
}

// Main execution
$analyzer = new RegexPatternAnalyzer();

$srcDir = __DIR__ . '/../src';
if (!is_dir($srcDir)) {
    echo "Error: src directory not found\n";
    exit(1);
}

echo "🔍 Analyzing regex patterns in src/...\n\n";
$analyzer->analyzeDirectory($srcDir);

// Generate report
$report = $analyzer->generateReport();
$reportFile = __DIR__ . '/../docs/REGEX_ANALYSIS_REPORT.md';
file_put_contents($reportFile, $report);

echo $report;
echo "\n📄 Full report saved to: $reportFile\n";

// Check if --fix flag provided
if (in_array('--fix', $argv, true)) {
    echo "\n🔧 Generating fix script...\n";

    $fixScript = $analyzer->generateFixScript();
    $fixScriptFile = __DIR__ . '/fix-simple-regex.php';
    file_put_contents($fixScriptFile, $fixScript);
    chmod($fixScriptFile, 0755);

    echo "✅ Fix script generated: $fixScriptFile\n";
    echo "⚠️  Review the script before running it!\n";
    echo "Run: php $fixScriptFile\n";
}

// Statistics
$results = $analyzer->getResults();
$totalSimple = count($results['simple']);
$estimatedTime = $totalSimple * 5; // 5 minutes per replacement

echo "\n📊 Statistics:\n";
echo "- Simple patterns to replace: $totalSimple\n";
echo "- Estimated time savings: ~" . round($estimatedTime / 60, 1) . " hours\n";
echo "- Complex patterns needing parser: " . count($results['complex']) . "\n";
echo "- Undocumented patterns: " . count($results['undocumented']) . "\n";
