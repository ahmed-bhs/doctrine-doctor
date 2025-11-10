<?php

declare(strict_types=1);

['entity_class' => $entityClass, 'field_name' => $fieldName, 'issue_type' => $issueType, 'target_entity' => $targetEntity, 'is_composition' => $isComposition] = $context;
$e                                                                                                                                                             = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$lastBackslash                                                                                                                                                 = strrchr($entityClass, '\\');
$shortClass                                                                                                                                                    = false !== $lastBackslash ? substr($lastBackslash, 1) : $entityClass;
$lastBackslashTarget                                                                                                                                           = strrchr((string) $targetEntity, '\\');
$shortTarget                                                                                                                                                   = false !== $lastBackslashTarget ? substr($lastBackslashTarget, 1) : (string) $targetEntity;
ob_start();
?>
<div class="suggestion-header"><h4>Cascade Configuration Issue</h4></div>
<div class="suggestion-content">
<div class="alert alert-warning"><strong>Cascade issue in <?php echo $e($shortClass); ?>::$<?php echo $e($fieldName); ?></strong><br>
Issue type: <?php echo $e($issueType); ?></div>
<h4>Recommendations</h4>
<?php if ($isComposition) { ?>
<p><strong>Composition relationship detected</strong> (parent owns children)<br>
Recommended cascades: <code>['persist', 'remove']</code> with <code>orphanRemoval: true</code></p>
<?php } else { ?>
<p><strong>Association relationship</strong> (independent entities)<br>
Avoid <code>cascade: ['remove']</code> to prevent accidental deletions!</p>
<?php } ?>
<div class="query-item"><pre><code class="language-php">class <?php echo $e($shortClass); ?> {
    #[ORM\OneToMany(
        targetEntity: <?php echo $e($shortTarget); ?>::class,
        cascade: [<?php echo $isComposition ? "'persist', 'remove'" : "'persist'"; ?>],
        <?php echo $isComposition ? 'orphanRemoval: true' : ''; ?>

    )]
    private Collection $<?php echo $e($fieldName); ?>;
}</code></pre></div>
</div>
<?php
$code = ob_get_clean();

return ['code' => $code, 'description' => sprintf('Fix cascaconfiguration for %s::$%s', $shortClass, $fieldName)];
