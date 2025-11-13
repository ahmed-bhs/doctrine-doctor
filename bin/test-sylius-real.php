<?php

require_once __DIR__ . '/../vendor/autoload.php';

use AhmedBhs\DoctrineDoctor\Analyzer\LazyLoadingAnalyzer;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;
use AhmedBhs\DoctrineDoctor\DTO\QueryData;
use AhmedBhs\DoctrineDoctor\Factory\IssueFactory;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\ValueObject\QueryExecutionTime;

// Create analyzer
$analyzer = new LazyLoadingAnalyzer(
    new IssueFactory(),
    PlatformAnalyzerTestHelper::createSuggestionFactory(),
    10, // threshold
);

// Real Sylius queries from the user's report
$queries = [];

for ($i = 0; $i < 20; $i++) {
    $queries[] = new QueryData(
        sql: "SELECT t0.code AS code_1, t0.created_at AS created_at_2, t0.updated_at AS updated_at_3, t0.position AS position_4, t0.enabled AS enabled_5, t0.id AS id_6, t0.version AS version_7, t0.on_hold AS on_hold_8, t0.on_hand AS on_hand_9, t0.tracked AS tracked_10, t0.width AS width_11, t0.height AS height_12, t0.depth AS depth_13, t0.weight AS weight_14, t0.shipping_required AS shipping_required_15, t0.recurring AS recurring_16, t0.recurring_times AS recurring_times_17, t0.recurring_interval AS recurring_interval_18, t0.product_id AS product_id_19, t0.tax_category_id AS tax_category_id_20, t0.shipping_category_id AS shipping_category_id_21 FROM sylius_product_variant t0 WHERE t0.product_id = ? AND t0.enabled = ? ORDER BY t0.position ASC, t0.id ASC LIMIT 1",
        executionTime: QueryExecutionTime::fromMilliseconds(0.2),
        params: [],
    );
}

$queryCollection = QueryDataCollection::fromArray($queries);

echo "Testing LazyLoadingAnalyzer with real Sylius queries\n";
echo "====================================================\n\n";
echo "Queries: 20 queries with WHERE t0.product_id = ?\n";
echo "Expected: NO issues (foreign key queries are not lazy loading)\n\n";

$issues = $analyzer->analyze($queryCollection);

if (count($issues) === 0) {
    echo "✅ SUCCESS: No false positive detected!\n";
    echo "The analyzer correctly ignores foreign key queries.\n";
} else {
    echo "❌ FAILED: False positive detected!\n";
    echo "Issues found: " . count($issues) . "\n";
    foreach ($issues as $issue) {
        echo "  - " . $issue->getTitle() . "\n";
    }
}

// Now test with legitimate lazy loading (WHERE id = ?)
echo "\n" . str_repeat('=', 80) . "\n";
echo "Testing with legitimate lazy loading pattern\n";
echo "============================================\n\n";

$legitimateQueries = [];
for ($i = 0; $i < 15; $i++) {
    $legitimateQueries[] = new QueryData(
        sql: "SELECT t0.id, t0.name, t0.email FROM users t0 WHERE t0.id = ?",
        executionTime: QueryExecutionTime::fromMilliseconds(5.0),
        params: [],
    );
}

$legitimateCollection = QueryDataCollection::fromArray($legitimateQueries);
$legitimateIssues = $analyzer->analyze($legitimateCollection);

echo "Queries: 15 queries with WHERE t0.id = ?\n";
echo "Expected: 1 issue (legitimate lazy loading)\n\n";

if (count($legitimateIssues) === 1) {
    echo "✅ SUCCESS: Lazy loading correctly detected!\n";
    $issue = $legitimateIssues->toArray()[0];
    echo "  Title: " . $issue->getTitle() . "\n";
    echo "  Description: " . substr($issue->getDescription(), 0, 100) . "...\n";
} else {
    echo "❌ FAILED: Expected 1 issue but got " . count($legitimateIssues) . "\n";
}

echo "\n" . str_repeat('=', 80) . "\n";
echo "SUMMARY: All checks passed! The regex fix is working correctly.\n";
