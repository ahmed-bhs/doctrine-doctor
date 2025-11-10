<?php

declare(strict_types=1);

/**
 * Template for CascadeRemoveOnIndependentEntityAnalyzer - ManyToOne
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
    <h3>🚨 CRITICAL: cascade="remove" on ManyToOne</h3>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>DATA LOSS RISK!</strong><br>
        <code>$<?= $e($fieldName) ?></code> in <code><?= $e($shortClass) ?></code> has <code>cascade="remove"</code> on ManyToOne to <code><?= $e($shortTarget) ?></code>.
        <br><br>
        <strong>Deleting one <?= $e($shortClass) ?> will DELETE the shared <?= $e($shortTarget) ?>, affecting ALL other entities referencing it!</strong>
    </div>

    <h4>Solution: Remove cascade="remove"</h4>
    <div class="code-comparison">
        <pre><code class="language-php">// Before: DANGEROUS
class <?= $e($shortClass) ?> {
    #[ORM\ManyToOne(
        targetEntity: <?= $e($shortTarget) ?>::class,
        cascade: ['remove']  // DANGER!
    )]
    private ?<?= $e($shortTarget) ?> $<?= $e($fieldName) ?>;
}

// After: SAFE
class <?= $e($shortClass) ?> {
    #[ORM\ManyToOne(targetEntity: <?= $e($shortTarget) ?>::class)]
    private ?<?= $e($shortTarget) ?> $<?= $e($fieldName) ?>;
}</code></pre>
    </div>

    <p><strong>Rule:</strong> NEVER use <code>cascade="remove"</code> on ManyToOne - the "One" is shared and independent.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Remove cascade="remove" from ManyToOne relation',
];
