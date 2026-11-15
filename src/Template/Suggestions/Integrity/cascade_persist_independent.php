<?php

declare(strict_types=1);

/**
 * Template for cascade="persist" on Independent Entity.
 */
$entityClass = $context['entity_class'] ?? 'Entity';
$fieldName = $context['field_name'] ?? 'field';
$targetEntity = $context['target_entity'] ?? 'Entity';
$referenceCount = $context['reference_count'] ?? null;
$associationType = $context['association_type'] ?? null;

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$mapping = (string) ($associationType ?? 'ManyToOne');

ob_start();
?>
<?php echo suggestionHeader('Load the existing entity instead of cascading'); ?>
<div class="suggestion-content">
<p><code><?php echo $e($entityClass); ?>::$<?php echo $e($fieldName); ?></code> cascades persist to <code><?php echo $e($targetEntity); ?></code>, which <?php echo null !== $referenceCount ? $e((string) $referenceCount) . ' entities reference' : 'other entities reference'; ?>. A new or detached <?php echo $e($targetEntity); ?> passed here is inserted again instead of being linked to the existing row.</p>

<div class="query-item"><pre><code class="language-php">// Remove the persist cascade from the mapping
#[ORM\<?php echo $e($mapping); ?>(targetEntity: <?php echo $e($targetEntity); ?>::class)]
private <?php echo $e($targetEntity); ?> $<?php echo $e($fieldName); ?>;

// Link to the existing row
$entity->set<?php echo $e(ucfirst((string) $fieldName)); ?>($em->getReference(<?php echo $e($targetEntity); ?>::class, $<?php echo $e($fieldName); ?>Id));</code></pre></div>

<p>Keep the persist cascade for children owned by one parent only, such as order lines.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/working-with-associations.html#transitive-persistence-cascade-operations', 'Doctrine Cascade Operations'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Remove cascade="persist" from independent entity %s to prevent duplicates',
        $targetEntity,
    ),
];
