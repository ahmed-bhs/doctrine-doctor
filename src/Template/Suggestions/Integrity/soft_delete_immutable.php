<?php

declare(strict_types=1);

$entityClass = $context['entity_class'] ?? '';
$fieldName = $context['field_name'] ?? '';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$shortClass = basename(str_replace('\\', '/', $entityClass));

ob_start();
?>
<div class="suggestion-header"><h4>Use DateTimeImmutable for soft delete</h4></div>
<div class="suggestion-content">
<div class="alert alert-warning"><code><?php echo $e($fieldName); ?></code> uses mutable <code>DateTime</code>. Use <code>DateTimeImmutable</code> instead.</div>

<p>DateTimeImmutable prevents accidental modifications to the deletion timestamp.</p>

<h4>Fix</h4>
<div class="query-item"><pre><code class="language-php">use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?php echo $e($shortClass); ?>

{
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $<?php echo $e($fieldName); ?> = null;

    public function delete(): void
    {
        $this-><?php echo $e($fieldName); ?> = new \DateTimeImmutable();
    }

    public function restore(): void
    {
        $this-><?php echo $e($fieldName); ?> = null;
    }
}</code></pre></div>

<p>DateTimeImmutable is thread-safe. Once a deletion time is set, it should never change.</p>

<p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/basic-mapping.html#doctrine-mapping-types" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine ORM Mapping Types</a></p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Use DateTimeImmutable for soft delete field %s::%s', $shortClass, $fieldName),
];
