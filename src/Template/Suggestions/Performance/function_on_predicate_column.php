<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$function = $context['function'] ?? '';
$column = $context['column'] ?? '';
$originalQuery = $context['original_query'] ?? '';
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<?php echo suggestionHeader('Function wraps predicate column'); ?>

<div class="suggestion-content">
    <div class="alert alert-warning">
        <code><?= $e((string) $function) ?>(<?= $e((string) $column) ?>)</code> in WHERE prevents the database
        from using any standard index on <code><?= $e((string) $column) ?></code>.
        Each row must be evaluated by the function before the predicate can be tested.
    </div>

    <h4>Original query</h4>
    <div class="query-item">
        <pre><code class="language-sql"><?= $e((string) $originalQuery) ?></code></pre>
    </div>

    <h4>Fix 1: keep the column bare</h4>
    <div class="query-item">
        <pre><code class="language-php">// Before: function wraps the column
$qb-&gt;andWhere('LOWER(u.email) = :email')
   -&gt;setParameter('email', strtolower($input));

// After: normalize at write time (store lowercased email) and compare bare
$qb-&gt;andWhere('u.email = :email')
   -&gt;setParameter('email', strtolower($input));</code></pre>
    </div>

    <h4>Fix 2: NULL handling without COALESCE/IFNULL/ISNULL</h4>
    <div class="query-item">
        <pre><code class="language-php">// Before
$qb-&gt;andWhere('COALESCE(u.deletedAt, 0) = 0');

// After
$qb-&gt;andWhere('u.deletedAt IS NULL');</code></pre>
    </div>

    <h4>Fix 3: functional / expression index (when rewrite is impossible)</h4>
    <div class="query-item">
        <pre><code class="language-sql">-- PostgreSQL
CREATE INDEX idx_users_email_lower ON users (LOWER(email));

-- MySQL 8.0+
ALTER TABLE users ADD INDEX idx_email_lower ((LOWER(email)));</code></pre>
    </div>

    <?php echo suggestionDocLink('https://use-the-index-luke.com/sql/where-clause/obfuscation', 'Use The Index, Luke! Obfuscated Conditions'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => 'Avoid wrapping filtered columns in SQL functions; rewrite the predicate or add a functional index',
];
