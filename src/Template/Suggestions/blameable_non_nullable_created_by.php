<?php

declare(strict_types=1);

/**
 * @var string $entity_class Entity class name
 * @var string $field_name   Field name
 */

// Extract context
$entityClass = $context['entity_class'] ?? '';
$fieldName = $context['field_name'] ?? '';

// Escaping function
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-content">
    <h3>Why is this important?</h3>
    <p>
        The <code><?= $e($fieldName) ?></code> field (usually createdBy/author) should be <strong>NOT NULL</strong> because:
    </p>
    <ul>
        <li>Every entity must have a creator for proper audit trailing</li>
        <li>Nullable createdBy makes it impossible to know who created the entity</li>
        <li>This breaks accountability and audit requirements (GDPR, compliance)</li>
        <li>Once set during creation, this field should never be null</li>
    </ul>

    <h3>Solution 1: Using Doctrine Attributes</h3>
    <pre><code class="language-php">
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?= $e(basename(str_replace('\\', '/', $entityClass))) . "\n" ?>
{
    // GOOD: NOT nullable
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]  // ← Make it NOT NULL
    private ?User $<?= $e($fieldName) ?> = null;

    public function __construct(User $<?= $e($fieldName) ?>)
    {
        // Set in constructor to ensure it's always present
        $this-><?= $field_name ?> = $<?= $e($fieldName) ?>;
    }

    public function get<?= ucfirst($field_name) ?>(): User
    {
        return $this-><?= $field_name ?>;
    }

    //  DO NOT add a public setter
}
</code></pre>

    <h3>Solution 2: Using gedmo/doctrine-extensions</h3>
    <pre><code class="language-php">
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?= $e(basename(str_replace('\\', '/', $entityClass))) . "\n" ?>
{
    #[Gedmo\Blameable(on: 'create')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]  // ← NOT NULL
    private ?User $<?= $e($fieldName) ?> = null;
}
</code></pre>

    <h3>Solution 3: Using knplabs/doctrine-behaviors</h3>
    <pre><code class="language-php">
use Knp\DoctrineBehaviors\Contract\Entity\BlameableInterface;
use Knp\DoctrineBehaviors\Model\Blameable\BlameableTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?= $e(basename(str_replace('\\', '/', $entityClass))) ?> implements BlameableInterface
{
    use BlameableTrait;  // Provides createdBy with NOT NULL by default

    // Trait automatically adds:
    // - createdBy (NOT nullable)
    // - updatedBy (nullable - can be null if never updated)
}
</code></pre>

    <h3>Migration</h3>
    <pre><code class="language-sql">
-- Make the column NOT NULL
-- First ensure all existing rows have a value!
UPDATE <?= $e(strtolower(basename(str_replace('\\', '/', $entityClass)))) ?>
SET <?= $e(strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $fieldName))) ?>_id = 1
WHERE <?= $e(strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $fieldName))) ?>_id IS NULL;

ALTER TABLE <?= $e(strtolower(basename(str_replace('\\', '/', $entityClass)))) ?>
MODIFY COLUMN <?= $e(strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $fieldName))) ?>_id INT NOT NULL;
</code></pre>

    <p><strong>Benefits:</strong> Proper audit trail • GDPR compliance • Data integrity • No orphaned entities</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
