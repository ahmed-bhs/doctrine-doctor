<?php

declare(strict_types=1);

/**
 * Template for LEFT JOIN on NOT NULL relation.
 * Context variables:
 * @var string $table - Table name
 * @var string $alias - Join alias
 * @var string $entity - Entity class
 */
['table' => $table, 'alias' => $alias, 'entity' => $entity] = $context;
$e                                                          = fn (string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Suboptimal LEFT JOIN on NOT NULL Relation</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <strong>Performance Improvement Available</strong><br>
        LEFT JOIN on table '<?php echo $e($table); ?>' which has a NOT NULL foreign key.<br>
        Using INNER JOIN would be 20-30% faster.
    </div>

    <h4>Current Query (Suboptimal)</h4>
    <div class="query-item">
        <?php echo formatSqlWithHighlight("LEFT JOIN {$table} {$alias}"); ?>
    </div>

    <h4>Problem</h4>
    <ul>
        <li>LEFT JOIN includes rows where the relation is NULL</li>
        <li>But your foreign key is NOT NULL (relation is mandatory)</li>
        <li>Database never returns NULL for this relation</li>
        <li>LEFT JOIN is doing extra work for nothing</li>
    </ul>

    <h4> Solution: Use INNER JOIN</h4>
    <div class="query-item">
        <pre><code class="language-php">// Current (suboptimal):
$qb->select('o')
   ->from(Order::class, 'o')
   ->leftJoin('o.relation', '<?php echo $e($alias); ?>');

//  Better (20-30% faster):
$qb->select('o')
   ->from(Order::class, 'o')
   ->innerJoin('o.relation', '<?php echo $e($alias); ?>');
   // or ->join() which is an alias for innerJoin()</code></pre>
    </div>

    <h4>When to Use Each</h4>
    <ul>
        <li><strong>NOT NULL FK</strong> (@JoinColumn(nullable=false)) → INNER JOIN </li>
        <li><strong>Nullable FK</strong> (@JoinColumn(nullable=true)) → LEFT JOIN </li>
    </ul>

    <div class="alert alert-info">
        ℹ️ <strong>Performance Impact:</strong><br>
        <ul>
            <li>INNER JOIN: ⚡⚡⚡⚡⚡ Fast (filters rows early)</li>
            <li>LEFT JOIN on NOT NULL: ⚡⚡⚡⚡ Slower (processes all rows)</li>
        </ul>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use INNER JOIN instead of LEFT JOIN on NOT NULL relation',
];
