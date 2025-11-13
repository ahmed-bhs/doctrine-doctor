<?php

// Test if LazyLoadingAnalyzer pattern matches the reported queries

$queries = [
    "SELECT t0.code AS code_1, t0.created_at AS created_at_2, t0.updated_at AS updated_at_3, t0.position AS position_4, t0.enabled AS enabled_5, t0.id AS id_6, t0.version AS version_7, t0.on_hold AS on_hold_8, t0.on_hand AS on_hand_9, t0.tracked AS tracked_10, t0.width AS width_11, t0.height AS height_12, t0.depth AS depth_13, t0.weight AS weight_14, t0.shipping_required AS shipping_required_15, t0.recurring AS recurring_16, t0.recurring_times AS recurring_times_17, t0.recurring_interval AS recurring_interval_18, t0.product_id AS product_id_19, t0.tax_category_id AS tax_category_id_20, t0.shipping_category_id AS shipping_category_id_21 FROM sylius_product_variant t0 WHERE t0.product_id = ? AND t0.enabled = ? ORDER BY t0.position ASC, t0.id ASC LIMIT 1",

    "SELECT t0.code AS code_1, t0.created_at AS created_at_2, t0.updated_at AS updated_at_3, t0.position AS position_4, t0.enabled AS enabled_5, t0.id AS id_6, t0.version AS version_7, t0.on_hold AS on_hold_8, t0.on_hand AS on_hand_9, t0.tracked AS tracked_10, t0.width AS width_11, t0.height AS height_12, t0.depth AS depth_13, t0.weight AS weight_14, t0.shipping_required AS shipping_required_15, t0.recurring AS recurring_16, t0.recurring_times AS recurring_times_17, t0.recurring_interval AS recurring_interval_18, t0.product_id AS product_id_19, t0.tax_category_id AS tax_category_id_20, t0.shipping_category_id AS shipping_category_id_21 FROM sylius_product_variant t0 WHERE t0.product_id = ? ORDER BY t0.position ASC, t0.id ASC"
];

// LazyLoadingAnalyzer pattern (line 98)
$pattern = '/SELECT\s+.*\s+FROM\s+(\w+)\s+.*WHERE\s+.*\.?id\s*=\s*\?/i';

echo "Testing LazyLoadingAnalyzer pattern:\n";
echo "Pattern: {$pattern}\n\n";

foreach ($queries as $index => $sql) {
    echo "Query " . ($index + 1) . ":\n";
    echo substr($sql, 0, 100) . "...\n";

    if (preg_match($pattern, $sql, $matches)) {
        echo "✅ MATCHES - Table captured: {$matches[1]}\n";
        echo "Full matches: " . json_encode($matches) . "\n";
    } else {
        echo "❌ NO MATCH\n";
    }
    echo "\n";
}

// Test more specific patterns
echo "\n--- Testing more specific patterns ---\n\n";

// Pattern that should match only WHERE id = ? (not product_id)
$strictPattern = '/WHERE\s+\w+\.id\s*=\s*\?/i';
echo "Strict pattern (only .id): {$strictPattern}\n";
foreach ($queries as $index => $sql) {
    echo "Query " . ($index + 1) . ": ";
    echo (preg_match($strictPattern, $sql) ? "✅ MATCHES" : "❌ NO MATCH") . "\n";
}

echo "\n";

// Pattern that matches foreign keys like product_id
$foreignKeyPattern = '/WHERE\s+\w+\.\w+_id\s*=\s*\?/i';
echo "Foreign key pattern (_id): {$foreignKeyPattern}\n";
foreach ($queries as $index => $sql) {
    echo "Query " . ($index + 1) . ": ";
    echo (preg_match($foreignKeyPattern, $sql) ? "✅ MATCHES" : "❌ NO MATCH") . "\n";
}
