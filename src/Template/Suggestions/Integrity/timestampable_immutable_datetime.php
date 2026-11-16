<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var string $entityClass
 * @var mixed $fieldName
 * @var array<string, mixed> $context
 */
$entityClass = (string) ($context['entity_class'] ?? 'Entity');
$fieldName   = (string) ($context['field_name'] ?? 'created_at');
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Use DateTimeImmutable for the timestamp'); ?>

<div class="suggestion-content">
    <p><code><?php echo $e($entityClass); ?>::$<?php echo $e($fieldName); ?></code> holds a mutable <code>DateTime</code>. A caller that gets it from the getter can modify it in place, and Doctrine does not detect that change.</p>

    <div class="query-item">
        <pre><code class="language-php">// Before
#[ORM\Column(type: Types::DATETIME_MUTABLE)]
private \DateTime $<?php echo $e($fieldName); ?>;

// After: same column type, no migration needed
#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;</code></pre>
    </div>

    <?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/cookbook/working-with-datetime.html', 'Doctrine datetime docs'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Use DateTimeImmutable for %s::$%s', $entityClass, $fieldName),
];
