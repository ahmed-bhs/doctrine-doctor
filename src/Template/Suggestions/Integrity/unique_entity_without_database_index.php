<?php

declare(strict_types=1);

/**
 * Template for UniqueEntity without database UNIQUE index suggestions.
 * Context variables:
 * @var string $entity_class - Short entity class name
 * @var string $entity_fqcn - Fully qualified entity class name
 * @var array $fields - Field names from #[UniqueEntity]
 * @var array $columns - Database column names
 * @var bool $is_single_column - Whether it's a single column constraint
 */

/** @var array<string, mixed> $context PHPStan: Template context */
$entityClass = $context['entity_class'] ?? 'Entity';
$fields = $context['fields'] ?? [];
$columns = $context['columns'] ?? [];
$isSingleColumn = $context['is_single_column'] ?? false;

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Add a database unique constraint'); ?>

<div class="suggestion-content">
    <p><?php echo t('<code>#[UniqueEntity]</code> on <code>%s</code> checks uniqueness in PHP only. Two concurrent requests can both pass the check and insert duplicates unless the database enforces it.', $e($entityClass)); ?></p>

    <div class="query-item">
        <pre><code class="language-php">#[ORM\Entity]
#[ORM\UniqueConstraint(columns: [<?php echo implode(', ', array_map(fn (string $c): string => "'" . $e($c) . "'", [] !== $columns ? $columns : $fields)); ?>])]
#[UniqueEntity(fields: [<?php echo implode(', ', array_map(fn (string $f): string => "'" . $e($f) . "'", $fields)); ?>])]
class <?php echo $e($entityClass); ?>

{
}</code></pre>
    </div>

<?php if ($isSingleColumn && 1 === count($fields)) { ?>
    <p><?php echo t('For a single column, <code>#[ORM\Column(unique: true)]</code> on <code>$%s</code> does the same. Generate a migration afterwards.', $e($fields[0])); ?></p>
<?php } else { ?>
    <p><?php echo t('Generate a migration afterwards; it fails if duplicates already exist.'); ?></p>
<?php } ?>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/attributes-reference.html#uniqueconstraint', 'Doctrine UniqueConstraint reference'); ?>
</div>
<?php
$code = ob_get_clean();

$fieldList = implode(', ', $fields);

return [
    'code'        => $code,
    'description' => t('Add a database unique constraint on %s for %s', $fieldList, $entityClass),
];
