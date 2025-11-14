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

$lastBackslashTarget = strrchr($targetEntity, '\\');
$shortTarget = false !== $lastBackslashTarget ? substr($lastBackslashTarget, 1) : $targetEntity;

ob_start();
?>

<div class="suggestion-header">
    <h3>cascade="remove" on ManyToMany</h3>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>Data Loss Risk</strong><br>
        <code>$<?= $e($fieldName) ?></code> in <code><?= $e($shortClass) ?></code> has <code>cascade="remove"</code> on ManyToMany to <code><?= $e($shortTarget) ?></code>.
        <br><br>
        <strong>Deleting one <?= $e($shortClass) ?> will DELETE ALL related <?= $e($shortTarget) ?>s, even those shared with other entities!</strong>
    </div>

    <h4>Solution: Remove cascade="remove"</h4>
    <div class="code-comparison">
        <pre><code class="language-php">// Before: Dangerous
class <?= $e($shortClass) ?> {
    #[ORM\ManyToMany(
        targetEntity: <?= $e($shortTarget) ?>::class,
        cascade: ['remove']
    )]
    private Collection $<?= $e($fieldName) ?>;
}

// After: Safe
class <?= $e($shortClass) ?> {
    #[ORM\ManyToMany(targetEntity: <?= $e($shortTarget) ?>::class)]
    private Collection $<?= $e($fieldName) ?>;
}</code></pre>
    </div>

    <p><strong>Rule:</strong> cascade="remove" on ManyToMany deletes shared entities. Use it ONLY if entities are truly dependent.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Remove cascade="remove" from ManyToMany relation',
];
