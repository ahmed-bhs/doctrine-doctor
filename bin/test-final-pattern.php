<?php

// Test final pattern with negative lookbehind to prevent matching foreign keys

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

// Solution with negative lookbehind: id must not be preceded by underscore or word char
$bestPattern = '/SELECT\s+.*\s+FROM\s+(\w+)\s+.*WHERE\s+.*(?<![_\w])id\s*=\s*\?/i';

echo "=== Testing BEST pattern (negative lookbehind) ===\n";
echo "Pattern: {$bestPattern}\n";
echo "Explanation: (?<![_\\w])id ensures 'id' is not preceded by underscore or alphanumeric\n\n";

$correct = 0;
$total = 0;

foreach ($testCases as $sql => $shouldMatch) {
    $total++;
    $matches = preg_match($bestPattern, $sql);
    $actualMatch = $matches === 1;
    $isCorrect = $actualMatch === $shouldMatch;

    if ($isCorrect) {
        $correct++;
    }

    $status = $isCorrect ? '✅' : '❌';
    $expected = $shouldMatch ? 'MATCH' : 'NO MATCH';
    $actual = $actualMatch ? 'MATCH' : 'NO MATCH';

    echo "{$status} Expected: {$expected}, Got: {$actual}\n";
    echo "   SQL: " . substr($sql, 0, 70) . (strlen($sql) > 70 ? '...' : '') . "\n\n";
}

echo "\nAccuracy: {$correct}/{$total} (" . round(($correct/$total)*100, 1) . "%)\n";

if ($correct === $total) {
    echo "\n🎉 PERFECT! This pattern correctly handles all test cases!\n";
} else {
    echo "\n⚠️  Pattern needs further refinement\n";
}

// Test with real Sylius queries
echo "\n" . str_repeat('=', 80) . "\n";
echo "=== Testing with REAL Sylius queries ===\n\n";

$syliusQuery1 = "SELECT t0.code AS code_1, t0.created_at AS created_at_2, t0.updated_at AS updated_at_3, t0.position AS position_4, t0.enabled AS enabled_5, t0.id AS id_6, t0.version AS version_7, t0.on_hold AS on_hold_8, t0.on_hand AS on_hand_9, t0.tracked AS tracked_10, t0.width AS width_11, t0.height AS height_12, t0.depth AS depth_13, t0.weight AS weight_14, t0.shipping_required AS shipping_required_15, t0.recurring AS recurring_16, t0.recurring_times AS recurring_times_17, t0.recurring_interval AS recurring_interval_18, t0.product_id AS product_id_19, t0.tax_category_id AS tax_category_id_20, t0.shipping_category_id AS shipping_category_id_21 FROM sylius_product_variant t0 WHERE t0.product_id = ? AND t0.enabled = ?";

$syliusQuery2 = "SELECT t0.code AS code_1, t0.created_at AS created_at_2, t0.updated_at AS updated_at_3, t0.position AS position_4, t0.enabled AS enabled_5, t0.id AS id_6 FROM sylius_product_variant t0 WHERE t0.product_id = ?";

echo "Query 1 (Sylius with product_id):\n";
if (preg_match($bestPattern, $syliusQuery1)) {
    echo "❌ MATCH - Should NOT match (false positive)\n";
} else {
    echo "✅ NO MATCH - Correct! (not lazy loading, it's a foreign key query)\n";
}

echo "\nQuery 2 (Sylius with product_id):\n";
if (preg_match($bestPattern, $syliusQuery2)) {
    echo "❌ MATCH - Should NOT match (false positive)\n";
} else {
    echo "✅ NO MATCH - Correct! (not lazy loading, it's a foreign key query)\n";
}
