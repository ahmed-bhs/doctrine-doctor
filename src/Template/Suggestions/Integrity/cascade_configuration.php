<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entityClass
 * @var mixed $fieldName
 * @var mixed $issueType
 * @var mixed $targetEntity
 * @var mixed $isComposition
 * @var mixed $context
 */
['entity_class' => $entityClass, 'field_name' => $fieldName, 'issue_type' => $issueType, 'target_entity' => $targetEntity, 'is_composition' => $isComposition] = $context;
$e                                                                                                                                                             = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$lastBackslash                                                                                                                                                 = strrchr($entityClass, '\\');
$shortClass                                                                                                                                                    = false !== $lastBackslash ? substr($lastBackslash, 1) : $entityClass;
$lastBackslashTarget                                                                                                                                           = strrchr((string) $targetEntity, '\\');
$shortTarget                                                                                                                                                   = false !== $lastBackslashTarget ? substr($lastBackslashTarget, 1) : (string) $targetEntity;
ob_start();
?>
<div class="suggestion-header"><h4>Cascade configuration</h4></div>
<div class="suggestion-content">
<div class="alert alert-warning"><strong><?php echo $e($shortClass); ?>::$<?php echo $e($fieldName); ?></strong> - <?php echo $e($issueType); ?></div>

<?php if ($isComposition) { ?>
<p>This looks like a composition (parent owns children). Use <code>['persist', 'remove']</code> with <code>orphanRemoval: true</code>.</p>
<?php } else { ?>
<p>This looks like an association (independent entities). Avoid <code>cascade: ['remove']</code> to prevent accidental deletions.</p>
<?php } ?>

<div class="query-item"><pre><code class="language-php">class <?php echo $e($shortClass); ?> {
    #[ORM\OneToMany(
        targetEntity: <?php echo $e($shortTarget); ?>::class,
        cascade: [<?php echo $isComposition ? "'persist', 'remove'" : "'persist'"; ?>]<?php echo $isComposition ? ",\n        orphanRemoval: true" : ''; ?>

    )]
    private Collection $<?php echo $e($fieldName); ?>;
}</code></pre></div>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Cascade configuration for %s::$%s', $shortClass, $fieldName)];
