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

<div class="suggestion-header">
    <h4>Deprecated String Default: <?php echo $e($entityClass); ?></h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        Field <code><?php echo $e($fieldName); ?></code> uses the raw string
        <code><?php echo $e($defaultValue); ?></code> as its default.
        Doctrine ORM 3.6 deprecated string defaults on temporal columns and ORM 4.0 removes them.
        DBAL 4.4 introduced typed expressions as the replacement.
    </div>

    <h4>Before</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Column(options: ['default' => '<?php echo $e($defaultValue); ?>'])]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;</code></pre>
    </div>

    <h4>After</h4>
    <div class="query-item">
        <pre><code class="language-php">use Doctrine\DBAL\Schema\DefaultExpression\<?php echo $e($expressionName); ?>;

#[ORM\Column(options: ['default' => new <?php echo $e($expressionName); ?>()])]
private \DateTimeImmutable $<?php echo $e($fieldName); ?>;</code></pre>
    </div>

    <p>The generated DDL is unchanged, so no migration is required.
    The expression object renders the correct SQL per platform, which the hardcoded string did not.</p>

    <p>Setting the value in the constructor instead keeps the default in PHP and drops the
    database default entirely, which is often clearer for entities that are only written through the ORM.</p>
</div>

<?php
$html = (string) ob_get_clean();

return [
    'code' => $html,
    'description' => sprintf(
        'Replace the string default on %s::$%s with a %s instance',
        $entityClass,
        $fieldName,
        $expressionName,
    ),
];
