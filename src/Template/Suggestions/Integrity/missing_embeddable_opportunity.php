<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $entityClass
 * @var mixed $fields
 * @var array<string, mixed> $context
 */
$entityClass = (string) ($context['entity_class'] ?? 'Entity');
$fields = $context['fields'] ?? ['street', 'city'];
if (!is_array($fields) || [] === $fields) {
    $fields = ['street', 'city'];
}
$fields = array_values(array_map(static fn (mixed $field): string => (string) $field, $fields));
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Group related fields in an embeddable'); ?>

<div class="suggestion-content">
    <p><code><?= $e($entityClass) ?></code> has fields that belong together: <code><?= implode(', ', array_map(static fn (mixed $field): string => $e((string) $field), $fields)) ?></code>. An embeddable keeps them and their validation in one value object.</p>

    <div class="query-item">
        <pre><code class="language-php">#[ORM\Embeddable]
final class Address
{
    public function __construct(
<?php foreach ($fields as $field) { ?>
        #[ORM\Column] private string $<?= $e($field) ?>,
<?php } ?>
    ) {}
}

// <?= $e($entityClass) ?>

#[ORM\Embedded(class: Address::class)]
private Address $address;</code></pre>
    </div>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/tutorials/embeddables.html', 'Doctrine ORM Embeddables'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Group related fields of %s in an embeddable', $entityClass),
];
