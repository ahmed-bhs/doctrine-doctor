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
    <h3>Timezone for soft delete</h3>
    <p>
        <code><?= $fieldName ?></code> uses <code>datetime</code> without timezone information.
    </p>

    <h3>Fix</h3>
    <pre><code class="language-php">use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $<?= $fieldName ?> = null;

    public function delete(): void
    {
        $this-><?= $fieldName ?> = new \DateTimeImmutable();
    }
}</code></pre>

    <p>Use <code>datetimetz_immutable</code> for audit fields in multi-timezone applications.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
