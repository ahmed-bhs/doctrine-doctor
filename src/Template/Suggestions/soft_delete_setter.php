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
    <h3>Why is this a problem?</h3>
    <p>
        Having a public setter on <code><?= $fieldName ?></code> breaks soft delete integrity:
    </p>
    <ul>
        <li>Soft delete timestamp should be managed by business logic</li>
        <li>Public setters allow bypassing soft delete controls</li>
        <li>This enables data manipulation and audit trail tampering</li>
        <li>Makes it possible to fake deletion/restoration times</li>
    </ul>

    <h3>Solution: Remove Public Setter, Use Methods</h3>
    <pre><code class="language-php">
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\\DateTimeImmutable $<?= $fieldName ?> = null;

    // GOOD: Business logic methods
    public function delete(): void
    {
        if ($this->isDeleted()) {
            throw new \LogicException('Already deleted');
        }
        $this-><?= $fieldName ?> = new \\DateTimeImmutable();
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

    public function get<?= ucfirst($fieldName) ?>(): ?\\DateTimeImmutable
    {
        return $this-><?= $fieldName ?>;
    }

    //  REMOVE THIS:
    // public function set<?= ucfirst($fieldName) ?>(?\\DateTime $date): void
    // {
    //     $this-><?= $fieldName ?> = $date;
    // }
}
</code></pre>

    <h3>Using Doctrine Extensions</h3>
    <p>Extensions handle this automatically with no public setters:</p>

    <pre><code class="language-php">
// Gedmo: Automatic soft delete
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[Gedmo\SoftDeleteable(fieldName: '<?= $fieldName ?>')]
class <?= basename(str_replace('\\', '/', $entityClass)) . "\n" ?>
{
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\\DateTimeImmutable $<?= $fieldName ?> = null;

    // No setters needed - Gedmo handles it
}

// Delete: $entityManager->remove($entity); $entityManager->flush();
// Restore: $entity->restore(); (if you add the method)
</code></pre>

    <p><strong>Benefits:</strong> Controlled access • Business logic validation • No tampering • Audit integrity</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
