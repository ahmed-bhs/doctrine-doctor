<?php

declare(strict_types=1);

/**
 * Template for ORM Cascade / Database onDelete Mismatch suggestions.
 * Context variables:
 * @var string $entity_class - Short entity class name
 * @var string $target_class - Short target entity class name
 * @var string $field_name - Field name
 * @var string $orm_cascade - ORM cascade setting
 * @var string $db_on_delete - Database onDelete constraint
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
$entityClass = $context['entity_class'] ?? 'Entity';
$targetClass = $context['target_class'] ?? 'ClassName';
$fieldName = $context['field_name'] ?? 'field';
$ormCascade = $context['orm_cascade'] ?? null;
$dbOnDelete = $context['db_on_delete'] ?? null;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Fix ORM Cascade / Database onDelete Mismatch</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        ORM cascade <code><?php echo $e($ormCascade); ?></code> conflicts with DB onDelete <code><?php echo $e($dbOnDelete); ?></code>. Deletion behavior varies by method (ORM vs SQL).
    </div>

    <h4>Solution: Align both settings</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\ManyToOne(targetEntity: <?php echo $e($targetClass); ?>::class, cascade: ['<?php echo $e($ormCascade); ?>'])]
#[ORM\JoinColumn(onDelete: '<?php echo 'remove' === $ormCascade ? 'CASCADE' : 'SET NULL'; ?>')]
private $<?php echo $e($fieldName); ?>;

// Then run migration to update database constraint</code></pre>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#joincolumn" target="_blank" class="doc-link">
            📖 Doctrine JoinColumn →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        "Align ORM cascade '%s' with DB onDelete '%s' in %s::\$%s",
        $ormCascade,
        $dbOnDelete,
        $entityClass,
        $fieldName,
    ),
];
