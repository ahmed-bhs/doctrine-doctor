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
    <h4>DTO hydration for aggregations</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Found <?php echo $queryCount; ?> <?php echo $queryCount > 1 ? 'queries' : 'query'; ?></strong> with aggregations (<?php echo implode(', ', array_map($e, $aggregations)); ?>). Using DTO hydration here would be 3-5x faster and type-safe.
    </div>

    <p>When you're aggregating data, you're dealing with read-only results. DTOs are perfect for this — they're faster, use less memory, and give you type safety.</p>

    <h4>Current approach</h4>
    <div class="query-item">
        <pre><code class="language-php">$query = $em->createQuery("
    SELECT u.name, u.email, SUM(o.total) as revenue, COUNT(o.id) as orderCount
    FROM User u
    JOIN u.orders o
    GROUP BY u.id
");
$results = $query->getResult();

foreach ($results as $row) {
    // Array access, no autocomplete
    echo $row['name'] . ': ' . $row['revenue'];
    $revenue = (float) $row['revenue']; // Manual casting
}</code></pre>
    </div>

    <h4>Using a DTO instead</h4>

    <h5>Create a DTO</h5>
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

    <h5>Use the NEW syntax</h5>
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

foreach ($results as $userRevenue) {
    // Type-safe, with autocomplete
    echo $userRevenue->name . ': ' . $userRevenue->revenue;
    $revenue = $userRevenue->revenue; // Already a float
}</code></pre>
    </div>

    <p>DTOs are 3-5x faster than array hydration and use about 70% less memory. They're type-safe, give you autocomplete in your IDE, and work great for read-only data like reports and dashboards.</p>

    <p>For 10,000 rows: array hydration takes ~500ms and 50MB, while DTOs take ~150ms and 15MB.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#new-operator-syntax" target="_blank" class="doc-link">
            Doctrine NEW operator docs
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'DTO hydration could speed up %d aggregation %s',
        $queryCount,
        $queryCount > 1 ? 'queries' : 'query',
    ),
];
