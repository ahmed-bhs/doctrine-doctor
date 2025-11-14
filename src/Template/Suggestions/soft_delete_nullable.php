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
    <h3>Soft delete field must be nullable</h3>
    <div class="alert alert-danger">
        <code><?= $fieldName ?></code> is NOT NULL. This breaks soft delete functionality.
    </div>

    <p>Soft delete works like this: NULL = active entity, DateTime = deleted entity. If the field is NOT NULL, it must always have a value, meaning the entity is always deleted. This completely breaks the pattern.</p>

    <h3>Fix</h3>
    <pre><code class="language-php">use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $<?= $fieldName ?> = null;

    public function delete(): void
    {
        $this-><?= $fieldName ?> = new \DateTime();
    }

    public function restore(): void
    {
        $this-><?= $fieldName ?> = null;
    }

    public function isDeleted(): bool
    {
        return null !== $this-><?= $fieldName ?>;
    }
}</code></pre>

    <p>Make the field nullable so it can be NULL when the entity is active and have a timestamp when deleted.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
