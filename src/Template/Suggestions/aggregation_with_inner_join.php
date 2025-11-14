<?php

declare(strict_types=1);

/**
 * Template for aggregation with INNER JOIN suggestion.
 * @var string $aggregation     Aggregation function (COUNT, SUM, AVG)
 * @var string $original_query  Original SQL query
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$aggregation = $context['aggregation'] ?? 'COUNT';
$originalQuery = $context['original_query'] ?? $context['query'] ?? 'SELECT ...';

ob_start();
?>

<div class="sql-issue">
    <h2> <?= htmlspecialchars($aggregation) ?>() with INNER JOIN - Wrong Results!</h2>

    <div class="issue-description">
        <p><strong>Critical Issue:</strong> Using <code><?= htmlspecialchars($aggregation) ?>()</code> with <code>INNER JOIN</code> on one-to-many relationships causes <strong>row duplication</strong> → incorrect aggregates.</p>
    </div>

    <div class="problem-section">
        <h3>Problem Example</h3>

        <div class="code-example wrong">
            <p><em>WRONG: Returns ITEMS count, not ORDERS count!</em></p>
            <pre><code class="language-sql">SELECT COUNT(o.id)
FROM orders o
INNER JOIN order_items oi ON oi.order_id = o.id;</code></pre>
            <p><em>If Order #1 has 3 items, it gets counted 3 times!<br>
            Result: 10 (wrong) instead of 3 orders</em></p>
        </div>
    </div>

    <div class="solution-section">
        <h3>Solution: Use COUNT(DISTINCT)</h3>

        <div class="code-example correct">
            <p><em>CORRECT: Count unique orders</em></p>
            <pre><code class="language-sql">SELECT COUNT(DISTINCT o.id)
FROM orders o
INNER JOIN order_items oi ON oi.order_id = o.id;</code></pre>
        </div>

        <div class="doctrine-example">
            <h4>Doctrine QueryBuilder:</h4>
            <pre><code class="language-php">$qb->select('COUNT(DISTINCT o.id)')
   ->from(Order::class, 'o')
   ->innerJoin('o.items', 'oi');</code></pre>
        </div>

        <div class="common-fixes">
            <p><strong>Common fix:</strong> Use <code>COUNT(DISTINCT)</code>, remove unnecessary JOINs, or use subqueries.</p>
        </div>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
