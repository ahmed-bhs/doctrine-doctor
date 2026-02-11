<?php

declare(strict_types=1);

/**
 * Template for CascadeRemoveOnIndependentEntityAnalyzer - ManyToMany
 */
[
    'entity_class' => $entityClass,
    'field_name' => $fieldName,
    'target_entity' => $targetEntity,
] = $context;

$e = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

$lastBackslashClass = strrchr($entityClass, '\\');
$shortClass = false !== $lastBackslashClass ? substr($lastBackslashClass, 1) : $entityClass;

$lastBackslashTarget = strrchr((string) $targetEntity, '\\');
$shortTarget = false !== $lastBackslashTarget ? substr($lastBackslashTarget, 1) : $targetEntity;

ob_start();
?>

<div class="suggestion-header">
    <h3>cascade="remove" on ManyToMany</h3>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <code>$<?= $e($fieldName) ?></code> in <code><?= $e($shortClass) ?></code> has <code>cascade="remove"</code> on a ManyToMany relation.
        Deleting a <?= $e($shortClass) ?> will delete all related <?= $e($shortTarget) ?>s, even if other entities reference them.
    </div>

    <h4>Solution</h4>
    <div class="code-comparison">
        <pre><code class="language-php">// Before
class <?= $e($shortClass) ?> {
    #[ORM\ManyToMany(
        targetEntity: <?= $e($shortTarget) ?>::class,
        cascade: ['remove']
    )]
    private Collection $<?= $e($fieldName) ?>;
}

// After
class <?= $e($shortClass) ?> {
    #[ORM\ManyToMany(targetEntity: <?= $e($shortTarget) ?>::class)]
    private Collection $<?= $e($fieldName) ?>;
}</code></pre>
    </div>

    <p>In ManyToMany, entities are typically shared. Only use cascade="remove" if they're truly dependent.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Remove cascade="remove" from ManyToMany relation',
];
