<?php

declare(strict_types=1);

/**
 * Template for ORM Cascade / Database onDelete Mismatch suggestions.
 * Context variables:
 * @var string $entity_class - Short entity class name
 * @var string $target_class - Short target entity class name
 * @var string $field_name - Field name
 * @var string $orm_cascade - ORM cascade setting
 * @var string $db_on_delete - Database onDelete constraint
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context for clarity
$entityClass = $context['entity_class'] ?? 'Entity';
$targetClass = $context['target_class'] ?? 'ClassName';
$fieldName = $context['field_name'] ?? 'field';
$ormCascade = $context['orm_cascade'] ?? null;
$dbOnDelete = $context['db_on_delete'] ?? null;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
        <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/>
    </svg>
    <h4>Fix ORM Cascade / Database onDelete Mismatch</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>Mismatch detected between ORM cascade and database onDelete constraint.</strong><br><br>
        - ORM cascade: <code><?php echo $e($ormCascade); ?></code><br>
        - Database onDelete: <code><?php echo $e($dbOnDelete); ?></code><br><br>
        This mismatch can cause inconsistent behavior depending on whether you delete via ORM or direct SQL.
    </div>

    <h4>The Problem</h4>
    <p>
        When ORM cascade and database constraints differ, behavior depends on <strong>HOW</strong> you delete:
    </p>
    <ul>
        <li><code>$em->remove($entity)</code> uses ORM cascade (<code><?php echo $e($ormCascade); ?></code>)</li>
        <li>Direct SQL <code>DELETE</code> uses database constraint (<code><?php echo $e($dbOnDelete); ?></code>)</li>
    </ul>

    <h4>Example Scenario</h4>
    <div class="query-item">
        <pre><code class="language-php">// Via ORM (uses cascade="<?php echo $e($ormCascade); ?>")
$em->remove($<?php echo lcfirst($e($entityClass)); ?>);
$em->flush();
// Behavior: <?php echo 'remove' === $ormCascade ? 'Children ARE deleted' : 'Children are NOT deleted'; ?>

// Via direct SQL (uses onDelete="<?php echo $e($dbOnDelete); ?>")
DELETE FROM table WHERE id = ?;
// Behavior: <?php echo 'CASCADE' === $dbOnDelete ? 'Children ARE deleted' : ('SET NULL' === $dbOnDelete ? 'Children foreign keys set to NULL' : 'Children are NOT affected'); ?>
</code></pre>
    </div>

    <h4>Recommended Solution</h4>
    <p>Align the ORM cascade behavior with the database onDelete constraint, or vice versa.</p>

    <h5>Steps to Fix:</h5>
    <ol>
        <li>Review the relationship between <code><?php echo $e($entityClass); ?></code> and <code><?php echo $e($targetClass); ?></code></li>
        <li>Decide on the desired deletion behavior (CASCADE, SET NULL, RESTRICT, etc.)</li>
        <li>Update the <code>@JoinColumn(onDelete="...")</code> attribute to match ORM cascade</li>
        <li>Or update the <code>cascade={...}</code> option to match database constraint</li>
        <li>Create and run a migration to update the database constraint</li>
    </ol>

    <h5>Example Fix:</h5>
    <div class="query-item">
        <pre><code class="language-php">// Option 1: Make database match ORM cascade
#[ORM\ManyToOne(targetEntity: <?php echo $e($targetClass); ?>::class, cascade: ['<?php echo $e($ormCascade); ?>'])]
#[ORM\JoinColumn(onDelete: '<?php echo 'remove' === $ormCascade ? 'CASCADE' : 'SET NULL'; ?>')]
private $<?php echo $e($fieldName); ?>;

// Option 2: Make ORM cascade match database
#[ORM\ManyToOne(targetEntity: <?php echo $e($targetClass); ?>::class, cascade: [<?php echo 'CASCADE' === $dbOnDelete ? "'remove'" : "'persist'"; ?>])]
#[ORM\JoinColumn(onDelete: '<?php echo $e($dbOnDelete); ?>')]
private $<?php echo $e($fieldName); ?>;
</code></pre>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#joincolumn" target="_blank" class="doc-link">
            📖 Doctrine JoinColumn Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        "Align ORM cascade '%s' with DB onDelete '%s' in %s::\$%s",
        $ormCascade,
        $dbOnDelete,
        $entityClass,
        $fieldName,
    ),
];
