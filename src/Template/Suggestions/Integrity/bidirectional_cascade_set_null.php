<?php

declare(strict_types=1);

/**
 * Template for cascade="remove" with onDelete="SET NULL" inconsistency.
 */
$parentClass = $context['parent_class'] ?? 'ClassName';
$parentField = $context['parent_field'] ?? 'field';
$childClass = $context['child_class'] ?? 'ClassName';
$childField = $context['child_field'] ?? 'field';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>cascade="remove" with onDelete="SET NULL"</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <code><?php echo $e($parentField); ?></code> has <code>cascade="remove"</code> (ORM deletes children)
        but database has <code>onDelete="SET NULL"</code> (sets FK to NULL).
    </div>

    <p>Behavior differs depending on how you delete. ORM delete removes children via cascade. Database delete sets FK to NULL. This is confusing and error-prone.</p>

    <h4>Fix</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($parentClass); ?> {
    /** @OneToMany(cascade={"remove"}) */
    private Collection $<?php echo $e($parentField); ?>;
}

class <?php echo $e($childClass); ?> {
    /** @ManyToOne @JoinColumn(nullable=false, onDelete="CASCADE") */
    private <?php echo $e($parentClass); ?> $<?php echo $e($childField); ?>;
}</code></pre>
    </div>

    <p>Make cascade="remove" match onDelete="CASCADE" so behavior is consistent whether you delete via ORM or database.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use onDelete="CASCADE" to match cascade="remove"',
];
