<?php

declare(strict_types=1);

/**
 * Template for incorrect NULL comparison suggestion.
 * @var string $incorrect Incorrect NULL comparison
 * @var string $correct   Correct NULL comparison
 * @var string $field     Field name
 * @var string $operator  Operator used (=, !=, <>)
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$incorrect = $context['incorrect'] ?? 'field = NULL';
$correct = $context['correct'] ?? 'field IS NULL';
$field = $context['field'] ?? 'field_name';
$operator = $context['operator'] ?? '=';

ob_start();
?>

<div class="null-comparison-issue">
    <h2>📢 Incorrect NULL Comparison Detected</h2>

    <div class="original-query">
        <p><strong>Your query:</strong></p>
        <pre><code class="language-sql"><?= htmlspecialchars($incorrect) ?></code></pre>
    </div>

    <div class="problem-description">
        <p><strong>Problem:</strong></p>
        <p>In SQL, <code>NULL</code> is not a value — it represents the <strong>absence of a value</strong>. You cannot compare to <code>NULL</code> using <code><?= htmlspecialchars($operator) ?></code>.</p>
    </div>

    <div class="explanation">
        <h3>Why This Is Wrong</h3>
        <pre><code class="language-sql">-- 📢 This NEVER returns any rows (even if bonus is NULL!)
WHERE <?= htmlspecialchars($field) ?> = NULL

-- 📢 This ALSO never returns any rows!
WHERE <?= htmlspecialchars($field) ?> != NULL</code></pre>
        <p><strong>Why?</strong> In SQL, <code>NULL = NULL</code> is <strong>UNKNOWN</strong> (not TRUE), so the condition fails.</p>
    </div>

    <div class="correct-syntax">
        <h3>Correct Syntax</h3>
        <p>Use <code>IS NULL</code> or <code>IS NOT NULL</code>:</p>
        <pre><code class="language-sql"><?= htmlspecialchars($correct) ?></code></pre>
    </div>

    <div class="examples">
        <h3>📖 Examples</h3>
        <div class="code-comparison">
            <div class="wrong-example">
                <p><em>📢 WRONG: Find employees without bonus</em></p>
                <pre><code class="language-sql">SELECT * FROM employees WHERE bonus = NULL;</code></pre>
            </div>
            <div class="correct-example">
                <p><em>CORRECT</em></p>
                <pre><code class="language-sql">SELECT * FROM employees WHERE bonus IS NULL;</code></pre>
            </div>
            <div class="wrong-example">
                <p><em>📢 WRONG: Find employees with bonus</em></p>
                <pre><code class="language-sql">SELECT * FROM employees WHERE bonus != NULL;</code></pre>
            </div>
            <div class="correct-example">
                <p><em>CORRECT</em></p>
                <pre><code class="language-sql">SELECT * FROM employees WHERE bonus IS NOT NULL;</code></pre>
            </div>
        </div>
    </div>

    <div class="doctrine-example">
        <h3>🔍 DQL Example (Doctrine)</h3>
        <div class="code-comparison">
            <div class="incorrect-example">
                <p><em>Incorrect</em></p>
                <pre><code class="language-php">$qb->where('e.bonus = NULL');</code></pre>
            </div>
            <div class="correct-example">
                <p><em>Correct</em></p>
                <pre><code class="language-php">$qb->where('e.bonus IS NULL');

// Or use the dedicated method
$qb->where($qb->expr()->isNull('e.bonus'));</code></pre>
            </div>
        </div>
    </div>

    <div class="quick-reference">
        <h3>Quick Reference</h3>
        <table class="reference-table">
            <thead>
                <tr>
                    <th>📢 Don't Use</th>
                    <th>Use Instead</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>field = NULL</code></td>
                    <td><code>field IS NULL</code></td>
                </tr>
                <tr>
                    <td><code>field != NULL</code></td>
                    <td><code>field IS NOT NULL</code></td>
                </tr>
                <tr>
                    <td><code>field <> NULL</code></td>
                    <td><code>field IS NOT NULL</code></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="sql-rules">
        <h3>📖 SQL NULL Rules</h3>
        <ol>
            <li><code>NULL = NULL</code> → UNKNOWN (not TRUE!)</li>
            <li><code>NULL != NULL</code> → UNKNOWN</li>
            <li><code>NULL IS NULL</code> → TRUE</li>
            <li><code>NULL IS NOT NULL</code> → FALSE</li>
        </ol>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
