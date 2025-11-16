<?php

declare(strict_types=1);

/**
 * Template for Missing orphanRemoval on Composition suggestions.
 * Context variables:
 * @var string $entity_class - Short entity class name
 * @var string $target_class - Short target entity class name
 * @var string $field_name - Field name
 * @var string $mapped_by - MappedBy field name
 * @var string $current_cascade - Current cascade setting
 * @var bool   $is_not_null_fk - Is FK NOT NULL (critical case)
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
$entityClass = $context['entity_class'] ?? 'Entity';
$targetClass = $context['target_class'] ?? 'ClassName';
$fieldName = $context['field_name'] ?? 'field';
$mappedBy = $context['mapped_by'] ?? null;
$currentCascade = $context['current_cascade'] ?? null;
$isNotNullFK = $context['is_not_null_fk'] ?? null;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Missing orphanRemoval</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        Removing from collection leaves orphan records. Add <code>orphanRemoval=true</code> for composition relationships.
    </div>

    <h4>Solution</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($entityClass); ?> {
    #[ORM\OneToMany(
        targetEntity: <?php echo $e($targetClass); ?>::class,
        mappedBy: '<?php echo $e($mappedBy); ?>',
        cascade: ['persist', 'remove'],
        orphanRemoval: true  // Add this
    )]
    private Collection $<?php echo $e($fieldName); ?>;
}

// Removing from collection now deletes the record
$<?php echo lcfirst($e($entityClass)); ?>->remove<?php echo ucfirst(rtrim($e($fieldName), 's')); ?>($item);
$em->flush();</code></pre>
    </div>

    <p>Use when parent owns children (Order → OrderItems). Don't use for shared entities (Order → Products).</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/working-with-associations.html#orphan-removal" target="_blank" class="doc-link">
            📖 Doctrine Orphan Removal
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Add orphanRemoval=true to %s::$%s for proper composition handling',
        $entityClass,
        $fieldName,
    ),
];
