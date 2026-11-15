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
$relationName = static fn (int $index, string $fallback): string => isset($tables[$index]) ? basename((string) $tables[$index]) : $fallback;

ob_start();
?>
<?php echo suggestionHeader('Load each collection in its own query'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    The query fetch-joins <?php echo (int) $joinCount; ?> collections (<?php echo implode(', ', array_map($e, $tables)); ?>), so their rows multiply:
    1 user with 3 accounts and 2 sessions returns 6 rows. One query per collection returns 3 + 2 rows instead.
</div>

<div class="query-item"><pre><code class="language-php">// Step 1: the roots with the first collection
$users = $em-&gt;createQuery('SELECT u, c1 FROM App\Entity\User u
    LEFT JOIN u.<?php echo $e($relationName(0, 'firstCollection')); ?> c1')-&gt;getResult();

// Step 2: same roots, next collection; the identity map fills the loaded objects
$em-&gt;createQuery('SELECT u, c2 FROM App\Entity\User u
    LEFT JOIN u.<?php echo $e($relationName(1, 'secondCollection')); ?> c2 WHERE u IN (:users)')
    -&gt;setParameter('users', $users)
    -&gt;getResult();
<?php if ($joinCount > 2) { ?>
// Repeat step 2 for <?php echo $e($relationName(2, 'thirdCollection')); ?> and any further collection
<?php } ?></code></pre></div>

<?php echo suggestionDocLink('https://ocramius.github.io/blog/doctrine-orm-optimization-hydration', 'Read Marco Pivetta\'s article on multi-step hydration'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Load the %d fetch-joined collections in separate queries to avoid multiplying rows', $joinCount),
];
