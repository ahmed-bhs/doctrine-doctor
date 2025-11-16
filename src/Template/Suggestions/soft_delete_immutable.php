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
    <h3>Why Use DateTimeImmutable for Soft Delete?</h3>
    <p>
        The field <code><?= $fieldName ?></code> uses mutable <code>DateTime</code>. Use <code>DateTimeImmutable</code> instead:
    </p>
    <ul>
        <li>Prevents accidental modifications to the deletion timestamp</li>
        <li>Thread-safe and more predictable</li>
        <li>Follows PHP best practices (PHP 8.1+)</li>
        <li>Deletion time should never change after being set</li>
    </ul>

    <h3>Solution: Use DateTimeImmutable</h3>
    <pre><code class="language-php">
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    // GOOD: DateTimeImmutable
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\\DateTimeImmutable $<?= $fieldName ?> = null;

    public function delete(): void
    {
        $this-><?= $fieldName ?> = new \\DateTimeImmutable();
    }

    public function restore(): void
    {
        $this-><?= $fieldName ?> = null;
    }
}
</code></pre>

    <p><strong>Benefits:</strong> Immutable • Thread-safe • No accidental modifications • PHP</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
