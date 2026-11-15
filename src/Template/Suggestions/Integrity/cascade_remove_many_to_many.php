<?php

declare(strict_types=1);

/**
 * Template for CascadeRemoveOnIndependentEntityAnalyzer - ManyToMany
 */
$entityClass = (string) ($context['entity_class'] ?? 'Post');
$fieldName = (string) ($context['field_name'] ?? 'tags');
$targetEntity = (string) ($context['target_entity'] ?? 'Tag');

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

$lastBackslashClass = strrchr($entityClass, '\\');
$shortClass = false !== $lastBackslashClass ? substr($lastBackslashClass, 1) : $entityClass;

$lastBackslashTarget = strrchr((string) $targetEntity, '\\');
$shortTarget = false !== $lastBackslashTarget ? substr($lastBackslashTarget, 1) : (string) $targetEntity;

ob_start();
?>
<?php echo suggestionHeader('Remove cascade remove from the ManyToMany'); ?>
<div class="suggestion-content">
<p><code><?= $e($shortClass) ?>::$<?= $e($fieldName) ?></code> uses <code>cascade: ['remove']</code> on a ManyToMany association. Deleting a <?= $e($shortClass) ?> also deletes every linked <?= $e($shortTarget) ?>, even those still linked to other rows.</p>

<div class="query-item"><pre><code class="language-php">// Before
#[ORM\ManyToMany(targetEntity: <?= $e($shortTarget) ?>::class, cascade: ['remove'])]
private Collection $<?= $e($fieldName) ?>;

// After
#[ORM\ManyToMany(targetEntity: <?= $e($shortTarget) ?>::class)]
private Collection $<?= $e($fieldName) ?>;</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/working-with-associations.html#transitive-persistence-cascade-operations', 'Doctrine ORM Cascade Operations'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Remove cascade="remove" from ManyToMany relation',
];
