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
    <h3>Public setter on soft delete field</h3>
    <div class="alert alert-warning">
        <code><?= $fieldName ?></code> has a public setter, allowing direct manipulation of the deletion timestamp.
    </div>

    <p>Soft delete should be managed through business logic methods.</p>

    <h3>Fix</h3>
    <pre><code class="language-php">class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $<?= $fieldName ?> = null;

    public function delete(): void
    {
        if ($this->isDeleted()) {
            throw new \LogicException('Already deleted');
        }
        $this-><?= $fieldName ?> = new \DateTimeImmutable();
    }

    public function restore(): void
    {
        if (!$this->isDeleted()) {
            throw new \LogicException('Not deleted');
        }
        $this-><?= $fieldName ?> = null;
    }

    public function isDeleted(): bool
    {
        return null !== $this-><?= $fieldName ?>;
    }

    public function get<?= ucfirst($fieldName) ?>(): ?\DateTimeImmutable
    {
        return $this-><?= $fieldName ?>;
    }
}</code></pre>

    <p>Use <code>delete()</code> and <code>restore()</code> methods instead of a setter.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
