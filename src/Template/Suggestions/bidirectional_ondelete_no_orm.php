<?php

declare(strict_types=1);

/**
 * Template for onDelete="CASCADE" without cascade="remove".
 */
$parentClass = $context['parent_class'] ?? 'ClassName';
$parentField = $context['parent_field'] ?? 'field';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Database CASCADE without ORM cascade</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        Database has <code>onDelete="CASCADE"</code> but ORM has no <code>cascade="remove"</code>. Behavior differs between ORM and database deletes.
    </div>

    <p>ORM delete leaves children intact (no cascade). Database delete removes children (onDelete CASCADE). This inconsistency can lead to unexpected behavior.</p>

    <h4>Fix</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($parentClass); ?> {
    /** @OneToMany(cascade={"persist", "remove"}) */
    private Collection $<?php echo $e($parentField); ?>;
}</code></pre>
    </div>

    <p>Add cascade="remove" to match the database onDelete="CASCADE" so behavior is consistent.</p>

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
    'description' => 'Add cascade="remove" to match database onDelete="CASCADE"',
];
