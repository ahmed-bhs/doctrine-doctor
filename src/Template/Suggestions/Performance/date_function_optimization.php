<?php

declare(strict_types=1);

/**
 * Template for date function optimization suggestion.
 * @var string $function         Function name (YEAR, MONTH, DATE, etc.)
 * @var string $field            Field name
 * @var string $original_clause  Original WHERE clause
 * @var string $optimized_clause Optimized WHERE clause
 * @var string $operator         Operator used
 * @var string $value            Value compared
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$function = $context['function'] ?? $context['function_name'] ?? 'YEAR';
$field = $context['field'] ?? $context['field_name'] ?? 'created_at';
$originalClause = $context['original_clause'] ?? $context['query'] ?? "{$function}({$field}) = value";
$optimizedClause = $context['optimized_clause'] ?? "{$field} BETWEEN start AND end";

ob_start();
?>

<div class="date-optimization">
    <h2>Date Function Prevents Index Usage</h2>

    <div class="original-query">
        <p><strong>Your query:</strong></p>
        <pre><code class="language-sql">WHERE <?= htmlspecialchars($originalClause) ?></code></pre>
    </div>

    <div class="problem-description">
        <p>Using <code><?= htmlspecialchars($function) ?>()</code> on <code><?= htmlspecialchars($field) ?></code> prevents index usage, forcing a full table scan. On 1 million rows: ~5 seconds vs ~50ms with index (100x slower).</p>
    </div>

    <div class="optimized-query">
        <h3>Solution: Use Range Comparison</h3>
        <pre><code class="language-sql">WHERE <?= htmlspecialchars($optimizedClause) ?></code></pre>
    </div>

    <div class="common-examples">
        <h3>Examples</h3>

        <div class="code-comparison">
            <div class="slow-example">
                <p><em>Slow (full scan)</em></p>
                <pre><code class="language-sql">WHERE YEAR(created_at) = 2023
WHERE DATE(created_at) = '2023-01-15'</code></pre>
            </div>
            <div class="fast-example">
                <p><em>Fast (uses index)</em></p>
                <pre><code class="language-sql">WHERE created_at >= '2023-01-01' AND created_at < '2024-01-01'
WHERE created_at >= '2023-01-15' AND created_at < '2023-01-16'</code></pre>
            </div>
        </div>
    </div>

    <div class="doctrine-example">
        <h3>DQL Example (Doctrine)</h3>
        <div class="code-comparison">
            <div class="slow-example">
                <p><em>Slow</em></p>
                <pre><code class="language-php">$qb->where('YEAR(o.createdAt) = :year')
   ->setParameter('year', 2023);</code></pre>
            </div>
            <div class="fast-example">
                <p><em>Fast</em></p>
                <pre><code class="language-php">$qb->where('o.createdAt BETWEEN :start AND :end')
   ->setParameter('start', new \DateTime('2023-01-01'))
   ->setParameter('end', new \DateTime('2023-12-31'));</code></pre>
            </div>
        </div>
    </div>

    <div class="general-rule">
        <h3>General Rule</h3>
        <p><strong>Never use functions on indexed columns in WHERE clause.</strong></p>
        <p>Common culprits: <code>YEAR()</code>, <code>MONTH()</code>, <code>DATE()</code>, <code>UPPER()</code>, <code>LOWER()</code>, <code>SUBSTRING()</code>, <code>ROUND()</code>. Always compare the column directly.</p>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
