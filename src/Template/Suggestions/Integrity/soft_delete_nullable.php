<?php

declare(strict_types=1);

$entityClass = $context['entity_class'] ?? '';
$fieldName = $context['field_name'] ?? '';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$shortClass = basename(str_replace('\\', '/', $entityClass));

ob_start();
?>
<div class="suggestion-header"><h4>Soft delete field must be nullable</h4></div>
<div class="suggestion-content">
<div class="alert alert-danger"><code><?php echo $e($fieldName); ?></code> is NOT NULL. This breaks soft delete functionality.</div>

<p>Soft delete works like this: NULL = active entity, DateTime = deleted entity. If the field is NOT NULL, the entity is always considered deleted.</p>

<h4>Fix</h4>
<div class="query-item"><pre><code class="language-php">use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?php echo $e($shortClass); ?>

{
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $<?php echo $e($fieldName); ?> = null;

    public function delete(): void
    {
        $this-><?php echo $e($fieldName); ?> = new \DateTime();
    }

    public function restore(): void
    {
        $this-><?php echo $e($fieldName); ?> = null;
    }

    public function isDeleted(): bool
    {
        return null !== $this-><?php echo $e($fieldName); ?>;
    }
}</code></pre></div>

<p>Make the field nullable so it can be NULL when the entity is active.</p>

<p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/basic-mapping.html#doctrine-mapping-types" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine ORM Mapping Types</a></p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Make soft delete field %s::%s nullable', $shortClass, $fieldName),
];
