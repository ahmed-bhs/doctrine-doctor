<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$getter = $context['getter'] ?? 'getItems';
$association = $context['association'] ?? 'Order::$items';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Filter the collection in SQL with matching()'); ?>
<div class="suggestion-content">
<p><code>filter()</code> loads every element of <code><?php echo $e($association); ?></code> first. <code>matching()</code> sends the condition to the database when the collection is not loaded yet, and filters in memory when it already is.</p>

<div class="query-item"><pre><code class="language-php">use Doctrine\Common\Collections\Criteria;

// Before
$active = $order-><?php echo $e($getter); ?>()->filter(fn ($item) => 'active' === $item->getStatus());

// After
$active = $order-><?php echo $e($getter); ?>()->matching(
    Criteria::create()->where(Criteria::expr()->eq('status', 'active'))
);</code></pre></div>

<p>Criteria use field names of the target entity. If you only need the count, mark the association <code>fetch: 'EXTRA_LAZY'</code>: <code>count()</code> then runs a <code>COUNT</code> query.</p>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/working-with-associations.html#filtering-collections', 'Doctrine: filtering collections'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Use matching() instead of %s()->filter()', $getter),
];
