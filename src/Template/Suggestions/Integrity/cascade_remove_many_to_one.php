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

$lastBackslashTarget = strrchr((string) $targetEntity, '\\');
$shortTarget = false !== $lastBackslashTarget ? substr($lastBackslashTarget, 1) : $targetEntity;

ob_start();
?>

<div class="suggestion-header">
    <h4>cascade="remove" on ManyToOne</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <code>$<?= $e($fieldName) ?></code> in <code><?= $e($shortClass) ?></code> has <code>cascade="remove"</code> on a ManyToOne relation.
        Deleting a <?= $e($shortClass) ?> will also delete the <?= $e($shortTarget) ?>, which may be referenced by other entities.
    </div>

    <h4>Solution</h4>
    <div class="code-comparison">
        <pre><code class="language-php">// Before
class <?= $e($shortClass) ?> {
    #[ORM\ManyToOne(
        targetEntity: <?= $e($shortTarget) ?>::class,
        cascade: ['remove']
    )]
    private ?<?= $e($shortTarget) ?> $<?= $e($fieldName) ?>;
}

// After
class <?= $e($shortClass) ?> {
    #[ORM\ManyToOne(targetEntity: <?= $e($shortTarget) ?>::class)]
    private ?<?= $e($shortTarget) ?> $<?= $e($fieldName) ?>;
}</code></pre>
    </div>

    <p>On ManyToOne, the target entity is shared. Remove cascade="remove" to avoid deleting shared data.</p>

    <p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/working-with-associations.html#transitive-persistence-cascade-operations" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine ORM Cascade Operations</a></p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Remove cascade="remove" from ManyToOne relation',
];
