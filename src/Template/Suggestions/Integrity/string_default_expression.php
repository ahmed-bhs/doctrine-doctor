<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$entityClass = (string) ($context['entity_class'] ?? 'Entity');
$fieldName = (string) ($context['field_name'] ?? 'createdAt');
$defaultValue = (string) ($context['default_value'] ?? 'CURRENT_TIMESTAMP');
$expressionName = (string) ($context['expression_name'] ?? 'CurrentTimestamp');

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Use a default expression object'); ?>

<div class="suggestion-content">
    <p><?php echo t('<code>%s::$%s</code> uses the raw string <code>%s</code> as its default. ORM 3.6 deprecates string defaults on temporal columns and ORM 4.0 removes them; DBAL 4.4 replaces them with expression objects.', $e($entityClass), $e($fieldName), $e($defaultValue)); ?></p>

    <div class="query-item">
        <pre><code class="language-php">// Before
#[ORM\Column(options: ['default' => '<?php echo $e($defaultValue); ?>'])]

// After: same DDL, no migration needed
use Doctrine\DBAL\Schema\DefaultExpression\<?php echo $e($expressionName); ?>;

#[ORM\Column(options: ['default' => new <?php echo $e($expressionName); ?>()])]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;</code></pre>
    </div>

    <p><?php echo t('For entities written only through the ORM, setting the value in the constructor and dropping the database default also works.'); ?></p>
</div>
<?php
$html = (string) ob_get_clean();

return [
    'code' => $html,
    'description' => t(
        'Replace the string default on %s::$%s with a %s instance',
        $entityClass,
        $fieldName,
        $expressionName,
    ),
];
