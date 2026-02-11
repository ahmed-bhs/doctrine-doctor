<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $context
 */
['parent_entity' => $parentEntity, 'collection_field' => $collectionField, 'child_entity' => $childEntity, 'query_count' => $queryCount, 'trigger_location' => $triggerLocation] = $context;

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

$lcCollectionField = lcfirst((string) $collectionField);

ob_start();
?>

<div class="suggestion-header">
    <h4>Collection N+1 Query Problem</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong><?php echo $queryCount; ?> queries</strong> loading the <code><?php echo $e($parentEntity); ?>::$<?php echo $e($collectionField); ?></code> collection (<?php echo $e($childEntity); ?> entities).
        Each access to this collection triggers a separate SQL query.
    </div>

<?php if (null !== $triggerLocation && '' !== $triggerLocation): ?>
    <div class="alert alert-info">
        <strong>Triggered at:</strong> <code><?php echo $e($triggerLocation); ?></code>
    </div>
<?php endif; ?>

    <h4>Root Cause</h4>
    <p>
        The <code><?php echo $e($parentEntity); ?></code> entity has a OneToMany/ManyToMany collection <code>$<?php echo $e($collectionField); ?></code>
        that is lazily loaded. When your query loads <code><?php echo $e($parentEntity); ?></code> entities without selecting this collection,
        each subsequent access triggers an individual query.
    </p>

    <h4>Solution 1: Add <code>addSelect()</code> to your query (Recommended)</h4>
    <div class="query-item">
        <pre><code class="language-php">$qb = $repository->createQueryBuilder('e')
    ->leftJoin('e.<?php echo $e($lcCollectionField); ?>', '<?php echo $e(substr($lcCollectionField, 0, 1)); ?>')
    ->addSelect('<?php echo $e(substr($lcCollectionField, 0, 1)); ?>')
    ->getQuery()
    ->getResult();
// 1 query instead of <?php echo $queryCount; ?></code></pre>
    </div>
    <p>If you already have a <code>join()</code> or <code>leftJoin()</code> on this collection in your query, simply add <code>->addSelect('alias')</code> to hydrate it eagerly.</p>

    <h4>Solution 2: EXTRA_LAZY fetch mode</h4>
    <div class="query-item">
        <pre><code class="language-php">// In <?php echo $e($parentEntity); ?> entity:
#[ORM\OneToMany(targetEntity: <?php echo $e($childEntity); ?>::class, mappedBy: '...', fetch: 'EXTRA_LAZY')]
private Collection $<?php echo $e($collectionField); ?>;</code></pre>
    </div>
    <p>Use this if you only call <code>count()</code>, <code>contains()</code>, or <code>slice()</code> on the collection, without iterating all elements.</p>

    <h4>When to Use Each Solution</h4>
    <ul>
        <li><strong>addSelect()</strong>: Best when you need the full collection data (iterating, displaying)</li>
        <li><strong>EXTRA_LAZY</strong>: Best when you only check <code>count()</code>/<code>isEmpty()</code> without loading all items</li>
    </ul>

    <div class="alert alert-info">
        <strong>Expected improvement:</strong><br>
        <ul>
            <li><strong>Current:</strong> <?php echo $queryCount; ?> queries (one per <?php echo $e($parentEntity); ?> entity)</li>
            <li><strong>With addSelect():</strong> 1 query total</li>
            <li><strong>With EXTRA_LAZY:</strong> 1 lightweight COUNT query per access</li>
        </ul>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#joins" target="_blank" class="doc-link">
            Doctrine DQL Joins Documentation
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Collection N+1 on %s::$%s - add addSelect() to your query or use EXTRA_LAZY fetch mode',
        $parentEntity,
        $collectionField,
    ),
];
