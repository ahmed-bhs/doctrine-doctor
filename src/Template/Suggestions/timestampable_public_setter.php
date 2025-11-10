<?php

declare(strict_types=1);

/**
 * Template for Timestampable Public Setter.
 */
['entity_class' => $entityClass, 'field_name' => $fieldName] = $context;
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>💡 Public Setter on Timestamp Field</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-info">
        <strong>Field:</strong> <code><?php echo $e($entityClass); ?>::$<?php echo $e($fieldName); ?></code><br>
        Timestamps should be managed automatically, not manually via public setters.
    </div>

    <h4>Solution: Remove Public Setter</h4>
    <div class="query-item">
        <pre><code class="language-php">// Before: Public setter allows manual manipulation
class <?php echo $e($entityClass); ?> {
    #[Gedmo\Timestampable(on: 'create')]
    private \DateTimeImmutable $<?php echo $e($fieldName); ?>;
    
    public function set<?php echo ucfirst($fieldName); ?>(\DateTimeImmutable $date): void {
        $this-><?php echo $e($fieldName); ?> = $date;  // Defeats automatic timestamping!
    }
}

// After: Only getter, automatic management
class <?php echo $e($entityClass); ?> {
    #[Gedmo\Timestampable(on: 'create')]
    private \DateTimeImmutable $<?php echo $e($fieldName); ?>;
    
    public function get<?php echo ucfirst($fieldName); ?>(): \DateTimeImmutable {
        return $this-><?php echo $e($fieldName); ?>;
    }
    // No setter - Gedmo manages it automatically
}</code></pre>
    </div>

    <p>Timestamps are managed by Doctrine/Gedmo lifecycle events. Public setters defeat this purpose.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Remove public setter on timestamp field',
];
