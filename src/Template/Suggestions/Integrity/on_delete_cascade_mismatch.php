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
<?php
$why = match ($mismatchType) {
    'orm_cascade_db_setnull' => t("ORM <code>cascade: ['remove']</code> deletes the children, but the database foreign key uses <code>onDelete: 'SET NULL'</code>, so a plain SQL DELETE keeps them with a NULL parent."),
    'orm_orphan_db_setnull' => t("<code>orphanRemoval</code> deletes orphans in the ORM, but the database foreign key uses <code>onDelete: 'SET NULL'</code>, so a plain SQL DELETE keeps them with a NULL parent."),
    'db_cascade_no_orm' => t("The database deletes the children (<code>onDelete: 'CASCADE'</code>) without the ORM, so their preRemove/postRemove callbacks and listeners never run. Add the ORM cascade only if those callbacks must run: it costs one query per child."),
    'orm_cascade_no_db' => t("ORM <code>cascade: ['remove']</code> works through <code>\$em-&gt;remove()</code>, but the foreign key has no <code>onDelete</code>, so a plain SQL DELETE of the parent fails with a constraint violation."),
    default => t('The ORM cascade and the database <code>onDelete</code> rule disagree, so deleting through the ORM and through SQL give different results.'),
};
?>
<?php echo suggestionHeader('Align the ORM cascade with onDelete'); ?>

<div class="suggestion-content">
    <p><?php echo $why; ?></p>

    <div class="query-item">
<?php if ('db_cascade_no_orm' === $mismatchType) { ?>
        <pre><code class="language-php">// <?php echo $e($entityClass); ?>

#[ORM\OneToMany(targetEntity: <?php echo $e($targetClass); ?>::class, mappedBy: '...', cascade: ['remove'])]
private Collection $<?php echo $e($fieldName); ?>;</code></pre>
<?php } else { ?>
        <pre><code class="language-php">// <?php echo $e($targetClass); ?> (owning side), then generate a migration
#[ORM\ManyToOne(targetEntity: <?php echo $e($entityClass); ?>::class)]
#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
private <?php echo $e($entityClass); ?> $parent;</code></pre>
<?php } ?>
    </div>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#joincolumn', 'Doctrine JoinColumn'); ?>
</div>
<?php
$code = ob_get_clean();

$description = match ($mismatchType) {
    'orm_cascade_db_setnull' => t("Change DB onDelete to 'CASCADE' to match ORM cascade in %s::\$%s", $entityClass, $fieldName),
    'orm_orphan_db_setnull' => t("Change DB onDelete to 'CASCADE' to match orphanRemoval in %s::\$%s", $entityClass, $fieldName),
    'db_cascade_no_orm' => t("Add ORM cascade: ['remove'] in %s::\$%s if the children's remove callbacks must run", $entityClass, $fieldName),
    'orm_cascade_no_db' => t("Add DB onDelete='CASCADE' to match ORM cascade in %s::\$%s", $entityClass, $fieldName),
    default => t("Align ORM cascade '%s' with DB onDelete '%s' in %s::\$%s", (string) $ormCascade, (string) $dbOnDelete, $entityClass, $fieldName),
};

return [
    'code'        => $code,
    'description' => $description,
];
