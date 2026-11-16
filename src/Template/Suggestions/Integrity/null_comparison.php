<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$incorrect = $context['incorrect'] ?? 'field = NULL';
$correct = $context['correct'] ?? 'field IS NULL';
$field = $context['field'] ?? 'field_name';
$operator = $context['operator'] ?? '=';
// The SQL column (s0_.shipped_at) becomes the DQL property (e.shippedAt).
$property = lcfirst(str_replace('_', '', ucwords((string) preg_replace('/^\w+\./', '', (string) $field), '_')));

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Compare with IS NULL'); ?>
<div class="suggestion-content">
<p>In SQL, <code>= NULL</code> and <code>!= NULL</code> evaluate to UNKNOWN, so the condition never matches a row. Use <code>IS NULL</code> or <code>IS NOT NULL</code>.</p>

<div class="query-item"><pre><code class="language-sql">-- Before
<?php echo $e((string) $incorrect); ?>

-- After
<?php echo $e((string) $correct); ?></code></pre></div>

<p>In a QueryBuilder: <code>$qb-&gt;andWhere($qb-&gt;expr()-&gt;isNull('e.<?php echo $e($property); ?>'))</code>.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/dql-doctrine-query-language.html', 'Doctrine Query Language (DQL)'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use IS NULL instead of = NULL',
];
