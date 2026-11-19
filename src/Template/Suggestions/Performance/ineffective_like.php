<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$pattern = $context['pattern'] ?? '%search%';
$like_type = $context['like_type'] ?? 'contains search';
$original_query = $context['original_query'] ?? $context['query'] ?? 'SELECT ... WHERE column LIKE \'%value%\'';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Avoid a leading wildcard in LIKE'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <?php echo t("<code>LIKE '%s'</code> starts with a wildcard, so no regular index on the column can be used and every row is read.", $e($pattern)); ?>
<?php if ('ends-with search' === $like_type) { ?>
    <?php echo t('For an ends-with search, index a reversed copy of the column and search it with a prefix.'); ?>
<?php } else { ?>
    <?php echo t('For a contains search, a full-text index is built for this.'); ?>
<?php } ?>
</div>

<div class="query-item"><pre><code class="language-sql">-- Before: full scan
SELECT * FROM product WHERE name LIKE '%phone%';

-- After (MySQL): full-text index
ALTER TABLE product ADD FULLTEXT INDEX ft_product_name (name);
SELECT * FROM product WHERE MATCH(name) AGAINST('phone');</code></pre></div>

<p><?php echo t("On PostgreSQL, a trigram index (<code>CREATE INDEX ... USING gin (name gin_trgm_ops)</code>) serves <code>LIKE '%value%'</code> directly."); ?></p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Replace the leading-wildcard LIKE (%s) with a full-text or trigram index', $like_type),
];
