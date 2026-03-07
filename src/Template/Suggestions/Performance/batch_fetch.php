<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entity
 * @var mixed $relation
 * @var mixed $queryCount
 * @var mixed $context
 */
['entity' => $entity, 'relation' => $relation, 'query_count' => $queryCount, 'trigger_location' => $triggerLocation] = $context;

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Proxy N+1 Query Problem</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Proxy N+1 Query Detected</strong><br>
        Detected <strong><?php echo $queryCount; ?> sequential queries</strong> loading <code><?php echo $e($relation); ?></code> proxy entities.
        This happens when accessing a ManyToOne or OneToOne relation inside a loop where each proxy is lazily initialized.
    </div>

<?php if (null !== $triggerLocation && '' !== $triggerLocation): ?>
    <div class="alert alert-info">
        <strong>Triggered at:</strong> <code><?php echo $e($triggerLocation); ?></code>
    </div>
<?php endif; ?>

    <h4>Problem: Proxy Initialization in Loop</h4>
    <div class="query-item">
        <pre><code class="language-php">$entities = $repository->findAll();

foreach ($entities as $entity) {
    echo $entity->get<?php echo ucfirst((string) $relation); ?>()->getName(); // Query triggered here!
}
// Result: <?php echo $queryCount; ?> queries instead of 1</code></pre>
    </div>

    <h4>Solution: Fetch Join (join + addSelect)</h4>
    <div class="query-item">
        <pre><code class="language-php">public function findAllWith<?php echo ucfirst((string) $relation); ?>(): array
{
    return $this->createQueryBuilder('e')
        ->leftJoin('e.<?php echo $e($relation); ?>', 'r')
        ->addSelect('r')
        ->getQuery()
        ->getResult();
}
// Result: 1 query instead of <?php echo $queryCount; ?></code></pre>
    </div>
    <p>
        <code>addSelect('r')</code> after a <code>leftJoin()</code> turns it into a <strong>fetch join</strong>:
        Doctrine loads the related entity in the same SQL query, so no proxy is created.
    </p>
    <p>
        Without <code>addSelect()</code>, the JOIN only filters/conditions the query but does <strong>not</strong> hydrate the relation.
    </p>

    <div class="alert alert-info">
        <strong>Expected improvement:</strong><br>
        <ul>
            <li><strong>Current:</strong> <?php echo $queryCount; ?> queries (one per entity)</li>
            <li><strong>With fetch join:</strong> 1 query total</li>
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
        'Proxy N+1 on %s.%s - use a fetch join (join + addSelect) to load in a single query',
        $entity,
        $relation,
    ),
];
