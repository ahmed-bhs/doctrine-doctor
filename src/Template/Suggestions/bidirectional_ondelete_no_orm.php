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
    <h4>Database CASCADE Without ORM Cascade</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Database/ORM Mismatch</strong><br>
        Database has <code>onDelete="CASCADE"</code> but ORM has no <code>cascade="remove"</code>.<br>
        Behavior differs between ORM and database deletes!
    </div>

    <h4>Problem</h4>
    <p>
        Behavior differs depending on how you delete:
    </p>
    <ul>
        <li><strong>ORM delete:</strong> Children remain (no cascade)</li>
        <li><strong>Database delete:</strong> Children are deleted (onDelete="CASCADE")</li>
    </ul>

    <h4> Solution: Align ORM with Database</h4>
    <div class="query-item">
        <pre><code class="language-php">class <?php echo $e($parentClass); ?> {
    /** @OneToMany(cascade={"persist", "remove"}) */
    private Collection $<?php echo $e($parentField); ?>;
}</code></pre>
    </div>

    <div class="alert alert-info">
        ℹ️ <strong>Rule:</strong> ORM cascade should match database onDelete behavior
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
    'description' => 'Add cascade="remove" to match database onDelete="CASCADE"',
];
