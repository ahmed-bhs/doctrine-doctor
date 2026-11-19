<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var array<string, mixed> $context
 */
$entity_class = (string) ($context['entity_class'] ?? '');
$field_name = (string) ($context['field_name'] ?? '');
$target_entity = (string) ($context['target_entity'] ?? '');
$target_short_name = (string) ($context['target_short_name'] ?? $target_entity);
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$alias = '' === $field_name ? 'r' : substr($field_name, 0, 1);
ob_start();
?>
<?php echo suggestionHeader('Remove fetch: EAGER from the mapping'); ?>
<div class="suggestion-content">
<p><?php echo t("<code>%s::$%s</code> is mapped with <code>fetch: 'EAGER'</code>, so <code>%s</code> is loaded by every query on the entity, needed or not.", $e($entity_class), $e($field_name), $e($target_short_name)); ?>
<?php echo t('Fetch-join it only in the queries that read it.'); ?></p>

<div class="query-item"><pre><code class="language-php">// Before
#[ORM\ManyToOne(targetEntity: <?php echo $e($target_short_name); ?>::class, fetch: 'EAGER')]
private <?php echo $e($target_short_name); ?> $<?php echo $e($field_name); ?>;

// After
#[ORM\ManyToOne(targetEntity: <?php echo $e($target_short_name); ?>::class)]
private <?php echo $e($target_short_name); ?> $<?php echo $e($field_name); ?>;

// In the query that needs it
$qb-&gt;leftJoin('e.<?php echo $e($field_name); ?>', '<?php echo $e($alias); ?>')-&gt;addSelect('<?php echo $e($alias); ?>');</code></pre></div>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => t('Remove fetch: "EAGER" from the mapping and fetch-join the relation where it is needed')];
