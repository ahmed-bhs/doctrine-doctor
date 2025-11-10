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
    <h4>Inconsistency: cascade="remove" with onDelete="SET NULL"</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Behavior Differs: ORM vs Database</strong><br>
        <code><?php echo $e($parentField); ?></code> has <code>cascade="remove"</code> (ORM deletes children)
        but database has <code>onDelete="SET NULL"</code> (sets FK to NULL).
    </div>

    <h4>Problem</h4>
    <p>
        Behavior differs depending on how you delete:
    </p>
    <ul>
        <li><strong>ORM delete:</strong> Children are deleted (cascade="remove")</li>
        <li><strong>Database delete:</strong> Children FK set to NULL (onDelete="SET NULL")</li>
    </ul>

    <h4> Solution: Make Them Consistent</h4>
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

    <div class="alert alert-info">
        ℹ️ <strong>Rule:</strong> cascade="remove" should match onDelete="CASCADE"
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use onDelete="CASCADE" to match cascade="remove"',
];
