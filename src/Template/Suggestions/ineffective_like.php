<?php

declare(strict_types=1);

/**
 * Template for ineffective LIKE pattern suggestion.
 * @var string $pattern         LIKE pattern detected (e.g., '%search%')
 * @var string $like_type        Type of LIKE (contains, ends-with, etc.)
 * @var string $original_query   Original SQL query
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$pattern = $context['pattern'] ?? '%search%';
$like_type = $context['like_type'] ?? 'contains search';
$original_query = $context['original_query'] ?? $context['query'] ?? 'SELECT ... WHERE column LIKE \'%value%\'';

ob_start();
?>

<div class="ineffective-like-pattern">
    <h2> Ineffective LIKE Pattern Detected</h2>

    <div class="original-query">
        <p><strong>Your query:</strong></p>
        <pre><code class="language-sql"><?= htmlspecialchars($original_query) ?></code></pre>
    </div>

    <div class="problem-description">
        <p><strong>Problem:</strong></p>
        <p>Using <code>LIKE '<?= htmlspecialchars($pattern) ?>'</code> with a <strong>leading wildcard</strong> (<code>%</code> at the beginning) forces a <strong>full table scan</strong>. The database <strong>cannot use indexes</strong> when the wildcard is at the start, making this extremely slow on large tables.</p>
    </div>

    <div class="pattern-type">
        <h3>Pattern Type: <?= htmlspecialchars($like_type) ?></h3>

        <?php if ('contains search' === $like_type): ?>
        <div class="contains-search">
            <h4>Contains Search (<code>LIKE '%value%'</code>)</h4>
            <p>This is the <strong>worst case</strong> for performance. Consider full-text search instead.</p>
        </div>

        <?php elseif ('ends-with search' === $like_type): ?>
        <div class="ends-with-search">
            <h4>Ends-With Search (<code>LIKE '%value'</code>)</h4>
            <p>This pattern cannot use indexes. Consider reversing the column or using a different approach.</p>
        </div>
        <?php endif; ?>
    </div>

    <div class="solution">
        <h3>Solution: Use Full-Text Search</h3>
        <p>For text search, use MySQL's <code>MATCH...AGAINST</code> or an external search engine like Elasticsearch.</p>
    </div>

    <div class="summary">
        <h3>Summary</h3>
        <p><strong>Golden Rule:</strong> Never use leading wildcards (<code>%...</code>) in user-facing features!</p>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
