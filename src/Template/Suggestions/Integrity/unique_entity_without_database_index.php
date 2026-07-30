<?php

declare(strict_types=1);

/**
 * Template for UniqueEntity without database UNIQUE index suggestions.
 * Context variables:
 * @var string $entity_class - Short entity class name
 * @var string $entity_fqcn - Fully qualified entity class name
 * @var array $fields - Field names from #[UniqueEntity]
 * @var array $columns - Database column names
 * @var bool $is_single_column - Whether it's a single column constraint
 */

/** @var array<string, mixed> $context PHPStan: Template context */
$entityClass = $context['entity_class'] ?? 'Entity';
$fields = $context['fields'] ?? [];
$columns = $context['columns'] ?? [];
$isSingleColumn = $context['is_single_column'] ?? false;

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Missing database UNIQUE index</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <code>#[UniqueEntity]</code> only validates at the PHP level. Without a database UNIQUE index, concurrent requests can insert duplicate rows.
    </div>

<?php if ($isSingleColumn && 1 === count($fields)): ?>
    <h4>Option 1: Add <code>unique: true</code> to the column</h4>
    <div class="query-item">
        <pre><code class="language-php">#[UniqueEntity(fields: ['<?php echo $e($fields[0]); ?>'])]
#[ORM\Entity]
class <?php echo $e($entityClass); ?>
{
    #[ORM\Column(type: 'string', unique: true)]
    private string $<?php echo $e($fields[0]); ?>;
}</code></pre>
    </div>

    <h4>Option 2: Add a <code>#[UniqueConstraint]</code></h4>
    <div class="query-item">
        <pre><code class="language-php">#[UniqueEntity(fields: ['<?php echo $e($fields[0]); ?>'])]
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['<?php echo $e($columns[0] ?? $fields[0]); ?>'])]
class <?php echo $e($entityClass); ?>
{
}</code></pre>
    </div>
<?php else: ?>
    <h4>Add a <code>#[UniqueConstraint]</code> for the composite fields</h4>
    <div class="query-item">
        <pre><code class="language-php">#[UniqueEntity(fields: [<?php echo implode(', ', array_map(fn (string $f): string => "'" . $e($f) . "'", $fields)); ?>])]
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: [<?php echo implode(', ', array_map(fn (string $c): string => "'" . $e($c) . "'", $columns)); ?>])]
class <?php echo $e($entityClass); ?>
{
}</code></pre>
    </div>
<?php endif; ?>

    <p>Then generate and run a migration:</p>
    <div class="query-item">
        <pre><code class="language-bash">php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate</code></pre>
    </div>

    <p>
        <a href="https://symfony.com/doc/current/reference/constraints/UniqueEntity.html" target="_blank" class="doc-link">
            Symfony UniqueEntity documentation
        </a>
        &nbsp;|&nbsp;
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/attributes-reference.html#uniqueconstraint" target="_blank" class="doc-link">
            Doctrine UniqueConstraint reference
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

$fieldList = implode(', ', $fields);

return [
    'code'        => $code,
    'description' => sprintf(
        'Add a database UNIQUE index on %s for %s to prevent race condition duplicates',
        $fieldList,
        $entityClass,
    ),
];
