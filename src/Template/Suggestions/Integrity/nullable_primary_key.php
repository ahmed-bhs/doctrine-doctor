<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$entityClass = (string) ($context['entity_class'] ?? 'Entity');
$fieldName = (string) ($context['field_name'] ?? 'id');

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Remove nullable from the primary key'); ?>

<div class="suggestion-content">
    <p><?php echo t('<code>%s::$%s</code> is an identifier mapped with <code>nullable: true</code>. A primary key cannot hold NULL, so the flag never reaches the schema; ORM 3.6 deprecates it and ORM 4.0 rejects it.', $e($entityClass), $e($fieldName)); ?></p>

    <div class="query-item">
        <pre><code class="language-php">// Before
#[ORM\Id]
#[ORM\Column(nullable: true)]
private ?int $<?php echo $e($fieldName); ?> = null;

// After: the property stays nullable until the first flush
#[ORM\Id]
#[ORM\Column]
private ?int $<?php echo $e($fieldName); ?> = null;</code></pre>
    </div>

    <p><?php echo t('Only the mapping changes, so no migration is generated.'); ?></p>
</div>
<?php
$html = (string) ob_get_clean();

return [
    'code' => $html,
    'description' => t(
        'Remove nullable from the primary key %s::$%s',
        $entityClass,
        $fieldName,
    ),
];
