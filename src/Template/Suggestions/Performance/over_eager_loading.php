<?php

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var int $joinCount
 * @var array<string, mixed> $context
 */
$joinCount = (int) ($context['join_count'] ?? 0);
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Load each collection in its own query'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    The query has <?php echo $joinCount; ?> JOINs. Each fetch-joined collection multiplies the rows of the others:
    1 article with 10 comments and 5 tags already returns 50 rows.
</div>

<div class="query-item"><pre><code class="language-php">// Before: two collections in one query
$em->createQuery('SELECT a, c, t FROM App\Entity\Article a
    LEFT JOIN a.comments c LEFT JOIN a.tags t')->getResult();

// After: one collection per query, the identity map merges them
$articles = $em->createQuery('SELECT a, c FROM App\Entity\Article a
    LEFT JOIN a.comments c')->getResult();
$em->createQuery('SELECT a, t FROM App\Entity\Article a
    LEFT JOIN a.tags t WHERE a IN (:articles)')
    ->setParameter('articles', $articles)
    ->getResult();</code></pre></div>

<p>To-one joins (ManyToOne, OneToOne) add no rows and can stay in the first query.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#joins', 'Doctrine DQL JOIN Documentation'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Split the %d JOINs so each collection is loaded in its own query', $joinCount),
];
