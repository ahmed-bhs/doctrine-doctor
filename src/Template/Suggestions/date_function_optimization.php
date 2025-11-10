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
        <p><strong>Problem:</strong></p>
        <p>Using <code><?= htmlspecialchars($function) ?>()</code> on column <code><?= htmlspecialchars($field) ?></code> prevents the database from using indexes, forcing a <strong>full table scan</strong>. This can be extremely slow on large tables.</p>
    </div>

    <div class="optimized-query">
        <h3>Optimized Query</h3>
        <p>Use range comparison with BETWEEN or >= / < operators:</p>
        <pre><code class="language-sql">WHERE <?= htmlspecialchars($optimizedClause) ?></code></pre>
    </div>

    <div class="performance-impact">
        <h3>📖 Performance Impact</h3>
        <table class="performance-table">
            <thead>
                <tr>
                    <th>Method</th>
                    <th>Index Used</th>
                    <th>Speed</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>📢 <code><?= htmlspecialchars($function) ?>(<?= htmlspecialchars($field) ?>)</code></td>
                    <td>NO</td>
                    <td>🐌 Slow (full scan)</td>
                </tr>
                <tr>
                    <td><code><?= htmlspecialchars($field) ?> BETWEEN ...</code></td>
                    <td>YES</td>
                    <td>⚡ Fast (index seek)</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="common-examples">
        <h3>📖 Common Examples</h3>

        <div class="example">
            <h4>YEAR() Optimization</h4>
            <div class="code-comparison">
                <div class="slow-example">
                    <p><em>📢 Slow: Full table scan</em></p>
                    <pre><code class="language-sql">WHERE YEAR(created_at) = 2023</code></pre>
                </div>
                <div class="fast-example">
                    <p><em>Fast: Uses index</em></p>
                    <pre><code class="language-sql">WHERE created_at BETWEEN '2023-01-01' AND '2023-12-31'
-- Or even better:
WHERE created_at >= '2023-01-01' AND created_at < '2024-01-01'</code></pre>
                </div>
            </div>
        </div>

        <div class="example">
            <h4>MONTH() Optimization</h4>
            <div class="code-comparison">
                <div class="slow-example">
                    <p><em>📢 Slow</em></p>
                    <pre><code class="language-sql">WHERE MONTH(created_at) = 12</code></pre>
                </div>
                <div class="fast-example">
                    <p><em>Fast</em></p>
                    <pre><code class="language-sql">WHERE created_at >= '2023-12-01' AND created_at < '2024-01-01'</code></pre>
                </div>
            </div>
        </div>

        <div class="example">
            <h4>DATE() Optimization</h4>
            <div class="code-comparison">
                <div class="slow-example">
                    <p><em>📢 Slow</em></p>
                    <pre><code class="language-sql">WHERE DATE(created_at) = '2023-01-15'</code></pre>
                </div>
                <div class="fast-example">
                    <p><em>Fast</em></p>
                    <pre><code class="language-sql">WHERE created_at BETWEEN '2023-01-15 00:00:00' AND '2023-01-15 23:59:59'
-- Or:
WHERE created_at >= '2023-01-15' AND created_at < '2023-01-16'</code></pre>
                </div>
            </div>
        </div>
    </div>

    <div class="doctrine-example">
        <h3>🔍 DQL Example (Doctrine)</h3>
        <div class="code-comparison">
            <div class="slow-example">
                <p><em>Slow: Function prevents index usage</em></p>
                <pre><code class="language-php">$qb->where('YEAR(o.createdAt) = :year')
   ->setParameter('year', 2023);</code></pre>
            </div>
            <div class="fast-example">
                <p><em>Fast: Range comparison uses index</em></p>
                <pre><code class="language-php">$qb->where('o.createdAt BETWEEN :start AND :end')
   ->setParameter('start', new \DateTime('2023-01-01'))
   ->setParameter('end', new \DateTime('2023-12-31'));</code></pre>
            </div>
        </div>
    </div>

    <div class="why-matters">
        <h3>💡 Why This Matters</h3>
        <ol>
            <li><strong>Without index:</strong> Database scans EVERY row, applies function, then compares</li>
            <li><strong>With index:</strong> Database uses B-tree to jump directly to matching rows</li>
        </ol>

        <p>On a table with 1 million rows:</p>
        <ul>
            <li>📢 Function: ~5000ms (5 seconds)</li>
            <li>Range: ~50ms (0.05 seconds)</li>
        </ul>

        <p><strong>100x faster!</strong></p>
    </div>

    <div class="general-rule">
        <h3>📖 General Rule</h3>
        <p><strong>Never use functions on indexed columns in WHERE clause.</strong></p>

        <p>Functions that prevent index usage:</p>
        <ul>
            <li><code>YEAR()</code>, <code>MONTH()</code>, <code>DAY()</code>, <code>HOUR()</code></li>
            <li><code>DATE()</code>, <code>TIME()</code></li>
            <li><code>UPPER()</code>, <code>LOWER()</code></li>
            <li><code>SUBSTRING()</code>, <code>LEFT()</code>, <code>RIGHT()</code></li>
            <li>Math functions: <code>ROUND()</code>, <code>FLOOR()</code>, <code>CEIL()</code></li>
        </ul>

        <p>Always rewrite to compare the column directly!</p>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
