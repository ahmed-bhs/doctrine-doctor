<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$getter = $context['getter'] ?? 'getOrders';
$association = $context['association'] ?? 'Customer::$orders';
[$entity, $field] = array_pad(explode('::$', (string) $association, 2), 2, 'orders');

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Count in SQL, not by loading the collection'); ?>
<div class="suggestion-content">
<p>For one entity, mark the association <code>EXTRA_LAZY</code>: <code>count()</code> then runs a <code>COUNT</code> query and leaves the collection unloaded. For a list, ask for the counts in the query that loads the list.</p>

<div class="query-item"><pre><code class="language-php">#[ORM\OneToMany(targetEntity: Order::class, mappedBy: 'customer', fetch: 'EXTRA_LAZY')]
private Collection $<?php echo $e($field); ?>;

// a list of <?php echo $e($entity); ?> with their counts, in one query
SELECT c, COUNT(x.id) FROM <?php echo $e($entity); ?> c LEFT JOIN c.<?php echo $e($field); ?> x GROUP BY c</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/current/tutorials/extra-lazy-associations.html', 'Doctrine: extra lazy associations'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Count %s without loading it', $association),
];
