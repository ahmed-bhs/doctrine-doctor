<?php

declare(strict_types=1);

/**
 * Template for cascade="persist" on Independent Entity.
 * Context variables:
 */
$entityClass = $context['entity_class'] ?? 'Entity';
$fieldName = $context['field_name'] ?? 'field';
$targetEntity = $context['target_entity'] ?? 'Entity';
$referenceCount = $context['reference_count'] ?? null;
$associationType = $context['association_type'] ?? null;

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>cascade="persist" on Independent Entity (Risk of Duplicates)</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>Risk of Duplicate Records</strong><br>
        Field <code><?php echo $e($fieldName); ?></code> has <code>cascade="persist"</code> on independent entity
        <code><?php echo $e($targetEntity); ?></code>.<br>
        This entity is referenced by <strong><?php echo $referenceCount; ?> entities</strong> - it's independent!
    </div>

    <p><?php echo $e($targetEntity); ?> is referenced by <?php echo $referenceCount; ?> entities (independent). cascade="persist" will create duplicates instead of loading existing records.</p>

    <h4>Solution: Remove cascade="persist" and Load Existing Entity</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($entityClass); ?> {
    /** @<?php echo $e($associationType); ?>(targetEntity="<?php echo $e($targetEntity); ?>") */
    private <?php echo $e($targetEntity); ?> $<?php echo $e($fieldName); ?>;
    // NO CASCADE
}

// Load existing <?php echo $e($targetEntity); ?> (or use getReference() for better performance)
$entity = new <?php echo $e($entityClass); ?>();
$<?php echo $e($fieldName); ?> = $em->find(<?php echo $e($targetEntity); ?>::class, $<?php echo $e($fieldName); ?>Id);
$entity->set<?php echo ucfirst((string) $fieldName); ?>($<?php echo $e($fieldName); ?>);
$em->persist($entity);
$em->flush();</code></pre>
    </div>

    <p><strong>Use cascade="persist" only</strong> on composition relationships (Order → OrderItems) where children don't exist independently. <strong>Never</strong> on User, Customer, Product, Category, etc.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/working-with-associations.html#transitive-persistence-cascade-operations" target="_blank" class="doc-link">
            📖 Doctrine Cascade Operations →
        </a>
    </p>
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
