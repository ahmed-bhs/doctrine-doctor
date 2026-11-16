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
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Make the timestamp NOT NULL'); ?>

<div class="suggestion-content">
    <p><code><?= $e($entityClass) ?>::$<?= $e($fieldName) ?></code> is nullable, but a timestamp is always set when the row is written. NOT NULL lets the database reject rows that skipped it.</p>

    <div class="query-item">
        <pre><code class="language-php">// Before
#[ORM\Column(nullable: true)]
private ?\DateTimeImmutable $<?= $e($fieldName) ?>;

// After
#[ORM\Column]
#[Gedmo\Timestampable(on: 'create')]
private \DateTimeImmutable $<?= $e($fieldName) ?>;</code></pre>
    </div>

    <?php echo suggestionDocLink('https://github.com/doctrine-extensions/DoctrineExtensions/blob/main/doc/timestampable.md', 'Doctrine Extensions Timestampable'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Make %s::$%s NOT NULL', $entityClass, $fieldName),
];
