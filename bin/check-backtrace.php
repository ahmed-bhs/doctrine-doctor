#!/usr/bin/env php
<?php

/**
 * Script to check which analyzers don't properly handle backtrace.
 *
 * This script scans all analyzer files and checks if they:
 * 1. Create IssueData with backtrace parameter
 * 2. Use synthetic backtrace methods vs real backtrace from query
 */

$analyzerDir = __DIR__ . '/../src/Analyzer';

if (!is_dir($analyzerDir)) {
    echo "Error: Analyzer directory not found at $analyzerDir\n";
    exit(1);
}

echo "Checking analyzers for backtrace handling...\n\n";

$analyzers = glob($analyzerDir . '/*Analyzer.php');
$results = [];

foreach ($analyzers as $analyzerFile) {
    $analyzerName = basename($analyzerFile, '.php');
    $content = file_get_contents($analyzerFile);

    $result = [
        'name' => $analyzerName,
        'file' => $analyzerFile,
        'has_backtrace_param' => false,
        'uses_query_backtrace' => false,
        'uses_synthetic_backtrace' => false,
        'backtrace_methods' => [],
    ];

    // Check if IssueData is created with backtrace parameter
    if (preg_match('/new\s+IssueData\s*\(/s', $content) ||
        preg_match('/IssueData\s*\(/s', $content)) {
        // Check if backtrace is in the parameters
        if (preg_match('/backtrace:\s*([^,\)]+)/s', $content, $matches)) {
            $result['has_backtrace_param'] = true;
            $result['backtrace_methods'][] = trim($matches[1]);

            // Check if it's from query object
            if (preg_match('/\$query(Data)?->\s*backtrace/', $content) ||
                preg_match('/\$.*\[[\'"]*backtrace[\'"]*\]/', $content) ||
                preg_match('/extractBacktrace\s*\(\s*\$query/', $content)) {
                $result['uses_query_backtrace'] = true;
            }

            // Check if it uses synthetic backtrace
            if (preg_match('/createEntityBacktrace|createSqlBacktrace|createSyntheticBacktrace/', $content)) {
                $result['uses_synthetic_backtrace'] = true;
            }
        }
    }

    // Check for PerformanceIssue creation
    if (preg_match('/new\s+PerformanceIssue\s*\(\s*\[/s', $content)) {
        if (preg_match_all('/["\']backtrace["\']\s*=>\s*([^,\]]+)/s', $content, $matches)) {
            $result['has_backtrace_param'] = true;
            foreach ($matches[1] as $match) {
                $result['backtrace_methods'][] = trim($match);
            }

            // Check if it's from query object
            if (preg_match('/\$query(Data)?->\s*backtrace/', $content) ||
                preg_match('/\$.*\[[\'"]*backtrace[\'"]*\]/', $content) ||
                preg_match('/extractBacktrace\s*\(\s*\$query/', $content)) {
                $result['uses_query_backtrace'] = true;
            }

            // Check if it uses synthetic backtrace
            if (preg_match('/createEntityBacktrace|createSqlBacktrace|createSyntheticBacktrace/', $content)) {
                $result['uses_synthetic_backtrace'] = true;
            }
        }
    }

    $results[] = $result;
}

// Display results
echo "=" . str_repeat("=", 80) . "\n";
echo "SUMMARY\n";
echo "=" . str_repeat("=", 80) . "\n\n";

$withBacktrace = 0;
$withoutBacktrace = 0;
$usingSynthetic = 0;
$usingQueryBacktrace = 0;

foreach ($results as $result) {
    if ($result['has_backtrace_param']) {
        $withBacktrace++;
        if ($result['uses_query_backtrace']) {
            $usingQueryBacktrace++;
        }
        if ($result['uses_synthetic_backtrace']) {
            $usingSynthetic++;
        }
    } else {
        $withoutBacktrace++;
    }
}

echo "Total analyzers: " . count($results) . "\n";
echo "With backtrace parameter: $withBacktrace\n";
echo "Without backtrace parameter: $withoutBacktrace\n";
echo "Using query backtrace: $usingQueryBacktrace\n";
echo "Using synthetic backtrace: $usingSynthetic\n";

echo "\n" . str_repeat("=", 80) . "\n";
echo "ANALYZERS WITHOUT BACKTRACE\n";
echo str_repeat("=", 80) . "\n\n";

foreach ($results as $result) {
    if (!$result['has_backtrace_param']) {
        echo "❌ {$result['name']}\n";
    }
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "ANALYZERS WITH SYNTHETIC BACKTRACE (may need review)\n";
echo str_repeat("=", 80) . "\n\n";

foreach ($results as $result) {
    if ($result['uses_synthetic_backtrace'] && !$result['uses_query_backtrace']) {
        echo "⚠️  {$result['name']}\n";
        echo "   Methods: " . implode(', ', $result['backtrace_methods']) . "\n";
    }
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "ANALYZERS WITH PROPER QUERY BACKTRACE\n";
echo str_repeat("=", 80) . "\n\n";

foreach ($results as $result) {
    if ($result['uses_query_backtrace']) {
        echo "✅ {$result['name']}\n";
    }
}

echo "\n";
