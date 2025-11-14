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

    <p>Soft delete means entities are never physically deleted from the database. CASCADE DELETE triggers physical deletion when the parent is removed. When you soft delete a parent, CASCADE will physically delete children, which defeats the purpose of soft delete.</p>

    <h3>Current code</h3>
    <pre><code class="language-php">#[ORM\Entity]
#[Gedmo\SoftDeleteable]
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $deletedAt = null;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Category $<?= $fieldName ?>;
}

// What happens:
// 1. Soft delete Post: deletedAt = now() ← Post stays in DB
// 2. Hard delete Category: CASCADE triggers ← Related Posts are PHYSICALLY deleted!
// 3. Result: Data loss</code></pre>

    <h3>Option 1: Remove CASCADE DELETE</h3>
    <pre><code class="language-php">#[ORM\Entity]
#[Gedmo\SoftDeleteable]
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL', nullable: true)]
    private ?Category $<?= $fieldName ?>;

    // Or use RESTRICT to prevent deletion
    // #[ORM\JoinColumn(onDelete: 'RESTRICT')]
}</code></pre>

    <h3>Option 2: Soft delete children too</h3>
    <p>Use ORM cascade, not database cascade:</p>

    <pre><code class="language-php">#[ORM\Entity]
#[Gedmo\SoftDeleteable]
class Category
{
    #[ORM\OneToMany(targetEntity: Post::class, mappedBy: 'category', cascade: ['remove'])]
    private Collection $posts;
}

// Now when Category is soft deleted, Posts are also soft deleted</code></pre>

    <p>Choose SET NULL if children should be orphaned, or ORM cascade if children should also soft delete. Never mix soft delete with database CASCADE DELETE.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
