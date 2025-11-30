<?php

declare(strict_types=1);

/**
 * Template for Multi-Step Hydration suggestion.
 * Context variables:
 * - join_count: Number of collection JOINs
 * - tables: Array of table names being joined
 * - sql: Original SQL query
 */
$joinCount = $context['join_count'] ?? 2;
$tables = $context['tables'] ?? [];
$sql = $context['sql'] ?? '';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Multi-Step Hydration for Collection JOINs</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        <strong>O(n^<?php echo $joinCount; ?>) Hydration Complexity Detected!</strong><br>
        Query performs <?php echo $joinCount; ?> LEFT JOINs on collections (<?php echo implode(', ', array_map($e, $tables)); ?>).<br>
        This creates a cartesian product that exponentially increases SQL rows.
    </div>

    <h4>Current Approach (O(n^<?php echo $joinCount; ?>))</h4>
    <div class="query-item">
        <pre><code class="language-sql"><?php echo $e($sql); ?></code></pre>
    </div>
    <p class="text-muted">
        Example: 1 user with 3 social accounts × 2 sessions = <strong>6 rows</strong> returned for the same user!<br>
        Doctrine must de-normalize this data, causing expensive O(n^m) hydration.
    </p>

    <h4>Solution: Multi-Step Hydration (O(n×m + n×p))</h4>
    <p>Split the query into separate steps to avoid cartesian product:</p>

    <div class="query-item">
        <pre><code class="language-php">// Step 1: Load users with first collection
$users = $em->createQuery('
    SELECT u, <?php echo isset($tables[0]) ? 'c1' : 'collection1'; ?>

    FROM User u
    LEFT JOIN u.<?php echo isset($tables[0]) ? $e(basename($tables[0])) : 'firstCollection'; ?> <?php echo isset($tables[0]) ? 'c1' : 'collection1'; ?>

')->getResult();

// Step 2: Re-hydrate users with second collection
// Doctrine's UnitOfWork will populate the existing User objects
$em->createQuery('
    SELECT PARTIAL u.{id}, <?php echo isset($tables[1]) ? 'c2' : 'collection2'; ?>

    FROM User u
    LEFT JOIN u.<?php echo isset($tables[1]) ? $e(basename($tables[1])) : 'secondCollection'; ?> <?php echo isset($tables[1]) ? 'c2' : 'collection2'; ?>

')->getResult(); // Result discarded - just re-hydrating

<?php if ($joinCount > 2): ?>
// Step 3: Re-hydrate with third collection
$em->createQuery('
    SELECT PARTIAL u.{id}, <?php echo isset($tables[2]) ? 'c3' : 'collection3'; ?>

    FROM User u
    LEFT JOIN u.<?php echo isset($tables[2]) ? $e(basename($tables[2])) : 'thirdCollection'; ?> <?php echo isset($tables[2]) ? 'c3' : 'collection3'; ?>

')->getResult();
<?php endif; ?>

// All collections are now loaded - use $users
foreach ($users as $user) {
    // Access all collections - fully hydrated
}</code></pre>
    </div>

    <h4>Performance Impact</h4>
    <ul>
        <li><strong>Rows returned:</strong> 3 + 2 = <strong>5 rows</strong> instead of 6 (gets worse with more data!)</li>
        <li><strong>Hydration complexity:</strong> O(n×m + n×p) instead of O(n^<?php echo $joinCount; ?>)</li>
        <li><strong>Memory usage:</strong> 50-70% reduction for large datasets</li>
        <li><strong>Performance:</strong> 2-5x faster hydration with complex collections</li>
    </ul>

    <h4>How It Works</h4>
    <p>
        Doctrine's <code>UnitOfWork</code> maintains an identity map of all loaded entities.<br>
        When you query for the same entities again (same ID), Doctrine <strong>re-uses existing objects</strong> and just fills their collections.<br>
        This is why the second/third query results are discarded - we only care about the side effect of populating collections.
    </p>

    <div class="alert alert-info">
        <strong>Pro Tip:</strong> Use <code>PARTIAL u.{id}</code> in subsequent queries to avoid re-hydrating scalar fields that are already in memory.
    </div>

    <p>
        <a href="https://ocramius.github.io/blog/doctrine-orm-optimization-hydration" target="_blank" class="doc-link">
            📖 Read Marco Pivetta's article on multi-step hydration →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Multi-step hydration could reduce O(n^%d) complexity for %d collection JOINs',
        $joinCount,
        $joinCount,
    ),
];
