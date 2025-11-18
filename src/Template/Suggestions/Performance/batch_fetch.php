<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entity
 * @var mixed $relation
 * @var mixed $queryCount
 * @var mixed $context
 */
['entity' => $entity, 'relation' => $relation, 'query_count' => $queryCount] = $context;

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
    <h4>Suggested Fix: Batch Fetching for Proxies</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Proxy N+1 Query Detected</strong><br>
        Detected <strong><?php echo $queryCount; ?> sequential queries</strong> loading <code><?php echo $e($relation); ?></code> proxy entities.
        This happens when accessing a ManyToOne or OneToOne relation inside a loop where each proxy is lazily initialized.
    </div>

    <h4>Problem: Proxy Initialization in Loop</h4>
    <div class="query-item">
        <pre><code class="language-php">// BAD: Each proxy initialization triggers a separate query
$entities = $repository->findAll();
Assert::isIterable($entities, '$entities must be iterable');

foreach ($entities as $entity) {
    echo $entity->get<?php echo ucfirst($relation); ?>()->getName(); // Proxy initialized here!
}
// Result: <?php echo $queryCount; ?> queries instead of 1</code></pre>
    </div>

    <h4>Solution 1: Batch Fetch Mode (Recommended for Proxies)</h4>
    <div class="query-item">
        <pre><code class="language-php">// BEST: Use BATCH fetch mode to load proxies in batches
// In your entity:
use Doctrine\ORM\Mapping as ORM;

#[ORM\ManyToOne(fetch: 'EXTRA_LAZY')]
#[ORM\BatchFetch(size: 10)]  // Loads proxies in batches of 10
private ?RelatedEntity $<?php echo $e($relation); ?> = null;

// Now when you access proxies in a loop:
$entities = $repository->findAll();
Assert::isIterable($entities, '$entities must be iterable');

foreach ($entities as $entity) {
    echo $entity->get<?php echo ucfirst($relation); ?>()->getName(); // Batched!
}
// Result: Approx <?php echo (int) ceil($queryCount / 10); ?> queries (10 proxies per query)</code></pre>
    </div>

    <h4>Solution 2: JOIN FETCH</h4>
    <div class="query-item">
        <pre><code class="language-php">// GOOD: Use JOIN FETCH for eager loading
$entities = $entityManager
    ->createQuery('
        SELECT e, r
        FROM App\\Entity\\<?php echo $e($entity); ?> e
        JOIN e.<?php echo $e($relation); ?> r
    ')
    ->getResult();

Assert::isIterable($entities, '$entities must be iterable');

foreach ($entities as $entity) {
    echo $entity->get<?php echo ucfirst($relation); ?>()->getName(); // Already loaded!
}
// Result: 1 query total</code></pre>
    </div>

    <h4>Solution 3: Repository Method with Query Builder</h4>
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
        <li><strong>Batch Fetch</strong>: Best for unpredictable access patterns, automatically optimizes</li>
        <li><strong>JOIN FETCH</strong>: Best when you ALWAYS need the relation (100% access rate)</li>
        <li><strong>EXTRA_LAZY</strong>: Best for collections, not typically for ManyToOne proxies</li>
    </ul>

    <h4>⚖️ Trade-offs: Batch Fetch</h4>
    <div class="alert alert-warning">
        <strong>Pros:</strong>
        <ul>
            <li><strong>Automatic optimization</strong>: Doctrine batches queries transparently</li>
            <li><strong>Flexible</strong>: Works even if not all proxies are accessed</li>
            <li><strong>Reduces queries significantly</strong>: N queries → N/batchSize queries</li>
            <li><strong>No code changes needed</strong>: Just add annotation, existing code works</li>
        </ul>
        <strong>Cons:</strong>
        <ul>
            <li><strong>Still multiple queries</strong>: Not as optimal as JOIN FETCH (1 query)</li>
            <li><strong>Batch size tuning</strong>: Need to find optimal size for your use case</li>
            <li><strong>Doctrine-specific</strong>: Won't work outside ORM context</li>
            <li> <strong>Less predictable</strong>: Query count depends on access pattern</li>
        </ul>
    </div>

    <h4>Best Practices</h4>
    <ul>
        <li>Prefer Batch Fetch for ManyToOne/OneToOne relations accessed in loops</li>
        <li>Use JOIN FETCH when relation access is guaranteed</li>
        <li>Avoid global <code>fetch: EAGER</code> as it loads relations everywhere</li>
        <li>Monitor with Doctrine profiler to verify optimization</li>
    </ul>

    <div class="alert alert-info">
        ℹ️ <strong>Expected Performance Improvement:</strong><br>
        <ul>
            <li><strong>Current:</strong> <?php echo $queryCount; ?> queries (one per entity)</li>
            <li><strong>With Batch Fetch (size=10):</strong> ~<?php echo (int) ceil($queryCount / 10); ?> queries</li>
            <li><strong>With JOIN FETCH:</strong> 1 query</li>
        </ul>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/annotations-reference.html#annref_batchfetch" target="_blank" class="doc-link">
            📖 Doctrine Batch Fetch Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Use Batch Fetch or JOIN FETCH to optimize proxy loading for %s.%s relation',
        $entity,
        $relation,
    ),
];
