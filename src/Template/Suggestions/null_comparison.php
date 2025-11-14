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
    <h2>Incorrect NULL Comparison Detected</h2>

    <div class="original-query">
        <p><strong>Your query:</strong></p>
        <pre><code class="language-sql"><?= htmlspecialchars($incorrect) ?></code></pre>
    </div>

    <div class="problem-description">
        <p><code>NULL</code> represents the absence of a value. <code>NULL = NULL</code> returns <strong>UNKNOWN</strong> (not TRUE), so the condition always fails.</p>
    </div>

    <div class="correct-syntax">
        <h3>Solution</h3>
        <pre><code class="language-sql"><?= htmlspecialchars($correct) ?></code></pre>
    </div>

    <div class="examples">
        <div class="code-comparison">
            <div class="wrong-example">
                <p><em>WRONG</em></p>
                <pre><code class="language-sql">WHERE bonus = NULL
WHERE bonus != NULL</code></pre>
            </div>
            <div class="correct-example">
                <p><em>CORRECT</em></p>
                <pre><code class="language-sql">WHERE bonus IS NULL
WHERE bonus IS NOT NULL</code></pre>
            </div>
        </div>
    </div>

    <div class="doctrine-example">
        <h3>DQL (Doctrine)</h3>
        <div class="code-comparison">
            <div class="incorrect-example">
                <p><em>Incorrect</em></p>
                <pre><code class="language-php">$qb->where('e.bonus = NULL');</code></pre>
            </div>
            <div class="correct-example">
                <p><em>Correct</em></p>
                <pre><code class="language-php">$qb->where('e.bonus IS NULL');</code></pre>
            </div>
        </div>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
