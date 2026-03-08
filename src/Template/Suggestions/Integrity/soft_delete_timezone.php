<?php

declare(strict_types=1);

$entityClass = $context['entity_class'] ?? '';
$fieldName = $context['field_name'] ?? '';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
$shortClass = basename(str_replace('\\', '/', $entityClass));

ob_start();
?>
<div class="suggestion-header"><h4>Timezone for soft delete</h4></div>
<div class="suggestion-content">
<div class="alert alert-info"><code><?php echo $e($fieldName); ?></code> uses <code>datetime</code> without timezone information.</div>

<h4>Fix</h4>
<div class="query-item"><pre><code class="language-php">use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?php echo $e($shortClass); ?>

{
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $<?php echo $e($fieldName); ?> = null;

    public function delete(): void
    {
        $this-><?php echo $e($fieldName); ?> = new \DateTimeImmutable();
    }
}</code></pre></div>

<p>Use <code>datetimetz_immutable</code> for audit fields in multi-timezone applications.</p>

<p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/basic-mapping.html#doctrine-mapping-types" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine ORM Mapping Types</a></p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Use datetimetz_immutable for the soft-delete field %s::%s', $shortClass, $fieldName),
];
