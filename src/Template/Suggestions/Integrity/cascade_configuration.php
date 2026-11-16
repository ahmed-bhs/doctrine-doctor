<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $entityClass
 * @var mixed $fieldName
 * @var string $issueType
 * @var mixed $targetEntity
 * @var bool $isComposition
 * @var array<string, mixed> $context
 */
$entityClass = (string) ($context['entity_class'] ?? 'ParentEntity');
$fieldName = (string) ($context['field_name'] ?? 'children');
$issueType = (string) ($context['issue_type'] ?? 'missing_cascade');
$targetEntity = (string) ($context['target_entity'] ?? 'ChildEntity');
$isComposition = (bool) ($context['is_composition'] ?? false);
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$lastBackslash = strrchr($entityClass, '\\');
$shortClass = false !== $lastBackslash ? substr($lastBackslash, 1) : $entityClass;
$lastBackslashTarget = strrchr($targetEntity, '\\');
$shortTarget = false !== $lastBackslashTarget ? substr($lastBackslashTarget, 1) : $targetEntity;
ob_start();
?>
<?php echo suggestionHeader($isComposition ? 'Cascade persist and remove to owned children' : 'Cascade only persist to independent entities'); ?>
<div class="suggestion-content">
<?php if ($isComposition) { ?>
<p><code><?php echo $e($shortClass); ?>::$<?php echo $e($fieldName); ?></code> (<?php echo $e($issueType); ?>) looks like a composition: each <?php echo $e($shortTarget); ?> belongs to one <?php echo $e($shortClass); ?>. Let the parent persist and delete its children.</p>
<?php } else { ?>
<p><code><?php echo $e($shortClass); ?>::$<?php echo $e($fieldName); ?></code> (<?php echo $e($issueType); ?>) links independent entities. <code>cascade: ['remove']</code> here would delete <?php echo $e($shortTarget); ?> rows that other records may still use.</p>
<?php } ?>

<div class="query-item"><pre><code class="language-php">#[ORM\OneToMany(
    targetEntity: <?php echo $e($shortTarget); ?>::class,
    mappedBy: 'parent', // keep your current mappedBy
    cascade: [<?php echo $isComposition ? "'persist', 'remove'" : "'persist'"; ?>],<?php echo $isComposition ? "\n    orphanRemoval: true," : ''; ?>

)]
private Collection $<?php echo $e($fieldName); ?>;</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/working-with-associations.html#transitive-persistence-cascade-operations', 'Doctrine ORM Cascade Operations'); ?>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Cascade configuration for %s::$%s', $shortClass, $fieldName)];
