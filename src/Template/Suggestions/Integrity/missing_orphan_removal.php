<?php

declare(strict_types=1);

/**
 * Template for missing orphanRemoval on composition suggestions.
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
<?php echo suggestionHeader('Add orphanRemoval to the collection'); ?>

<div class="suggestion-content">
    <p>Removing an item from <code><?php echo $e((string) $entityClass); ?>::$<?php echo $e((string) $fieldName); ?></code> only unlinks it, so the <code><?php echo $e((string) $targetClass); ?></code> row stays in the table<?php if ($isNotNullFK) { ?>, and its NOT NULL foreign key makes the flush fail<?php } ?>. With <code>orphanRemoval</code>, Doctrine deletes the removed child.</p>

    <div class="query-item">
        <pre><code class="language-php">#[ORM\OneToMany(
    targetEntity: <?php echo $e((string) $targetClass); ?>::class,
    mappedBy: '<?php echo $e((string) $mappedBy); ?>',
    cascade: ['persist', 'remove'],
    orphanRemoval: true,
)]
private Collection $<?php echo $e((string) $fieldName); ?>;</code></pre>
    </div>

    <p>Use it only when the parent owns its children (order and order lines), not for shared entities.</p>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/working-with-associations.html#orphan-removal', 'Doctrine Orphan Removal'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Add orphanRemoval: true to %s::$%s', $entityClass, $fieldName),
];
