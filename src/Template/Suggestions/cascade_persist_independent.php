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
    <h4>🚨 cascade="persist" on Independent Entity (Risk of Duplicates)</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        🚨 <strong>Critical Issue: Risk of Duplicate Records</strong><br>
        Field <code><?php echo $e($fieldName); ?></code> has <code>cascade="persist"</code> on independent entity
        <code><?php echo $e($targetEntity); ?></code>.<br>
        This entity is referenced by <strong><?php echo $referenceCount; ?> entities</strong> - it's independent!
    </div>

    <h4>Why This Is a Problem</h4>
    <ul>
        <li><?php echo $e($targetEntity); ?> is referenced by <?php echo $referenceCount; ?> entities (independent entity)</li>
        <li>cascade="persist" will CREATE new <?php echo $e($targetEntity); ?> records</li>
        <li>You should LOAD existing <?php echo $e($targetEntity); ?> from database instead</li>
    </ul>

    <h4>📢 Current Configuration</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($entityClass); ?> {
    /** @<?php echo $e($associationType); ?>(targetEntity="<?php echo $e($targetEntity); ?>", cascade={"persist"}) */
    private <?php echo $e($targetEntity); ?> $<?php echo $e($fieldName); ?>;
}</code></pre>
    </div>

    <h4>📢 Current Usage (Creates Duplicates)</h4>
    <div class="query-item">
        <pre><code class="language-php">$entity = new <?php echo $e($entityClass); ?>();
$<?php echo $e($fieldName); ?> = new <?php echo $e($targetEntity); ?>();  // Creates NEW record!
$<?php echo $e($fieldName); ?>->setName('John Doe');
$entity->set<?php echo ucfirst((string) $fieldName); ?>($<?php echo $e($fieldName); ?>);
$em->persist($entity);  // Also persists <?php echo $e($targetEntity); ?> (duplicate!)
$em->flush();</code></pre>
    </div>

    <h4> SOLUTION 1: Remove cascade="persist" and Load from Database</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($entityClass); ?> {
    /** @<?php echo $e($associationType); ?>(targetEntity="<?php echo $e($targetEntity); ?>") */
    private <?php echo $e($targetEntity); ?> $<?php echo $e($fieldName); ?>;
    // NO CASCADE
}

// Load existing <?php echo $e($targetEntity); ?>

$entity = new <?php echo $e($entityClass); ?>();
$<?php echo $e($fieldName); ?> = $em->find(<?php echo $e($targetEntity); ?>::class, $<?php echo $e($fieldName); ?>Id);
$entity->set<?php echo ucfirst((string) $fieldName); ?>($<?php echo $e($fieldName); ?>);
$em->persist($entity);
$em->flush();</code></pre>
    </div>

    <h4> SOLUTION 2: Use getReference() for Better Performance</h4>
    <div class="query-item">
        <pre><code class="language-php">// Even faster - creates proxy without hitting DB
$<?php echo $e($fieldName); ?> = $em->getReference(<?php echo $e($targetEntity); ?>::class, $<?php echo $e($fieldName); ?>Id);
$entity->set<?php echo ucfirst((string) $fieldName); ?>($<?php echo $e($fieldName); ?>);
$em->persist($entity);
$em->flush();</code></pre>
    </div>

    <h4>Benefits of Removing cascade="persist"</h4>
    <ul>
        <li> No duplicate <?php echo $e($targetEntity); ?> records</li>
        <li> Referential integrity maintained</li>
        <li> Forces developers to use existing records</li>
        <li> Clearer code intent</li>
    </ul>

    <div class="alert alert-info">
        ℹ️ <strong>When cascade="persist" IS Appropriate:</strong><br>
        Only on composition relationships (Order → OrderItems) where child entities don't exist independently.<br>
        <strong>Never</strong> on User, Customer, Product, Category, etc.
    </div>

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
