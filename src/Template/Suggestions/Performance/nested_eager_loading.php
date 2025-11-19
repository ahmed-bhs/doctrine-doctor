<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $entities
 * @var mixed $depth
 * @var mixed $queryCount
 * @var mixed $chain
 * @var mixed $context
 */
['entities' => $entities, 'depth' => $depth, 'query_count' => $queryCount, 'chain' => $chain] = $context;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

// Start output buffering for clean code block
ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
    </svg>
    <h4>Suggested Fix: Resolve Nested Relationship N+1</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>Nested N+1 Query Detected</strong><br>
        Found <strong><?php echo $queryCount; ?> queries</strong> across a <strong><?php echo $depth; ?>-level relationship chain</strong>: <code><?php echo $e($chain); ?></code>
        <br><br>
        <strong>Total Query Impact:</strong> <?php echo $queryCount * $depth; ?> queries!
    </div>

    <h4>Problem: Multi-Level Relationship Access in Loop</h4>
    <div class="query-item">
        <pre><code class="language-php">// BAD: Nested relationship access causes exponential queries
$<?php echo lcfirst($entities[0]); ?>s = $repository->findAll();

foreach ($<?php echo lcfirst($entities[0]); ?>s as $<?php echo lcfirst($entities[0]); ?>) {
    // Level 1: Accessing <?php echo $entities[1] ?? 'relation'; ?>

    echo $<?php echo lcfirst($entities[0]); ?>->get<?php echo $entities[1] ?? 'Relation'; ?>()
<?php if (isset($entities[2])): ?>
        // Level 2: Accessing <?php echo $entities[2]; ?> through <?php echo $entities[1]; ?>

        ->get<?php echo $entities[2]; ?>()
<?php endif; ?>
<?php if (isset($entities[3])): ?>
        // Level 3: Accessing <?php echo $entities[3]; ?> through <?php echo $entities[2]; ?>

        ->get<?php echo $entities[3]; ?>()
<?php endif; ?>
        ->getName();
}

// Result for 100 <?php echo lcfirst($entities[0]); ?>s:
// - 100 queries for <?php echo $entities[1] ?? 'relations'; ?>

<?php if (isset($entities[2])): ?>
// - 100 queries for <?php echo $entities[2]; ?>

<?php endif; ?>
<?php if (isset($entities[3])): ?>
// - 100 queries for <?php echo $entities[3]; ?>

<?php endif; ?>
// - Total: <?php echo $queryCount * $depth; ?> queries!</code></pre>
    </div>

    <h4>Solution 1: Multi-Level JOIN FETCH Best</h4>
    <div class="query-item">
        <pre><code class="language-php">// GOOD: Eager load entire chain with nested JOINs
$query = $em->createQuery('
    SELECT <?php echo strtolower($entities[0][0]); ?><?php foreach (array_slice($entities, 1) as $i => $entity): ?>, <?php echo strtolower($entity[0]); ?><?php endforeach; ?>

    FROM App\Entity\<?php echo $e($entities[0]); ?> <?php echo strtolower($entities[0][0]); ?>

    <?php foreach (array_slice($entities, 1) as $i => $entity): ?>
LEFT JOIN <?php echo strtolower($entities[$i][0]); ?>.<?php echo lcfirst($entity); ?> <?php echo strtolower($entity[0]); ?>

    <?php endforeach; ?>
');

$<?php echo lcfirst($entities[0]); ?>s = $query->getResult();

// Now access the chain without any extra queries:
foreach ($<?php echo lcfirst($entities[0]); ?>s as $<?php echo lcfirst($entities[0]); ?>) {
    echo $<?php echo lcfirst($entities[0]); ?>->get<?php echo $entities[1] ?? 'Relation'; ?>()
<?php if (isset($entities[2])): ?>
        ->get<?php echo $entities[2]; ?>()
<?php endif; ?>
<?php if (isset($entities[3])): ?>
        ->get<?php echo $entities[3]; ?>()
<?php endif; ?>
        ->getName(); // No queries!
}

// Result: 1 query total!</code></pre>
    </div>

    <h4>Solution 2: Query Builder Method</h4>
    <div class="query-item">
        <pre><code class="language-php">// In <?php echo $e($entities[0]); ?>Repository
/**
 * @return array<<?php echo $e($entities[0]); ?>>
 */
public function findAllWithNested<?php echo $entities[1] ?? 'Relations'; ?>(): array
{
    return $this->createQueryBuilder('<?php echo strtolower($entities[0][0]); ?>')
<?php foreach (array_slice($entities, 1) as $i => $entity): ?>
        ->leftJoin('<?php echo strtolower($entities[$i][0]); ?>.<?php echo lcfirst($entity); ?>', '<?php echo strtolower($entity[0]); ?>')
        ->addSelect('<?php echo strtolower($entity[0]); ?>')
<?php endforeach; ?>
        ->getQuery()
        ->getResult();
}

// Usage:
$<?php echo lcfirst($entities[0]); ?>s = $repository->findAllWithNested<?php echo $entities[1] ?? 'Relations'; ?>();
// Result: 1 query with all nested relations loaded!</code></pre>
    </div>

    <h4>Solution 3: Batch Fetch for Nested Relations</h4>
    <div class="query-item">
        <pre><code class="language-php">// For unpredictable access patterns, use Batch Fetch on all levels
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class <?php echo $e($entities[0]); ?>

{
    #[ORM\ManyToOne]
    #[ORM\BatchFetch(size: 10)]  // Batch at level 1
    private ?<?php echo $e($entities[1] ?? 'Relation'); ?> $<?php echo lcfirst($entities[1] ?? 'relation'); ?> = null;
}

<?php if (isset($entities[1])): ?>
#[ORM\Entity]
class <?php echo $e($entities[1]); ?>

{
    #[ORM\ManyToOne]
    #[ORM\BatchFetch(size: 10)]  // Batch at level 2
    private ?<?php echo $e($entities[2] ?? 'Relation'); ?> $<?php echo lcfirst($entities[2] ?? 'relation'); ?> = null;
}
<?php endif; ?>

// Now queries are batched at each level:
// 100 <?php echo lcfirst($entities[0]); ?>s → ~10 queries for level 1, ~10 for level 2
// Total: ~<?php echo (int) ceil($queryCount / 10) * $depth; ?> queries instead of <?php echo $queryCount * $depth; ?>!</code></pre>
    </div>

    <h4>Solution 4: Use DTOs for Deep Chains</h4>
    <div class="query-item">
        <pre><code class="language-php">// BEST for read-only data: Use custom DTO with single query
$results = $em->createQuery('
    SELECT NEW App\DTO\<?php echo $e($entities[0]); ?>DTO(
        <?php echo strtolower($entities[0][0]); ?>.id,
        <?php echo strtolower($entities[0][0]); ?>.title,
        <?php echo isset($entities[1]) ? strtolower($entities[1][0]) : 'r'; ?>.name,
        <?php echo isset($entities[2]) ? strtolower($entities[2][0]) : 'c'; ?>.name
    )
    FROM App\Entity\<?php echo $e($entities[0]); ?> <?php echo strtolower($entities[0][0]); ?>

    <?php foreach (array_slice($entities, 1) as $i => $entity): ?>
LEFT JOIN <?php echo strtolower($entities[$i][0]); ?>.<?php echo lcfirst($entity); ?> <?php echo strtolower($entity[0]); ?>

    <?php endforeach; ?>
')->getResult();

// Result: 1 query, scalar data, no hydration overhead!
// Perfect for APIs and read-only display</code></pre>
    </div>

    <h4>⚠️ Why Nested N+1 Is Especially Bad</h4>
    <div class="alert alert-danger">
        <strong>Exponential Query Growth:</strong>
        <ul>
            <li>🔥 <strong>Multiplies at each level</strong>: <?php echo $depth; ?> levels × <?php echo $queryCount; ?> queries = <?php echo $queryCount * $depth; ?> total queries</li>
            <li>⏱️ <strong>Latency compounds</strong>: Each level adds network round-trips</li>
            <li>🐘 <strong>Database strain</strong>: <?php echo $queryCount * $depth; ?> queries vs 1 with eager loading</li>
            <li>📈 <strong>Scales terribly</strong>: With 1000 entities → <?php echo 1000 * $depth; ?> queries!</li>
        </ul>
    </div>

    <h4>Performance Impact Visualization</h4>
    <div class="alert alert-info">
        <strong>Query Count Comparison:</strong>
        <table style="width: 100%; margin-top: 10px;">
            <tr style="background: #f5f5f5;">
                <th style="padding: 8px;">Scenario</th>
                <th style="padding: 8px;">Queries</th>
                <th style="padding: 8px;">Time (est.)</th>
            </tr>
            <tr>
                <td style="padding: 8px;">Current (nested N+1)</td>
                <td style="padding: 8px;"><strong><?php echo $queryCount * $depth; ?></strong></td>
                <td style="padding: 8px;"><?php echo number_format(($queryCount * $depth) * 2, 0); ?>ms</td>
            </tr>
            <tr>
                <td style="padding: 8px;">With multi-level JOIN</td>
                <td style="padding: 8px;"><strong>1</strong></td>
                <td style="padding: 8px;">5-10ms</td>
            </tr>
            <tr>
                <td style="padding: 8px;">⚡ Improvement</td>
                <td style="padding: 8px;"><strong><?php echo round((($queryCount * $depth - 1) / ($queryCount * $depth)) * 100); ?>%</strong></td>
                <td style="padding: 8px;"><?php echo round((($queryCount * $depth) * 2 - 7.5) / (($queryCount * $depth) * 2) * 100); ?>% faster</td>
            </tr>
        </table>
    </div>

    <h4>Best Practices for Nested Relations</h4>
    <ul>
        <li><strong>Always eager load chains</strong>: Use multi-level JOINs for nested access</li>
        <li><strong>Limit depth</strong>: If you need 4+ levels, consider denormalization or DTOs</li>
        <li><strong>Profile carefully</strong>: Use Doctrine profiler to catch nested patterns</li>
        <li><strong>Repository methods</strong>: Create specific finder methods for common access patterns</li>
        <li><strong>Consider architecture</strong>: Deep nesting may indicate domain model issues</li>
    </ul>

    <h4>When to Use Each Solution</h4>
    <ul>
        <li><strong>Multi-level JOIN:</strong> You ALWAYS access all levels (100% usage)</li>
        <li><strong>Batch Fetch:</strong> Access is conditional or varies by entity</li>
        <li><strong>DTOs:</strong> Read-only display, API responses, reporting</li>
        <li><strong>Refactor:</strong> 4+ levels deep suggests design smell</li>
    </ul>

    <div class="alert alert-info">
        ℹ️ <strong>Expected Performance Improvement:</strong><br>
        <ul>
            <li><strong>Current:</strong> <?php echo $queryCount * $depth; ?> queries (<?php echo $depth; ?> levels × <?php echo $queryCount; ?> per level)</li>
            <li><strong>With solution:</strong> 1 query (multi-level JOIN) or ~<?php echo (int) ceil($queryCount / 10) * $depth; ?> queries (batch)</li>
            <li><strong>Time saved:</strong> ~<?php echo number_format((($queryCount * $depth) - 1) * 2, 0); ?>ms (assuming 2ms/query)</li>
            <li><strong>Scalability:</strong> O(1) vs O(n×m) where n=entities, m=depth</li>
        </ul>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#joins" target="_blank" class="doc-link">
            📖 Doctrine Multi-Level JOIN Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Use multi-level eager loading to eliminate %d nested N+1 queries across %d-level chain: %s',
        $queryCount * $depth,
        $depth,
        $chain,
    ),
];
