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
    <h3>createdBy should be NOT NULL</h3>
    <p>
        <code><?= $e($fieldName) ?></code> (usually createdBy/author) should be NOT NULL. Every entity must have a creator for proper audit trail. Nullable createdBy makes it impossible to know who created the entity, which breaks accountability and compliance requirements.
    </p>

    <h3>Fix</h3>
    <pre><code class="language-php">use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?= $e(basename(str_replace('\\', '/', $entityClass))) . "\n" ?>
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $<?= $e($fieldName) ?>;

    public function __construct(User $<?= $e($fieldName) ?>)
    {
        $this-><?= $e($fieldName) ?> = $<?= $e($fieldName) ?>;
    }

    public function get<?= ucfirst($fieldName) ?>(): User
    {
        return $this-><?= $e($fieldName) ?>;
    }
}</code></pre>

    <p>Set in constructor to ensure it's always present. Don't add a public setter.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
