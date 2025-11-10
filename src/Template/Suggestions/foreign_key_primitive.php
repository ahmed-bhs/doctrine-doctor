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

ob_start();
?>

<div class="suggestion-header">
    <h4>Foreign Key Mapped as Primitive Type (Anti-Pattern)</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Doctrine Anti-Pattern Detected</strong><br>
        Field <code><?php echo $e($fieldName); ?></code> in <code><?php echo $e($entityClass); ?></code> appears to be a foreign key
        but is mapped as a primitive type (integer).<br>
        This defeats the purpose of using an ORM!
    </div>

    <h4>Current Anti-Pattern</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($entityClass); ?> {
    /** @Column(type="integer") */
    private int $<?php echo $e($fieldName); ?>;

    public function get<?php echo ucfirst((string) $fieldName); ?>(): int {
        return $this-><?php echo $e($fieldName); ?>;
    }
}</code></pre>
    </div>

    <h4> Recommended Doctrine Approach</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($entityClass); ?> {
    /** @<?php echo $e($associationType); ?>(targetEntity="<?php echo $e($targetEntity); ?>") */
    private <?php echo $e($targetEntity); ?> $<?php echo $e(rtrim((string) $fieldName, 'Id_')); ?>;

    public function get<?php echo ucfirst(rtrim((string) $fieldName, 'Id_')); ?>(): <?php echo $e($targetEntity); ?> {
        return $this-><?php echo $e(rtrim((string) $fieldName, 'Id_')); ?>;
    }

    public function set<?php echo ucfirst(rtrim((string) $fieldName, 'Id_')); ?>(<?php echo $e($targetEntity); ?> $<?php echo $e(rtrim((string) $fieldName, 'Id_')); ?>): self {
        $this-><?php echo $e(rtrim((string) $fieldName, 'Id_')); ?> = $<?php echo $e(rtrim((string) $fieldName, 'Id_')); ?>;
        return $this;
    }
}</code></pre>
    </div>

    <h4>Benefits of Object Relations</h4>
    <ul>
        <li> Object-oriented code instead of procedural</li>
        <li> Automatic lazy loading of related entities</li>
        <li> Type safety and IDE autocomplete</li>
        <li> Doctrine manages the relationship automatically</li>
        <li> Easier to work with joins and queries</li>
    </ul>

    <h4>Migration Steps</h4>
    <ol>
        <li>Add the new <?php echo $e($associationType); ?> relation field</li>
        <li>Create migration to keep the database column</li>
        <li>Update all code using the old field</li>
        <li>Remove the primitive field once migration is complete</li>
    </ol>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/association-mapping.html" target="_blank" class="doc-link">
            📖 Doctrine Association Mapping →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Replace primitive foreign key with proper object relation',
];
