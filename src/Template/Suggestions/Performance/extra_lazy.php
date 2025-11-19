<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entity
 * @var mixed $relation
 * @var mixed $queryCount
 * @var mixed $hasLimit
 * @var mixed $context
 */
['entity' => $entity, 'relation' => $relation, 'query_count' => $queryCount, 'has_limit' => $hasLimit] = $context;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

// Start output buffering for clean code block
ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
        <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/>
    </svg>
    <h4>Suggested Fix: Extra Lazy Collections</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Collection N+1 Query Detected<?php echo $hasLimit ? ' (Partial Access)' : ''; ?></strong><br>
        Detected <strong><?php echo $queryCount; ?> sequential queries</strong> loading <code><?php echo $e($relation); ?></code> collection.
        <?php if ($hasLimit): ?>
            Your queries use LIMIT, suggesting you only need part of the collection.
        <?php else: ?>
            This happens when accessing a lazy-loaded collection inside a loop.
        <?php endif; ?>
    </div>

    <h4>Problem: Collection Loading in Loop</h4>
    <div class="query-item">
        <pre><code class="language-php">// BAD: Each collection access triggers a separate query
$entities = $repository->findAll();
Assert::isIterable($entities, '$entities must be iterable');

foreach ($entities as $entity) {
    <?php if ($hasLimit): ?>
    // Counting or partial access still loads entire collection
    echo count($entity->get<?php echo ucfirst($relation); ?>()); // Full collection loaded!
    <?php else: ?>
    foreach ($entity->get<?php echo ucfirst($relation); ?>() as $item) { // Collection loaded here!
        // Process items...
    }
    <?php endif; ?>
}
// Result: <?php echo $queryCount; ?> queries instead of 1<?php echo $hasLimit ? ' (even if you only need counts!)' : ''; ?></code></pre>
    </div>

    <?php if ($hasLimit): ?>
    <h4>Solution 1: EXTRA_LAZY (Recommended for Partial Access)</h4>
    <div class="query-item">
        <pre><code class="language-php">// BEST for counts, contains(), slice(), etc.
use Doctrine\ORM\Mapping as ORM;

#[ORM\OneToMany(mappedBy: 'parent', fetch: 'EXTRA_LAZY')]
private Collection $<?php echo $e($relation); ?>;

// Now these operations are optimized:
$entities = $repository->findAll();
Assert::isIterable($entities, '$entities must be iterable');

foreach ($entities as $entity) {
    // COUNT query instead of loading all items!
    echo count($entity->get<?php echo ucfirst($relation); ?>());

    // LIMIT query instead of loading all items!
    $first3 = $entity->get<?php echo ucfirst($relation); ?>()->slice(0, 3);

    // EXISTS query instead of loading all items!
    if ($entity->get<?php echo ucfirst($relation); ?>()->contains($someItem)) {
        // ...
    }
}
// Result: Optimized queries (COUNT, LIMIT, EXISTS)</code></pre>
    </div>
    <?php else: ?>
    <h4>Solution 1: EXTRA_LAZY (For Partial Access)</h4>
    <div class="query-item">
        <pre><code class="language-php">// GOOD if you only need counts or partial access
use Doctrine\ORM\Mapping as ORM;

#[ORM\OneToMany(mappedBy: 'parent', fetch: 'EXTRA_LAZY')]
private Collection $<?php echo $e($relation); ?>;

// Optimized for:
$count = $entity->get<?php echo ucfirst($relation); ?>()->count(); // COUNT query only
$first5 = $entity->get<?php echo ucfirst($relation); ?>()->slice(0, 5); // LIMIT query only</code></pre>
    </div>
    <?php endif; ?>

    <h4>Solution 2: JOIN FETCH (For Full Access)</h4>
    <div class="query-item">
        <pre><code class="language-php">// BEST if you need to iterate over ALL items
$entities = $entityManager
    ->createQuery('
        SELECT e, r
        FROM App\\Entity\\<?php echo $e($entity); ?> e
        LEFT JOIN e.<?php echo $e($relation); ?> r
    ')
    ->getResult();

Assert::isIterable($entities, '$entities must be iterable');

foreach ($entities as $entity) {
    foreach ($entity->get<?php echo ucfirst($relation); ?>() as $item) { // Already loaded!
        // Process items...
    }
}
// Result: 1 query total</code></pre>
    </div>

    <h4>Solution 3: Repository Method</h4>
    <div class="query-item">
        <pre><code class="language-php">// In your repository
/**
 * @return array<mixed>
 */
public function findAllWith<?php echo ucfirst($relation); ?>(): array
{
    return $this->createQueryBuilder('e')
        ->leftJoin('e.<?php echo $e($relation); ?>', 'r')
        ->addSelect('r')
        ->getQuery()
        ->getResult();
}</code></pre>
    </div>

    <h4>When to Use Each Solution</h4>
    <ul>
        <li><strong>EXTRA_LAZY</strong>: When you need count(), contains(), slice(), or rarely iterate full collection</li>
        <li><strong>JOIN FETCH</strong>: When you ALWAYS iterate over the entire collection</li>
        <li><strong>Repository Method</strong>: When eager loading is needed in specific use cases only</li>
    </ul>

    <h4>EXTRA_LAZY Benefits</h4>
    <ul>
        <li><code>count()</code>: Executes COUNT query instead of loading all items</li>
        <li><code>slice(0, 10)</code>: Executes LIMIT query for subset</li>
        <li><code>contains($item)</code>: Executes EXISTS query</li>
        <li><code>containsKey($key)</code>: Executes EXISTS query with key lookup</li>
        <li><code>isEmpty()</code>: Executes EXISTS query</li>
        <li><code>first()</code>: Executes LIMIT 1 query to get first element</li>
        <li><code>get($key)</code>: Executes query to fetch single element by key</li>
    </ul>

    <h4>⚖️ Trade-offs: EXTRA_LAZY</h4>
    <div class="alert alert-warning">
        <strong>Pros:</strong>
        <ul>
            <li><strong>Memory efficient</strong>: Only loads what you need</li>
            <li><strong>Optimized queries</strong>: COUNT/EXISTS/LIMIT instead of SELECT *</li>
            <li><strong>Great for large collections</strong>: Thousands of items? No problem</li>
            <li><strong>Perfect for partial access</strong>: count(), first(), slice()</li>
        </ul>
        <strong>Cons:</strong>
        <ul>
            <li><strong>N+1 problem remains</strong>: Multiple queries (but lightweight)</li>
            <li><strong>Not for full iteration</strong>: If you need all items, use JOIN FETCH</li>
            <li><strong>Slightly slower than eager</strong>: Multiple round-trips vs one JOIN</li>
        </ul>
    </div>

    <h4>Best Practices</h4>
    <ul>
        <li>Use EXTRA_LAZY for large collections where you need counts or partial access</li>
        <li>Use JOIN FETCH when you need to iterate over entire collection</li>
        <li>Avoid accessing collections in loops without optimization</li>
        <li>Monitor query patterns with Doctrine profiler</li>
    </ul>

    <div class="alert alert-info">
        ℹ️ <strong>Expected Performance Improvement:</strong><br>
        <ul>
            <li><strong>Current:</strong> <?php echo $queryCount; ?> queries<?php echo $hasLimit ? ' (loading partial data each time)' : ''; ?></li>
            <?php if ($hasLimit): ?>
            <li><strong>With EXTRA_LAZY:</strong> <?php echo $queryCount; ?> optimized COUNT/LIMIT queries (much faster)</li>
            <?php else: ?>
            <li><strong>With JOIN FETCH:</strong> 1 query (if full access needed)</li>
            <li><strong>With EXTRA_LAZY:</strong> <?php echo $queryCount; ?> COUNT queries (if only counting)</li>
            <?php endif; ?>
        </ul>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/tutorials/extra-lazy-associations.html" target="_blank" class="doc-link">
            📖 Doctrine Extra Lazy Associations Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Use EXTRA_LAZY fetch mode to optimize collection access for %s.%s',
        $entity,
        $relation,
    ),
];
