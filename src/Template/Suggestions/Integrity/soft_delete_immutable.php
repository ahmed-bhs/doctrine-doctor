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
    <h3>Use DateTimeImmutable for soft delete</h3>
    <p>
        <code><?= $fieldName ?></code> uses mutable <code>DateTime</code>. Use <code>DateTimeImmutable</code> instead to prevent accidental modifications to the deletion timestamp.
    </p>

    <h3>Fix</h3>
    <pre><code class="language-php">use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $<?= $fieldName ?> = null;

    public function delete(): void
    {
        $this-><?= $fieldName ?> = new \DateTimeImmutable();
    }

    public function restore(): void
    {
        $this-><?= $fieldName ?> = null;
    }
}</code></pre>

    <p>DateTimeImmutable is thread-safe and prevents accidental modifications. Once a deletion time is set, it should never change.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
