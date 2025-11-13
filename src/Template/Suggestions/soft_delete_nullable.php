<?php

declare(strict_types=1);

/**
 * @var string $entityClass Entity class name
 * @var string $fieldName   Field name
 */

// Extract context variables
$entityClass = $context['entity_class'] ?? '';
$fieldName = $context['field_name'] ?? '';

// Escaping function
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-content">
    <h3>🚨 CRITICAL: Soft Delete Field MUST Be Nullable!</h3>
    <p>
        The field <code><?= $fieldName ?></code> is configured as <strong>NOT NULL</strong>, which breaks soft delete functionality:
    </p>
    <ul>
        <li><strong>NULL = Entity is NOT deleted</strong> (active)</li>
        <li><strong>DateTime = Entity IS deleted</strong> (soft deleted)</li>
        <li>NOT NULL means the field must always have a value → entity is ALWAYS deleted!</li>
        <li>This completely breaks the soft delete pattern</li>
    </ul>

    <h3>Solution: Make Field Nullable</h3>
    <pre><code class="language-php">
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    // CORRECT: nullable = true
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\\DateTime $<?= $fieldName ?> = null;

    public function delete(): void
    {
        // Soft delete: set the timestamp
        $this-><?= $fieldName ?> = new \\DateTime();
    }

    public function restore(): void
    {
        // Restore: set to NULL
        $this-><?= $fieldName ?> = null;
    }

    public function isDeleted(): bool
    {
        return null !== $this-><?= $fieldName ?>;
    }
}
</code></pre>

    <h3>Using Doctrine Extensions</h3>

    <pre><code class="language-php">
// Gedmo: Automatically nullable
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[Gedmo\SoftDeleteable(fieldName: '<?= $fieldName ?>', timeAware: false)]
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    #[ORM\Column(type: 'datetime', nullable: true)]  // ← Must be nullable
    private ?\\DateTime $<?= $fieldName ?> = null;
}

// KnpLabs: Automatically nullable
use Knp\DoctrineBehaviors\Contract\Entity\SoftDeletableInterface;
use Knp\DoctrineBehaviors\Model\SoftDeletable\SoftDeletableTrait;

#[ORM\Entity]
class <?= basename(str_replace('\\', '/', $entityClass)) ?> implements SoftDeletableInterface
{
    use SoftDeletableTrait;  // Provides nullable deletedAt
}
</code></pre>

    <h3>Migration</h3>
    <pre><code class="language-sql">
-- Make the column nullable and set all values to NULL
UPDATE <?= strtolower(basename(str_replace('\\', '/', $entityClass))) ?>
// Pattern: Simple pattern match: /(?<!^)[A-Z]/
SET <?= strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $fieldName)) ?> = NULL;

ALTER TABLE <?= strtolower(basename(str_replace('\\', '/', $entityClass))) ?>
// Pattern: Simple pattern match: /(?<!^)[A-Z]/
MODIFY COLUMN <?= strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $fieldName)) ?> DATETIME NULL;
</code></pre>

    <p><strong>Why this is CRITICAL:</strong> Data loss risk • Broken soft delete logic • All entities appear deleted</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
