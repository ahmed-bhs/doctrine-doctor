<?php

declare(strict_types=1);

/**
 * @var string $entityClass Entity class name
 * @var string $fieldName   Relation field that has CASCADE DELETE
 */

// Extract context variables
$entityClass = $context['entity_class'] ?? '';
$fieldName = $context['field_name'] ?? '';

// Escaping function
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-content">
    <h3>CASCADE DELETE vs Soft Delete conflict</h3>
    <div class="alert alert-danger">
        Your entity uses soft delete but has a relation with <code>onDelete="CASCADE"</code>. This causes data loss.
    </div>

    <p>Soft delete keeps entities in database. CASCADE DELETE physically deletes children when parent is removed, causing data loss.</p>

    <h3>Current</h3>
    <pre><code class="language-php">#[ORM\Entity]
#[Gedmo\SoftDeleteable]
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Category $<?= $fieldName ?>;
}</code></pre>

    <h3>Solution: Use SET NULL</h3>
    <pre><code class="language-php">#[ORM\Entity]
#[Gedmo\SoftDeleteable]
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    #[ORM\JoinColumn(onDelete: 'SET NULL', nullable: true)]
    private ?Category $<?= $fieldName ?>;
}</code></pre>

    <p>Use SET NULL to orphan children, or ORM cascade to soft delete children too. Never mix soft delete with database CASCADE DELETE.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
