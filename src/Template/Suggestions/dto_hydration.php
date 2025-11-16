<?php

declare(strict_types=1);

/**
 * Template for DTO Hydration suggestion.
 * Context variables:
 */
$queryCount = $context['query_count'] ?? null;
$aggregations = $context['aggregations'] ?? null;
$hasGroupBy = $context['has_group_by'] ?? null;

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Use DTO Hydration for Aggregation Queries</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Performance Optimization Available</strong><br>
        Detected <strong><?php echo $queryCount; ?> <?php echo $queryCount > 1 ? 'queries' : 'query'; ?></strong> with aggregations
        (<?php echo implode(', ', array_map($e, $aggregations)); ?>) that should use DTO hydration.<br>
        DTO hydration is <strong>3-5x faster</strong> and type-safe!
    </div>

    <h4>📢 Current Approach (Inefficient)</h4>
    <div class="query-item">
        <pre><code class="language-php">// Returns mixed arrays, hard to maintain
$query = $em->createQuery("
    SELECT u.name, u.email, SUM(o.total) as revenue, COUNT(o.id) as orderCount
    FROM User u
    JOIN u.orders o
    GROUP BY u.id
");
$results = $query->getResult();

assert(is_iterable($results), '$results must be iterable');


foreach ($results as $row) {
    // Array access - no IDE autocomplete, prone to typos
    echo $row['name'] . ': ' . $row['revenue'];
    // Type casting needed
    $revenue = (float) $row['revenue'];
}</code></pre>
    </div>

    <h4> Solution: DTO Hydration (3-5x Faster!)</h4>

    <h5>Step 1: Create a DTO Class</h5>
    <div class="query-item">
        <pre><code class="language-php">namespace App\DTO;

class UserRevenue
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly float $revenue,
        public readonly int $orderCount
    ) {}
}</code></pre>
    </div>

    <h5>Step 2: Use NEW Syntax in Query</h5>
    <div class="query-item">
        <pre><code class="language-php">$query = $em->createQuery("
    SELECT NEW App\\DTO\\UserRevenue(
        u.name,
        u.email,
        SUM(o.total),
        COUNT(o.id)
    )
    FROM User u
    JOIN u.orders o
    GROUP BY u.id
");

$results = $query->getResult();

assert(is_iterable($results), '$results must be iterable');


foreach ($results as $userRevenue) {
    // Type-safe objects with IDE autocomplete
    echo $userRevenue->name . ': ' . $userRevenue->revenue;
    // No type casting needed - already typed
    $revenue = $userRevenue->revenue; // float
}</code></pre>
    </div>

    <h4>Benefits of DTO Hydration</h4>
    <ul>
        <li>⚡ <strong>3-5x faster</strong> than array/object hydration</li>
        <li>💾 <strong>70% less memory</strong> usage</li>
        <li>🔒 <strong>Type-safe</strong> (constructor enforces types)</li>
        <li><strong>IDE autocomplete</strong> and refactoring support</li>
        <li>📖 <strong>Self-documenting</strong> code</li>
        <li> No runtime type checking needed</li>
        <li>🚀 Perfect for read-only data (reports, dashboards, APIs)</li>
    </ul>

    <h4>Performance Comparison (10,000 rows)</h4>
    <table class="table">
        <tr>
            <td>Array hydration</td>
            <td>~500ms, 50MB memory</td>
        </tr>
        <tr>
            <td>Entity hydration</td>
            <td>~800ms, 80MB memory</td>
        </tr>
        <tr>
            <td><strong>DTO hydration</strong></td>
            <td><strong>~150ms, 15MB memory </strong></td>
        </tr>
    </table>

    <div class="alert alert-info">
        ℹ️ <strong>Note:</strong> DTOs are read-only objects. Use entities only when you need to modify data.
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#new-operator-syntax" target="_blank" class="doc-link">
            📖 Doctrine NEW Operator Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Use DTO hydration (NEW syntax) for %d %s with aggregations - 3-5x faster!',
        $queryCount,
        $queryCount > 1 ? 'queries' : 'query',
    ),
];
