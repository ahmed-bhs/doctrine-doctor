<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entityClass
 * @var mixed $fieldName
 * @var mixed $context
 */
['entity_class' => $entityClass, 'field_name' => $fieldName] = $context;
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Mutable DateTime in timestamp field</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <code><?php echo $e($entityClass); ?>::$<?php echo $e($fieldName); ?></code> uses mutable DateTime. External code can modify it, breaking encapsulation.
    </div>

    <p>When you return a DateTime from a getter, external code can modify it without going through setters.</p>

    <h4>Current</h4>
    <pre><code class="language-php">#[ORM\Column(type: 'datetime')]
private \DateTime $<?php echo $e($fieldName); ?>;</code></pre>

    <h4>Solution: DateTimeImmutable</h4>
    <pre><code class="language-php">#[ORM\Column(type: 'datetime_immutable')]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;

// With Gedmo
#[Gedmo\Timestampable(on: 'create')]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;</code></pre>

    <p>No database migration needed - Doctrine handles both types the same way.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/cookbook/working-with-datetime.html" target="_blank" class="doc-link">
            📖 Doctrine datetime docs
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Replace DateTime with DateTimeImmutable',
];
