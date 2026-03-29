<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entity_class
 * @var mixed $field_name
 * @var mixed $target_entity
 * @var mixed $target_short_name
 * @var mixed $context
 */
$entity_class = (string) ($context['entity_class'] ?? '');
$field_name = (string) ($context['field_name'] ?? '');
$target_entity = (string) ($context['target_entity'] ?? '');
$target_short_name = (string) ($context['target_short_name'] ?? $target_entity);
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
ob_start();
?>
<div class="suggestion-header"><h4>Suggestion</h4></div>
<div class="suggestion-content">
<p>Remove <code>fetch: 'EAGER'</code> from the mapping and use dynamic eager loading in your queries.</p>

<h4>BEFORE — Global eager loading (always loads, even when not needed)</h4>
<div class="query-item"><pre><code class="language-php"><?php echo $e($entity_class); ?> {
    #[ORM\ManyToOne(targetEntity: <?php echo $e($target_short_name); ?>::class, fetch: 'EAGER')]
    private <?php echo $e($target_short_name); ?> $<?php echo $e($field_name); ?>;
}</code></pre></div>

<h4>AFTER — Dynamic eager loading (load only when needed)</h4>
<div class="query-item"><pre><code class="language-php"><?php echo $e($entity_class); ?> {
    #[ORM\ManyToOne(targetEntity: <?php echo $e($target_short_name); ?>::class)]
    private <?php echo $e($target_short_name); ?> $<?php echo $e($field_name); ?>;
}

// In your repository, eager load only when needed:
$qb->select('e', '<?php echo substr($field_name, 0, 1); ?>')
    ->from(<?php echo $e($entity_class); ?>::class, 'e')
    ->leftJoin('e.<?php echo $e($field_name); ?>', '<?php echo substr($field_name, 0, 1); ?>')
    ->addSelect('<?php echo substr($field_name, 0, 1); ?>');</code></pre></div>

<h4>Why?</h4>
<ul>
<li><strong>fetch: 'EAGER' is global</strong> — forces loading for every query, regardless of need</li>
<li><strong>Creates unnecessary JOINs</strong> — wastes queries and memory on unneeded data</li>
<li><strong>Difficult to optimize</strong> — can't disable eager loading per-query</li>
<li><strong>Lazy loading by default</strong> — Doctrine's smart design works best with it</li>
<li><strong>Dynamic loading with QueryBuilder</strong> — load only what your specific query needs</li>
</ul>

<h4>Benefits</h4>
<ul>
<li>Query performance — load only what you need, when you need it</li>
<li>Flexibility — choose per-query whether to eager load or lazy load</li>
<li>Memory efficiency — no unnecessary related objects in memory</li>
<li>N+1 awareness — can still use addSelect() to prevent N+1 when truly needed</li>
</ul>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => 'Remove fetch: "EAGER" from mapping and use dynamic eager loading via QueryBuilder'];
