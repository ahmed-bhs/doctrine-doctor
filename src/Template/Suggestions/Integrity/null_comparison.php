<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$incorrect = $context['incorrect'] ?? 'field = NULL';
$correct = $context['correct'] ?? 'field IS NULL';
$field = $context['field'] ?? 'field_name';
$operator = $context['operator'] ?? '=';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="suggestion-header"><h4>Incorrect NULL comparison</h4></div>
<div class="suggestion-content">
<div class="alert alert-danger"><code>NULL = NULL</code> returns UNKNOWN, not TRUE. Use <code>IS NULL</code> instead.</div>

<h4>Your query</h4>
<div class="query-item"><pre><code class="language-sql"><?php echo $e($incorrect); ?></code></pre></div>

<h4>Solution</h4>
<div class="query-item"><pre><code class="language-sql"><?php echo $e((string) $correct); ?>

-- DQL example
$qb->where('e.<?php echo $e((string) $field); ?> IS NULL');</code></pre></div>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use IS NULL instead of = NULL for correct NULL comparison',
];
