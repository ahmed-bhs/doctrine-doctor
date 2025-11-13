#!/usr/bin/env php
<?php

/**
 * Script to check which QUERY analyzers don't properly handle backtrace.
 *
 * This focuses on analyzers that work with QueryDataCollection
 * and should have backtrace support.
 */

$analyzerDir = __DIR__ . '/../src/Analyzer';

if (!is_dir($analyzerDir)) {
    echo "Error: Analyzer directory not found at $analyzerDir\n";
    exit(1);
}

echo "Checking QUERY analyzers for backtrace handling...\n\n";

$analyzers = glob($analyzerDir . '/*Analyzer.php');
$queryAnalyzers = [];

foreach ($analyzers as $analyzerFile) {
    $analyzerName = basename($analyzerFile, '.php');
    $content = file_get_contents($analyzerFile);

    // Check if it's a query analyzer (works with QueryDataCollection)
    $isQueryAnalyzer = preg_match('/QueryDataCollection/s', $content) ||
                       preg_match('/foreach\s*\(\s*\$queries/s', $content) ||
                       preg_match('/foreach\s*\(\s*\$queryDataCollection/s', $content);

    if (!$isQueryAnalyzer) {
        continue;
    }

    $result = [
        'name' => $analyzerName,
        'file' => $analyzerFile,
        'has_backtrace_param' => false,
        'uses_query_backtrace' => false,
        'backtrace_locations' => [],
    ];

    // Check if IssueData/PerformanceIssue is created with backtrace
    if (preg_match_all('/(?:new\s+(?:IssueData|PerformanceIssue)\s*\(|backtrace:\s*([^,\)]+))/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
        if (preg_match('/backtrace:\s*([^,\)]+)/s', $content, $backtraceMatch)) {
            $result['has_backtrace_param'] = true;
            $result['backtrace_locations'][] = trim($backtraceMatch[1]);

            // Check if it's from query object
            if (preg_match('/\$query(Data)?->\s*backtrace/', $content) ||
                preg_match('/extractBacktrace\s*\(\s*\$query/', $content)) {
                $result['uses_query_backtrace'] = true;
            }
        }

        // Also check array style
        if (preg_match_all('/["\']backtrace["\']\s*=>\s*([^,\]]+)/s', $content, $arrayMatches)) {
            $result['has_backtrace_param'] = true;
            foreach ($arrayMatches[1] as $match) {
                $result['backtrace_locations'][] = trim($match);
            }

            if (preg_match('/\$query(Data)?->\s*backtrace/', $content) ||
                preg_match('/extractBacktrace\s*\(\s*\$query/', $content)) {
                $result['uses_query_backtrace'] = true;
            }
        }
    }

    $queryAnalyzers[] = $result;
}

// Display results
echo "=" . str_repeat("=", 80) . "\n";
echo "QUERY ANALYZERS SUMMARY\n";
echo "=" . str_repeat("=", 80) . "\n\n";

$withBacktrace = 0;
$withoutBacktrace = 0;
$usingQueryBacktrace = 0;

foreach ($queryAnalyzers as $result) {
    if ($result['has_backtrace_param']) {
        $withBacktrace++;
        if ($result['uses_query_backtrace']) {
            $usingQueryBacktrace++;
        }
    } else {
        $withoutBacktrace++;
    }
}

echo "Total query analyzers: " . count($queryAnalyzers) . "\n";
echo "With backtrace parameter: $withBacktrace\n";
echo "WITHOUT backtrace parameter: $withoutBacktrace ⚠️\n";
echo "Using proper query backtrace: $usingQueryBacktrace\n";

echo "\n" . str_repeat("=", 80) . "\n";
echo "⚠️  QUERY ANALYZERS WITHOUT BACKTRACE (SHOULD BE FIXED)\n";
echo str_repeat("=", 80) . "\n\n";

$needFix = [];
foreach ($queryAnalyzers as $result) {
    if (!$result['has_backtrace_param']) {
        echo "❌ {$result['name']}\n";
        $needFix[] = $result['name'];
    }
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "✅ QUERY ANALYZERS WITH PROPER BACKTRACE\n";
echo str_repeat("=", 80) . "\n\n";

foreach ($queryAnalyzers as $result) {
    if ($result['uses_query_backtrace']) {
        echo "✅ {$result['name']}\n";
    }
}

if (!empty($needFix)) {
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "ACTION REQUIRED\n";
    echo str_repeat("=", 80) . "\n\n";
    echo "The following query analyzers need to be fixed:\n";
    foreach ($needFix as $name) {
        echo "  - $name\n";
    }
    echo "\nThese analyzers work with queries but don't extract/use backtrace.\n";
    echo "Users won't be able to see WHERE in their code the issues come from.\n";
}

echo "\n";

exit(count($needFix) > 0 ? 1 : 0);
