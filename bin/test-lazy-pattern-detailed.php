<?php

// Detailed analysis of the LazyLoadingAnalyzer regex bug

$testCases = [
    // Should MATCH (legitimate lazy loading)
    'SELECT * FROM users WHERE id = ?' => true,
    'SELECT * FROM users u WHERE u.id = ?' => true,
    'SELECT t0.* FROM users t0 WHERE t0.id = ?' => true,
    'SELECT p.id, p.title FROM posts p WHERE p.id = ?' => true,
    'SELECT t0.id, t0.name FROM users t0 WHERE t0.id = ?' => true,

    // Should NOT match (foreign keys - NOT lazy loading)
    'SELECT * FROM orders WHERE user_id = ?' => false,
    'SELECT * FROM posts WHERE author_id = ?' => false,
    'SELECT t0.* FROM product_variant t0 WHERE t0.product_id = ?' => false,
    'SELECT * FROM comments WHERE post_id = ?' => false,
    'SELECT t0.* FROM orders t0 WHERE t0.customer_id = ?' => false,

    // Should NOT match (other patterns)
    'SELECT * FROM users WHERE name = ?' => false,
    'SELECT * FROM posts WHERE title = ?' => false,
    'INSERT INTO logs VALUES (?)' => false,
];

// Current pattern (BUGGY)
$currentPattern = '/SELECT\s+.*\s+FROM\s+(\w+)\s+.*WHERE\s+.*\.?id\s*=\s*\?/i';

// Proposed fix - more strict
$fixedPattern = '/SELECT\s+.*\s+FROM\s+(\w+)\s+.*WHERE\s+\w+\.id\s*=\s*\?/i';

// Alternative fix - word boundary
$alternativePattern = '/SELECT\s+.*\s+FROM\s+(\w+)\s+.*WHERE\s+.*\.\bid\b\s*=\s*\?/i';

echo "=== Testing CURRENT pattern (buggy) ===\n";
echo "Pattern: {$currentPattern}\n\n";

$currentCorrect = 0;
$currentTotal = 0;

foreach ($testCases as $sql => $shouldMatch) {
    $currentTotal++;
    $matches = preg_match($currentPattern, $sql);
    $actualMatch = $matches === 1;
    $isCorrect = $actualMatch === $shouldMatch;

    if ($isCorrect) {
        $currentCorrect++;
    }

    $status = $isCorrect ? '✅' : '❌';
    $expected = $shouldMatch ? 'MATCH' : 'NO MATCH';
    $actual = $actualMatch ? 'MATCH' : 'NO MATCH';

    echo "{$status} Expected: {$expected}, Got: {$actual}\n";
    echo "   SQL: {$sql}\n\n";
}

echo "\nCurrent accuracy: {$currentCorrect}/{$currentTotal}\n";
echo str_repeat('=', 80) . "\n\n";

echo "=== Testing FIXED pattern (strict) ===\n";
echo "Pattern: {$fixedPattern}\n\n";

$fixedCorrect = 0;
$fixedTotal = 0;

foreach ($testCases as $sql => $shouldMatch) {
    $fixedTotal++;
    $matches = preg_match($fixedPattern, $sql);
    $actualMatch = $matches === 1;
    $isCorrect = $actualMatch === $shouldMatch;

    if ($isCorrect) {
        $fixedCorrect++;
    }

    $status = $isCorrect ? '✅' : '❌';
    $expected = $shouldMatch ? 'MATCH' : 'NO MATCH';
    $actual = $actualMatch ? 'MATCH' : 'NO MATCH';

    echo "{$status} Expected: {$expected}, Got: {$actual}\n";
    echo "   SQL: {$sql}\n\n";
}

echo "\nFixed accuracy: {$fixedCorrect}/{$fixedTotal}\n";
echo str_repeat('=', 80) . "\n\n";

echo "=== Testing ALTERNATIVE pattern (word boundary) ===\n";
echo "Pattern: {$alternativePattern}\n\n";

$altCorrect = 0;
$altTotal = 0;

foreach ($testCases as $sql => $shouldMatch) {
    $altTotal++;
    $matches = preg_match($alternativePattern, $sql);
    $actualMatch = $matches === 1;
    $isCorrect = $actualMatch === $shouldMatch;

    if ($isCorrect) {
        $altCorrect++;
    }

    $status = $isCorrect ? '✅' : '❌';
    $expected = $shouldMatch ? 'MATCH' : 'NO MATCH';
    $actual = $actualMatch ? 'MATCH' : 'NO MATCH';

    echo "{$status} Expected: {$expected}, Got: {$actual}\n";
    echo "   SQL: {$sql}\n\n";
}

echo "\nAlternative accuracy: {$altCorrect}/{$altTotal}\n";
echo str_repeat('=', 80) . "\n\n";

echo "=== SUMMARY ===\n";
echo "Current pattern accuracy: {$currentCorrect}/{$currentTotal} (" . round(($currentCorrect/$currentTotal)*100, 1) . "%)\n";
echo "Fixed pattern accuracy: {$fixedCorrect}/{$fixedTotal} (" . round(($fixedCorrect/$fixedTotal)*100, 1) . "%)\n";
echo "Alternative pattern accuracy: {$altCorrect}/{$altTotal} (" . round(($altCorrect/$altTotal)*100, 1) . "%)\n";
