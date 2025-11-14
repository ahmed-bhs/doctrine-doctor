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

    <h4>Solution: Use Proper Object Relations</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($entityClass); ?> {
    /** @<?php echo $e($associationType); ?>(targetEntity="<?php echo $e($targetEntity); ?>") */
    private <?php echo $e($targetEntity); ?> $<?php echo $e(rtrim((string) $fieldName, 'Id_')); ?>;

    public function get<?php echo ucfirst(rtrim((string) $fieldName, 'Id_')); ?>(): <?php echo $e($targetEntity); ?> {
        return $this-><?php echo $e(rtrim((string) $fieldName, 'Id_')); ?>;
    }
}</code></pre>
    </div>

    <p>Object relations give you automatic lazy loading, type safety, IDE autocomplete, and easier queries. This is what ORMs are for.</p>

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
