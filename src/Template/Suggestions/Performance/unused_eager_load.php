<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $unusedTables
 * @var mixed $unusedAliases
 * @var mixed $count
 * @var mixed $context
 */
['unused_tables' => $unusedTables, 'unused_aliases' => $unusedAliases, 'count' => $count] = $context;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

// Start output buffering for clean code block
ob_start();
?>

<div class="suggestion-header">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
        <path d="M7.002 11a1 1 0 1 1 2 0 1 1 0 0 1-2 0zM7.1 4.995a.905.905 0 1 1 1.8 0l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 4.995z"/>
    </svg>
    <h4>Suggested Fix: Remove Unused Eager Loading</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Unused Eager Load Detected</strong><br>
        Found <strong><?php echo $count; ?> unused JOIN(s)</strong> loading data that is never accessed.
        <?php if (1 === $count): ?>
        Table: <code><?php echo $e($unusedTables[0]); ?></code> (alias: <code><?php echo $e($unusedAliases[0]); ?></code>)
        <?php else: ?>
        Tables: <code><?php echo implode('</code>, <code>', array_map($e(...), $unusedTables)); ?></code>
        <?php endif; ?>
    </div>

    <h4>Problem: Loading Data You Never Use</h4>
    <div class="query-item">
        <pre><code class="language-sql">-- BAD: JOINing <?php echo $e($unusedTables[0]); ?> but never using it
SELECT a.*
FROM article a
LEFT JOIN <?php echo $e($unusedTables[0]); ?> <?php echo $e($unusedAliases[0]); ?> ON <?php echo $e($unusedAliases[0]); ?>.article_id = a.id
-- Result: Data loaded but never accessed! Waste of:
-- - Memory (storing unused entities)
-- - Bandwidth (transferring unused data)
-- - CPU time (hydrating unused objects)</code></pre>
    </div>

    <div class="query-item">
        <pre><code class="language-php">// Your code probably looks like this:
$query = $em->createQuery('
    SELECT a<?php foreach ($unusedAliases as $alias): ?>, <?php echo $e($alias); ?><?php endforeach; ?>

    FROM App\Entity\Article a
    <?php foreach ($unusedAliases as $alias): ?>
LEFT JOIN a.relation<?php echo ucfirst($e($alias)); ?> <?php echo $e($alias); ?>

    <?php endforeach; ?>
');

$articles = $query->getResult();
foreach ($articles as $article) {
    echo $article->getTitle(); // Never accessing <?php echo implode(', ', array_map($e(...), $unusedAliases)); ?>!
}</code></pre>
    </div>

    <h4>Solution 1: Remove Unused JOINs Recommended</h4>
    <div class="query-item">
        <pre><code class="language-sql">-- GOOD: Only JOIN what you actually need
SELECT a.*
FROM article a
-- Removed: LEFT JOIN <?php echo $e($unusedTables[0]); ?> ...
-- Result: Same functionality, less memory, faster!</code></pre>
    </div>

    <div class="query-item">
        <pre><code class="language-php">// Simplified query without unused JOINs
$query = $em->createQuery('
    SELECT a
    FROM App\Entity\Article a
');

$articles = $query->getResult();
// Result: Faster, less memory, same output!</code></pre>
    </div>

    <h4>Solution 2: Actually Use the Loaded Data</h4>
    <div class="query-item">
        <pre><code class="language-php">// If you DO need <?php echo $e($unusedTables[0]); ?>, use it!
$articles = $query->getResult();
foreach ($articles as $article) {
    echo $article->getTitle();
    // Access the eagerly loaded relation:
    echo $article->getRelation()->getName(); // Now it's used!
}</code></pre>
    </div>

    <h4>Why This Matters: Performance Impact</h4>
    <div class="alert alert-info">
        <strong>Memory Waste Example:</strong>
        <ul>
            <li>Loading 100 articles with unused author JOIN</li>
            <li>Each author entity: ~2KB</li>
            <li><strong>Wasted memory: ~200KB</strong> for data you never use!</li>
            <li>With 10,000 articles: <strong>~20MB wasted</strong></li>
        </ul>
    </div>

    <h4>⚖️ Why Unused Eager Loading Is Bad</h4>
    <div class="alert alert-warning">
        <strong>Negative Impacts:</strong>
        <ul>
            <li>💾 <strong>Memory waste</strong>: Entities loaded but never accessed</li>
            <li>🌐 <strong>Bandwidth waste</strong>: Transferring unnecessary data</li>
            <li>⏱️ <strong>Slower hydration</strong>: ORM must create objects you don't need</li>
            <li>🐘 <strong>Database overhead</strong>: JOINs process more rows</li>
            <li>📊 <strong>Result set bloat</strong>: Larger result sets to transfer</li>
        </ul>
    </div>

    <h4>Best Practices</h4>
    <ul>
        <li><strong>Only JOIN what you use</strong>: Remove JOINs for relations you don't access</li>
        <li><strong>Lazy loading is OK</strong>: If you don't need a relation, let it be lazy</li>
        <li><strong>Profile your queries</strong>: Use Doctrine profiler to find unused eager loads</li>
        <li><strong>Review DQL carefully</strong>: Each JOIN has a cost, make it count</li>
    </ul>

    <h4>When to Keep Eager Loading</h4>
    <ul>
        <li>You access the relation in 80%+ of iterations</li>
        <li>The relation is small (few rows, small objects)</li>
        <li>You're avoiding N+1 queries (you DO use the relation)</li>
    </ul>

    <h4>When to Remove It</h4>
    <ul>
        <li>You never call the getter for that relation</li>
        <li>The relation is large (many rows or big objects)</li>
        <li>You only need it in 10-20% of cases (consider lazy loading)</li>
    </ul>

    <div class="alert alert-info">
        ℹ️ <strong>Expected Performance Improvement:</strong><br>
        <ul>
            <li><strong>Memory usage:</strong> <?php echo $count; ?> fewer entities loaded per result</li>
            <li><strong>Hydration time:</strong> <?php echo $count * 15; ?>-<?php echo $count * 30; ?>% faster (estimated)</li>
            <li><strong>Query execution:</strong> Simpler query plan, less JOIN overhead</li>
        </ul>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#joins" target="_blank" class="doc-link">
            📖 Doctrine DQL JOIN Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Remove %d unused eager load(s) to save memory and improve performance',
        $count,
    ),
];
