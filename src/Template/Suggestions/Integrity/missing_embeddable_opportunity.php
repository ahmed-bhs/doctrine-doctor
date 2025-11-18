<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entityClass
 * @var mixed $fields
 * @var mixed $context
 */
['entity_class' => $entityClass, 'fields' => $fields] = $context;
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Consider extracting an embeddable</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-info">
        <code><?= $e($entityClass) ?></code> has related fields that could be grouped into an embeddable: <code><?= implode(', ', array_map($e, $fields)) ?></code>
    </div>

    <p>When you have several fields that belong together conceptually (like address fields or money amounts), grouping them into an embeddable value object can make your code clearer.</p>

    <h4>Example refactoring</h4>
    <div class="query-item">
        <pre><code class="language-php">// Before: separate fields
class <?= $e($entityClass) ?> {
<?php foreach ($fields as $field): ?>
    private string $<?= $e($field) ?>;
<?php endforeach; ?>
}

// After: grouped as value object
#[ORM\Embeddable]
readonly class Address {
    public function __construct(
<?php foreach ($fields as $i => $field): ?>
        private string $<?= $e($field) ?><?= $i < count($fields) - 1 ? ',' : '' ?>

<?php endforeach; ?>
    ) {}
}

class <?= $e($entityClass) ?> {
    #[ORM\Embedded(class: Address::class)]
    private Address $address;
}</code></pre>
    </div>

    <p><strong>Benefits:</strong> Better encapsulation, reusability, validation in one place.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Extract related fields into an Embeddable Value Object',
];
