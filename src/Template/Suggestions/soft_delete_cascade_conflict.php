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
    <h3>🚨 CRITICAL: CASCADE DELETE vs Soft Delete Conflict!</h3>
    <p>
        Your entity uses <strong>soft delete</strong>, but has a relation with <code>onDelete="CASCADE"</code>:
    </p>
    <ul>
        <li><strong>Soft delete</strong> means entities are NEVER physically deleted from database</li>
        <li><strong>CASCADE DELETE</strong> triggers physical deletion when the parent is removed</li>
        <li>This creates a conflict: soft deleted parent → CASCADE deletes children physically!</li>
        <li>This causes <strong>data loss</strong> - children are permanently deleted</li>
    </ul>

    <h3>The Problem</h3>
    <pre><code class="language-php">
//  BAD: Conflict!
#[ORM\Entity]
#[Gedmo\SoftDeleteable]
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\\DateTime $deletedAt = null;

    //  onDelete: 'CASCADE' will physically delete related entities!
    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Category $<?= $fieldName ?>;
}

// What happens:
// 1. Soft delete Post: deletedAt = now() ← Post stays in DB
// 2. Hard delete Category: CASCADE triggers ← Related Posts are PHYSICALLY deleted!
// 3. Result: Data loss! Posts are gone forever, not soft deleted
</code></pre>

    <h3>Solution 1: Remove CASCADE DELETE</h3>
    <pre><code class="language-php">
#[ORM\Entity]
#[Gedmo\SoftDeleteable]
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\\DateTime $deletedAt = null;

    // GOOD: No CASCADE DELETE
    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL', nullable: true)]
    private ?Category $<?= $fieldName ?>;

    // Or use RESTRICT to prevent deletion
    // #[ORM\JoinColumn(onDelete: 'RESTRICT')]
}
</code></pre>

    <h3>Solution 2: Also Soft Delete Children</h3>
    <p>If children should be deleted when parent is deleted, use ORM cascade, not database cascade:</p>

    <pre><code class="language-php">
#[ORM\Entity]
#[Gedmo\SoftDeleteable]
class Category
{
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\\DateTime $deletedAt = null;

    // ORM cascade: soft deletes children too
    #[ORM\OneToMany(targetEntity: Post::class, mappedBy: 'category', cascade: ['remove'])]
    private Collection $posts;
}

// Now when Category is soft deleted, Posts are also soft deleted
</code></pre>

    <h3>Solution 3: No Soft Delete on Parent</h3>
    <p>If the parent should NOT be soft deleted, remove SoftDeleteable:</p>

    <pre><code class="language-php">
#[ORM\Entity]
// No SoftDeleteable = physical delete is fine
class Category
{
    #[ORM\OneToMany(targetEntity: Post::class, mappedBy: 'category')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]  // ← Now it's consistent
    private Collection $posts;
}
</code></pre>

    <h3>Decision Matrix</h3>
    <table>
        <tr>
            <th>Scenario</th>
            <th>Solution</th>
        </tr>
        <tr>
            <td>Parent soft deleted → Children should be orphaned</td>
            <td>Use <code>onDelete: 'SET NULL'</code></td>
        </tr>
        <tr>
            <td>Parent soft deleted → Children should also soft delete</td>
            <td>Use ORM <code>cascade: ['remove']</code></td>
        </tr>
        <tr>
            <td>Parent physically deleted → Children should be deleted</td>
            <td>Remove SoftDeleteable, use <code>onDelete: 'CASCADE'</code></td>
        </tr>
        <tr>
            <td>Prevent parent deletion if children exist</td>
            <td>Use <code>onDelete: 'RESTRICT'</code></td>
        </tr>
    </table>

    <p><strong>Why this is CRITICAL:</strong> Permanent data loss • Broken soft delete pattern • Inconsistent behavior</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
