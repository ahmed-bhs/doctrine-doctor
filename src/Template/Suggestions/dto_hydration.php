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

    <p>When aggregating data, use DTOs for 3-5x faster performance, 70% less memory, and type safety.</p>

    <h4>Current approach (slow, no type safety)</h4>
    <div class="query-item">
        <pre><code class="language-php">$results = $em->createQuery("
    SELECT u.name, u.email, SUM(o.total) as revenue
    FROM User u JOIN u.orders o GROUP BY u.id
")->getResult();

foreach ($results as $row) {
    echo $row['name'] . ': ' . $row['revenue']; // Array access, no autocomplete
}</code></pre>
    </div>

    <h4>Solution: Use DTO with NEW syntax</h4>
    <div class="query-item">
        <pre><code class="language-php">// Create a DTO
class UserRevenue {
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly float $revenue
    ) {}
}

// Use NEW syntax
$results = $em->createQuery("
    SELECT NEW App\\DTO\\UserRevenue(u.name, u.email, SUM(o.total))
    FROM User u JOIN u.orders o GROUP BY u.id
")->getResult();

foreach ($results as $userRevenue) {
    echo $userRevenue->name . ': ' . $userRevenue->revenue; // Type-safe, autocomplete
}</code></pre>
    </div>

    <p>For 10,000 rows: arrays take ~500ms and 50MB, DTOs take ~150ms and 15MB.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#new-operator-syntax" target="_blank" class="doc-link">
            📖 Doctrine NEW operator docs
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
