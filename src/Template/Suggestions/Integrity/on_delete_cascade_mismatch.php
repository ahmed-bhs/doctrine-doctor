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
 * @var string $mismatch_type - Type of mismatch detected
 */

/** @var array<string, mixed> $context PHPStan: Template context */
$entityClass = $context['entity_class'] ?? 'Entity';
$targetClass = $context['target_class'] ?? 'ClassName';
$fieldName = $context['field_name'] ?? 'field';
$ormCascade = $context['orm_cascade'] ?? null;
$dbOnDelete = $context['db_on_delete'] ?? null;
$mismatchType = $context['mismatch_type'] ?? '';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Fix ORM Cascade / Database onDelete Mismatch</h4>
</div>

<div class="suggestion-content">
<?php if ('orm_cascade_db_setnull' === $mismatchType): ?>
    <div class="alert alert-danger">
        ORM <code>cascade: ['remove']</code> conflicts with DB <code>onDelete="SET NULL"</code>. <code>$em->remove()</code> deletes children, but a SQL DELETE sets FK to NULL.
    </div>
    <h4>Solution: Align DB onDelete with ORM cascade</h4>
    <p>You declared <code>cascade: ['remove']</code> in ORM, so deletions should cascade. Update the DB constraint to match:</p>
    <div class="query-item">
        <pre><code class="language-php">// On the inverse side (<?php echo $e($targetClass); ?>):
#[ORM\ManyToOne(targetEntity: <?php echo $e($entityClass); ?>::class)]
#[ORM\JoinColumn(onDelete: 'CASCADE')]

// Then run migration to update database constraint</code></pre>
    </div>

<?php elseif ('orm_orphan_db_setnull' === $mismatchType): ?>
    <div class="alert alert-danger">
        ORM <code>orphanRemoval=true</code> conflicts with DB <code>onDelete="SET NULL"</code>. ORM deletes orphans, but a SQL DELETE sets FK to NULL.
    </div>
    <h4>Solution: Align DB onDelete with orphanRemoval</h4>
    <p>You declared <code>orphanRemoval=true</code> in ORM, so orphans should be deleted. Update the DB constraint to match:</p>
    <div class="query-item">
        <pre><code class="language-php">// On the inverse side (<?php echo $e($targetClass); ?>):
#[ORM\ManyToOne(targetEntity: <?php echo $e($entityClass); ?>::class)]
#[ORM\JoinColumn(onDelete: 'CASCADE')]

// Then run migration to update database constraint</code></pre>
    </div>

<?php elseif ('db_cascade_no_orm' === $mismatchType): ?>
    <div class="alert alert-warning">
        DB has <code>onDelete="CASCADE"</code> but ORM has no <code>cascade: ['remove']</code>. SQL DELETEs cascade, but <code>$em->remove()</code> does not.
    </div>
    <h4>Solution: Add ORM cascade to match DB</h4>
    <p>DB already has <code>onDelete="CASCADE"</code>. Add the matching ORM cascade so <code>$em->remove()</code> behaves consistently:</p>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\OneToMany(targetEntity: <?php echo $e($targetClass); ?>::class, mappedBy: '...', cascade: ['remove'])]
private Collection $<?php echo $e($fieldName); ?>;</code></pre>
    </div>

<?php elseif ('orm_cascade_no_db' === $mismatchType): ?>
    <div class="alert alert-warning">
        ORM has <code>cascade: ['remove']</code> but no DB <code>onDelete</code> constraint. <code>$em->remove()</code> works, but direct SQL DELETEs may cause FK violations.
    </div>
    <h4>Solution: Add DB onDelete to match ORM cascade</h4>
    <p>ORM has <code>cascade: ['remove']</code> but direct SQL DELETEs may cause FK violations. Add the DB constraint:</p>
    <div class="query-item">
        <pre><code class="language-php">// On the inverse side (<?php echo $e($targetClass); ?>):
#[ORM\ManyToOne(targetEntity: <?php echo $e($entityClass); ?>::class)]
#[ORM\JoinColumn(onDelete: 'CASCADE')]

// Then run migration to update database constraint</code></pre>
    </div>

<?php endif; ?>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#joincolumn" target="_blank" class="doc-link">
            📖 Doctrine JoinColumn →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

$description = match ($mismatchType) {
    'orm_cascade_db_setnull' => sprintf("Change DB onDelete to 'CASCADE' to match ORM cascade in %s::\$%s", $entityClass, $fieldName),
    'orm_orphan_db_setnull' => sprintf("Change DB onDelete to 'CASCADE' to match orphanRemoval in %s::\$%s", $entityClass, $fieldName),
    'db_cascade_no_orm' => sprintf("Add ORM cascade: ['remove'] to match DB onDelete='CASCADE' in %s::\$%s", $entityClass, $fieldName),
    'orm_cascade_no_db' => sprintf("Add DB onDelete='CASCADE' to match ORM cascade in %s::\$%s", $entityClass, $fieldName),
    default => sprintf("Align ORM cascade '%s' with DB onDelete '%s' in %s::\$%s", $ormCascade, $dbOnDelete, $entityClass, $fieldName),
};

return [
    'code'        => $code,
    'description' => $description,
];
