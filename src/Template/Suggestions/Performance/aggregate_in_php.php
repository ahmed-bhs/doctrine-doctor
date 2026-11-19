<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$getter = $context['getter'] ?? 'getTotal';
$field = lcfirst((string) preg_replace('/^get/', '', (string) $getter));

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Let the database compute the sum'); ?>
<div class="suggestion-content">
<p><?php echo t('Ask for the number, not for the rows: <code>SUM()</code>, <code>AVG()</code>, <code>MAX()</code> return one row, and nothing is hydrated.'); ?></p>

<div class="query-item"><pre><code class="language-php">// Before
$total = 0;
foreach ($repository->findAll() as $item) {
    $total += $item-><?php echo $e($getter); ?>();
}

// After
$total = (int) $repository->createQueryBuilder('i')
    ->select('SUM(i.<?php echo $e($field); ?>)')
    ->getQuery()
    ->getSingleScalarResult();</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/dql-doctrine-query-language.html#aggregate-functions', 'Doctrine: aggregate functions'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Compute the sum of %s() in SQL', $getter),
];
