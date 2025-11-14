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
    <h2>Incorrect NULL Comparison</h2>

    <div class="alert alert-danger">
        <code>NULL = NULL</code> returns UNKNOWN, not TRUE. Use <code>IS NULL</code> instead.
    </div>

    <h4>Your query</h4>
    <div class="query-item">
        <pre><code class="language-sql"><?= htmlspecialchars($incorrect) ?></code></pre>
    </div>

    <h4>Solution</h4>
    <div class="query-item">
        <pre><code class="language-sql"><?= htmlspecialchars($correct) ?>

-- DQL example
$qb->where('e.<?= htmlspecialchars($field) ?> IS NULL');</code></pre>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
