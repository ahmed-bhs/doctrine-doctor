<?php

declare(strict_types=1);

/** @var array<string, mixed> $context */
$function = $context['function'] ?? '';
$column = $context['column'] ?? '';
$originalQuery = $context['original_query'] ?? '';
$e = fn (?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Keep the filtered column bare'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <?= t('<code>%s(%s)</code> in WHERE is evaluated for every row, so no regular index on <code>%s</code> can be used.', $e((string) $function), $e((string) $column), $e((string) $column)) ?>
    <?= t('Move the function to the other side of the comparison, or to write time.') ?>
</div>

<div class="query-item"><pre><code class="language-php">// Before
$qb-&gt;andWhere('LOWER(u.email) = :email');
$qb-&gt;andWhere('COALESCE(u.deletedAt, 0) = 0');

// After: store the email lowercased, compare the bare column
$qb-&gt;andWhere('u.email = :email')-&gt;setParameter('email', strtolower($input));
$qb-&gt;andWhere('u.deletedAt IS NULL');</code></pre></div>

<p><?= t('When the predicate cannot be rewritten, index the expression itself (PostgreSQL, MySQL 8.0+):') ?></p>
<div class="query-item"><pre><code class="language-sql">CREATE INDEX idx_users_email_lower ON users ((LOWER(email)));</code></pre></div>

<?php echo suggestionDocLink('https://use-the-index-luke.com/sql/where-clause/obfuscation', 'Use The Index, Luke! Obfuscated Conditions'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code' => $code,
    'description' => t('Rewrite the predicate so the filtered column is not wrapped in a function, or index the expression'),
];
