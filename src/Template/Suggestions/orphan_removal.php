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
    <h4>Incomplete composition setup</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <code>orphanRemoval=true</code> without <code>cascade="remove"</code> causes inconsistent deletion.
    </div>

    <h4>Solution: Add cascade remove</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($entityClass); ?> {
    #[ORM\OneToMany(
        targetEntity: <?php echo $e($targetClass); ?>::class,
        mappedBy: '<?php echo $e($mappedBy); ?>',
        cascade: ['persist', 'remove'],  // Add this
        orphanRemoval: true
    )]
    private Collection $<?php echo $e($fieldName); ?>;
}</code></pre>
    </div>

    <p>Complete composition requires both <code>cascade: ['persist', 'remove']</code> and <code>orphanRemoval: true</code>.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/working-with-associations.html#orphan-removal" target="_blank" class="doc-link">
            📖 Doctrine orphan removal →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Add cascade remove to %s::$%s for consistent deletion',
        $entityClass,
        $fieldName,
    ),
];
