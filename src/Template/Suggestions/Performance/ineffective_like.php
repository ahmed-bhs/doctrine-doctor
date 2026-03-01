<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$pattern = $context['pattern'] ?? '%search%';
$like_type = $context['like_type'] ?? 'contains search';
$original_query = $context['original_query'] ?? $context['query'] ?? 'SELECT ... WHERE column LIKE \'%value%\'';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="suggestion-header"><h4>Ineffective LIKE pattern detected</h4></div>
<div class="suggestion-content">
<div class="alert alert-warning">Using <code>LIKE '<?php echo $e($pattern); ?>'</code> with a leading wildcard forces a full table scan.</div>

<h4>Your query</h4>
<div class="query-item"><pre><code class="language-sql"><?php echo $e($original_query); ?></code></pre></div>

<p>The database <strong>cannot use indexes</strong> when the wildcard is at the start.</p>

<?php if ('contains search' === $like_type): ?>
<p><strong>Contains search</strong> (<code>LIKE '%value%'</code>) is the worst case for performance. Consider full-text search instead.</p>
<?php elseif ('ends-with search' === $like_type): ?>
<p><strong>Ends-with search</strong> (<code>LIKE '%value'</code>) cannot use indexes. Consider reversing the column or a different approach.</p>
<?php endif; ?>

<h4>Solution: Use full-text search</h4>
<p>For text search, use MySQL's <code>MATCH...AGAINST</code> or an external search engine like Elasticsearch.</p>

<p><strong>Golden rule:</strong> Never use leading wildcards (<code>%...</code>) in user-facing features.</p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Ineffective LIKE pattern (%s) - consider full-text search', $like_type),
];
