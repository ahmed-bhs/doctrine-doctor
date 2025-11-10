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
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
    </svg>
    <h4>Add orphanRemoval for Composition Relationship</h4>
</div>

<div class="suggestion-content">
    <?php if ($isNotNullFK) { ?>
    <div class="alert alert-danger">
        🚨 <strong>CRITICAL: Foreign key is NOT NULL but orphanRemoval is missing!</strong><br><br>
        This is inconsistent - children cannot be orphaned but you're not deleting them.
    </div>
    <?php } else { ?>
    <div class="alert alert-warning">
        <strong>Composition relationship detected without orphanRemoval=true</strong>
    </div>
    <?php } ?>

    <h4>Current configuration</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($entityClass); ?> {
    #[ORM\OneToMany(
        targetEntity: <?php echo $e($targetClass); ?>::class,
        mappedBy: '<?php echo $e($mappedBy); ?>',
        <?php echo $e($currentCascade); ?>
    )]
    private Collection $<?php echo $e($fieldName); ?>;
}</code></pre>
    </div>

    <h4>Problem</h4>
    <div class="query-item">
        <pre><code class="language-php">$<?php echo lcfirst($e($entityClass)); ?> = $em->find(<?php echo $e($entityClass); ?>::class, $id);
$item = $<?php echo lcfirst($e($entityClass)); ?>->get<?php echo ucfirst($e($fieldName)); ?>()->first();
$<?php echo lcfirst($e($entityClass)); ?>->remove<?php echo ucfirst(rtrim($e($fieldName), 's')); ?>($item);
$em->flush();

//  <?php echo $e($targetClass); ?> remains in database with <?php echo $e($mappedBy); ?>_id = NULL (orphan!)
// This pollutes your database with garbage data</code></pre>
    </div>

    <h4>Solution: Add orphanRemoval=true</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($entityClass); ?> {
    #[ORM\OneToMany(
        targetEntity: <?php echo $e($targetClass); ?>::class,
        mappedBy: '<?php echo $e($mappedBy); ?>',
        cascade: ['persist', 'remove'],
        orphanRemoval: true  // ← Add this
    )]
    private Collection $<?php echo $e($fieldName); ?>;
}</code></pre>
    </div>

    <h4>Behavior with orphanRemoval=true</h4>
    <div class="query-item">
        <pre><code class="language-php">$<?php echo lcfirst($e($entityClass)); ?>->remove<?php echo ucfirst(rtrim($e($fieldName), 's')); ?>($item);
$em->flush();
// <?php echo $e($targetClass); ?> is automatically DELETED from database</code></pre>
    </div>

    <h4>Benefits</h4>
    <ul>
        <li>No orphaned records in database</li>
        <li>Automatic cleanup when removing from collection</li>
        <li>Consistent with composition semantics</li>
        <li>Database stays clean</li>
    </ul>

    <h4>When to use orphanRemoval=true</h4>
    <ul>
        <li>Parent fully owns children (Order → OrderItems)</li>
        <li>Children cannot exist without parent</li>
        <li>Removing from collection = deleting from database</li>
    </ul>

    <h4>When NOT to use</h4>
    <ul>
        <li>📢 Children are independent entities (Order → Products)</li>
        <li>📢 Children can be reassigned to other parents</li>
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
        'Add orphanRemoval=true to %s::$%s for proper composition handling',
        $entityClass,
        $fieldName,
    ),
];
