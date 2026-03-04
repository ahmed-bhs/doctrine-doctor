<?php

declare(strict_types=1);

$entityClass = $context['entity_class'] ?? '';
$fieldName = $context['field_name'] ?? '';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$shortClass = basename(str_replace('\\', '/', $entityClass));

ob_start();
?>
<div class="suggestion-header"><h4>Public setter on soft delete field</h4></div>
<div class="suggestion-content">
<div class="alert alert-warning"><code><?php echo $e($fieldName); ?></code> has a public setter, allowing direct manipulation of the deletion timestamp.</div>

<p>Soft delete should be managed through business logic methods.</p>

<h4>Fix</h4>
<div class="query-item"><pre><code class="language-php">class <?php echo $e($shortClass); ?>

{
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $<?php echo $e($fieldName); ?> = null;

    public function delete(): void
    {
        if ($this->isDeleted()) {
            throw new \LogicException('Already deleted');
        }
        $this-><?php echo $e($fieldName); ?> = new \DateTimeImmutable();
    }

    public function restore(): void
    {
        if (!$this->isDeleted()) {
            throw new \LogicException('Not deleted');
        }
        $this-><?php echo $e($fieldName); ?> = null;
    }

    public function isDeleted(): bool
    {
        return null !== $this-><?php echo $e($fieldName); ?>;
    }

    public function get<?php echo ucfirst($fieldName); ?>(): ?\DateTimeImmutable
    {
        return $this-><?php echo $e($fieldName); ?>;
    }
}</code></pre></div>

<p>Use <code>delete()</code> and <code>restore()</code> methods instead of a setter.</p>

<p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/basic-mapping.html#doctrine-mapping-types" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine ORM Mapping Types</a></p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Remove public setter on soft delete field %s::%s', $shortClass, $fieldName),
];
