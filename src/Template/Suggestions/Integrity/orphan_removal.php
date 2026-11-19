<?php

declare(strict_types=1);

/**
 * Template for orphanRemoval without cascade="remove" suggestions.
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
<?php echo suggestionHeader('Add cascade remove next to orphanRemoval'); ?>

<div class="suggestion-content">
    <p><?php echo t('<code>%s::$%s</code> has <code>orphanRemoval: true</code> without <code>cascade: [\'remove\']</code>. Children removed from the collection are deleted, but removing the parent deletes them only if the ORM cascade is set, so both paths disagree.', $e((string) $entityClass), $e((string) $fieldName)); ?></p>

    <div class="query-item">
        <pre><code class="language-php">#[ORM\OneToMany(
    targetEntity: <?php echo $e((string) $targetClass); ?>::class,
    mappedBy: '<?php echo $e((string) $mappedBy); ?>',
    cascade: ['persist', 'remove'],
    orphanRemoval: true,
)]
private Collection $<?php echo $e((string) $fieldName); ?>;</code></pre>
    </div>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/working-with-associations.html#orphan-removal', 'Doctrine orphan removal'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t("Add cascade: ['remove'] to %s::$%s", $entityClass, $fieldName),
];
