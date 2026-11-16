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

ob_start();
?>
<?php echo suggestionHeader('Map the foreign key as an association'); ?>
<div class="suggestion-content">
<p><code><?php echo $e($entityClass); ?>::$<?php echo $e($fieldName); ?></code> stores the id of a <code><?php echo $e($targetEntity); ?></code> as a plain integer. Doctrine then cannot load, join or check the related entity, and every caller must look it up by hand.</p>

<div class="query-item"><pre><code class="language-php">// Before
#[ORM\Column(type: Types::INTEGER)]
private int $<?php echo $e($fieldName); ?>;

// After
#[ORM\<?php echo $e($association); ?>(targetEntity: <?php echo $e($targetEntity); ?>::class)]
#[ORM\JoinColumn(nullable: false)]
private <?php echo $e($targetEntity); ?> $<?php echo $e($propertyName); ?>;</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/association-mapping.html', 'Doctrine Association Mapping'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Map the foreign key as an association',
];
