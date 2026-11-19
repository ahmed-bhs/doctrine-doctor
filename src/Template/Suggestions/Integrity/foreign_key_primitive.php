<?php

declare(strict_types=1);

/**
 * Template for Foreign Key Mapped as Primitive Type.
 * Context variables:
 */
$entityClass = $context['entity_class'] ?? 'Entity';
$fieldName = $context['field_name'] ?? 'field';
$targetEntity = $context['target_entity'] ?? 'Entity';
$associationType = $context['association_type'] ?? null;

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$association = (string) ($associationType ?? 'ManyToOne');
$propertyName = (string) preg_replace('/_?id$/i', '', (string) $fieldName);
$propertyName = '' !== $propertyName ? $propertyName : (string) $fieldName;
$targetParts = explode('\\', ltrim((string) $targetEntity, '\\'));
$targetShort = (string) end($targetParts);
$targetFqcn = ltrim((string) $targetEntity, '\\');
$columnName = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', (string) $fieldName));

ob_start();
?>
<?php echo suggestionHeader('Map the foreign key as an association'); ?>
<div class="suggestion-content">
<p><?php echo t('<code>%s::$%s</code> stores the id of a <code>%s</code> as a plain integer. Doctrine then cannot load, join or check the related entity, and every caller must look it up by hand.', $e($entityClass), $e($fieldName), $e($targetEntity)); ?></p>

<div class="query-item"><pre><code class="language-php">use <?php echo $e($targetFqcn); ?>;

// Before
#[ORM\Column(type: Types::INTEGER)]
private int $<?php echo $e($fieldName); ?>;

// After: same column, now with a foreign key the database checks
#[ORM\<?php echo $e($association); ?>(targetEntity: <?php echo $e($targetShort); ?>::class)]
#[ORM\JoinColumn(name: '<?php echo $e($columnName); ?>', nullable: false)]
private <?php echo $e($targetShort); ?> $<?php echo $e($propertyName); ?>;</code></pre></div>

<p><?php echo t('Keeping the column name keeps the data: the migration only adds the foreign key constraint, and fails if a row points to a missing %s.', $e($targetShort)); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/association-mapping.html', 'Doctrine Association Mapping'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Map the foreign key as an association'),
];
