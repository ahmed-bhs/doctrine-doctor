<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$table = $context['table'] ?? 'product_taxon';
$condition = $context['condition'] ?? 'pt.taxon_id = ?';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Filter with one join, load with another'); ?>
<div class="suggestion-content">
<p><?php echo t('The same join both selects the rows of <code>%s</code> and filters them with <code>%s</code>. Join the collection twice: one alias decides which parents are returned, the other loads the whole collection.', $e($table), $e($condition)); ?></p>

<div class="query-item"><pre><code class="language-php">// Before
->select('p', 'productTaxon')
->innerJoin('p.productTaxons', 'productTaxon')
->andWhere('productTaxon.taxon = :collection')

// After
->select('p', 'productTaxon')
->innerJoin('p.productTaxons', 'inCollection', 'WITH', 'inCollection.taxon = :collection')
->leftJoin('p.productTaxons', 'productTaxon')</code></pre></div>

<p><?php echo t('If the page does not need the collection, keep the filter and remove the alias from <code>select()</code>.'); ?></p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/dql-doctrine-query-language.html#joins', 'Doctrine: fetch joins'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Do not filter the fetch-joined collection %s in WHERE', $table),
];
