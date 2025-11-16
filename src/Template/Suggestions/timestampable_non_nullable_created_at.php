<?php

declare(strict_types=1);

/**
 * Template for Timestampable Non-Nullable createdAt.
 */
['entity_class' => $entityClass, 'field_name' => $fieldName] = $context;
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Nullable Timestamp Field</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        Field <code>$<?= $e($fieldName) ?></code> in <code><?= $e($entityClass) ?></code> is nullable - timestamp fields should be NOT NULL.
    </div>

    <h4>Solution: Make it non-nullable</h4>
    <div class="query-item">
        <pre><code class="language-php">// Before: Nullable (wrong)
class <?= $e($entityClass) ?> {
    #[ORM\Column(nullable: true)]  // Wrong!
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $<?= $e($fieldName) ?>;
}

// After: Non-nullable (correct)
class <?= $e($entityClass) ?> {
    #[ORM\Column(nullable: false)]  // Correct
    #[Gedmo\Timestampable(on: 'create')]
    private \DateTimeImmutable $<?= $e($fieldName) ?>;
}</code></pre>
    </div>

    <p>Timestamps are always set automatically - they should never be NULL.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Make timestamp field non-nullable',
];
