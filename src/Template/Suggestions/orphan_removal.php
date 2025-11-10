<?php

declare(strict_types=1);

/**
 * Template for orphanRemoval Without cascade="remove" suggestions.
 * Context variables:
 * @var string $entity_class - Short entity class name
 * @var string $target_class - Short target entity class name
 * @var string $field_name - Field name
 * @var string $mapped_by - MappedBy field name
 * @var string $current_cascade - Current cascade setting
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
$entityClass = $context['entity_class'] ?? 'Entity';
$targetClass = $context['target_class'] ?? 'ClassName';
$fieldName = $context['field_name'] ?? 'field';
$mappedBy = $context['mapped_by'] ?? null;
$currentCascade = $context['current_cascade'] ?? null;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
        <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/>
    </svg>
    <h4>Add cascade="remove" for Complete Composition</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Incomplete composition configuration detected</strong><br><br>
        The field <code>$<?php echo $e($fieldName); ?></code> has <code>orphanRemoval=true</code> but no <code>cascade="remove"</code>.
        This creates inconsistent behavior:
        <ul>
            <li> Removing from collection deletes children (orphanRemoval works)</li>
            <li>Deleting the parent does NOT delete children (no cascade remove)</li>
        </ul>
    </div>

    <h4>Current configuration</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($entityClass); ?> {
    #[ORM\OneToMany(
        targetEntity: <?php echo $e($targetClass); ?>::class,
        mappedBy: '<?php echo $e($mappedBy); ?>',
        <?php echo $e($currentCascade); ?>,
        orphanRemoval: true
    )]
    private Collection $<?php echo $e($fieldName); ?>;
}</code></pre>
    </div>

    <h4>Problem: Inconsistent Delete Behavior</h4>
    <div class="query-item">
        <pre><code class="language-php">// Scenario 1: Remove from collection
$<?php echo lcfirst($e($entityClass)); ?>->remove<?php echo ucfirst(rtrim($e($fieldName), 's')); ?>($item);
$em->flush();
//  Item is DELETED (orphanRemoval works)

// Scenario 2: Delete parent
$em->remove($<?php echo lcfirst($e($entityClass)); ?>);
$em->flush();
// Items are NOT deleted (no cascade remove)
// Instead: <?php echo $e($mappedBy); ?>_id is set to NULL or causes FK constraint error
</code></pre>
    </div>

    <h4> Recommended configuration</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($entityClass); ?> {
    #[ORM\OneToMany(
        targetEntity: <?php echo $e($targetClass); ?>::class,
        mappedBy: '<?php echo $e($mappedBy); ?>',
        cascade: ['persist', 'remove'],  // Added cascade remove
        orphanRemoval: true
    )]
    private Collection $<?php echo $e($fieldName); ?>;
}</code></pre>
    </div>

    <h4>Behavior with cascade="remove"</h4>
    <div class="query-item">
        <pre><code class="language-php">// Scenario 1: Remove from collection
$<?php echo lcfirst($e($entityClass)); ?>->remove<?php echo ucfirst(rtrim($e($fieldName), 's')); ?>($item);
$em->flush();
//  Item is DELETED (orphanRemoval)

// Scenario 2: Delete parent
$em->remove($<?php echo lcfirst($e($entityClass)); ?>);
$em->flush();
//  All items are DELETED too (cascade remove)
</code></pre>
    </div>

    <h4>Why this matters</h4>
    <ul>
        <li>With orphanRemoval only: removing item from collection deletes it, but deleting parent leaves orphans</li>
        <li>With cascade remove: both operations delete children consistently</li>
        <li>Complete composition means: parent owns children, deleting parent should delete children</li>
    </ul>

    <h4>Complete Composition Configuration</h4>
    <div class="query-item">
        <pre><code class="language-php">cascade: ['persist', 'remove'], orphanRemoval: true</code></pre>
    </div>

    <p>This gives you:</p>
    <ul>
        <li><code>cascade="persist"</code>: Auto-save new children</li>
        <li><code>cascade="remove"</code>: Delete children when parent is deleted</li>
        <li><code>orphanRemoval=true</code>: Delete children when removed from collection</li>
    </ul>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/working-with-associations.html#orphan-removal" target="_blank" class="doc-link">
            📖 Doctrine Orphan Removal Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Add cascade="remove" to %s::$%s for complete composition',
        $entityClass,
        $fieldName,
    ),
];
