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
    <h3>Public setter on blameable field</h3>
    <div class="alert alert-warning">
        <code><?= $fieldName ?></code> has a public setter, allowing the audit field to be changed.
    </div>

    <p>Blameable fields should be set once and immutable.</p>

    <h3>Fix</h3>
    <pre><code class="language-php">class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $<?= $fieldName ?>;

    public function __construct(User $<?= $fieldName ?>)
    {
        $this-><?= $fieldName ?> = $<?= $fieldName ?>;
    }

    public function get<?= ucfirst($fieldName) ?>(): User
    {
        return $this-><?= $fieldName ?>;
    }
}</code></pre>

    <p>Remove the setter. Set in constructor.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
