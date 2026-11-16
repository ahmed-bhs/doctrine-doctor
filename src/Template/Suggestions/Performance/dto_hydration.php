<?php

declare(strict_types=1);

/**
 * Template for DTO Hydration suggestion.
 * @var array<string, mixed> $context
 */
$queryCount = max(0, (int) ($context['query_count'] ?? 0));
$aggregations = $context['aggregations'] ?? [];
$hasGroupBy = (bool) ($context['has_group_by'] ?? false);

if (!is_array($aggregations)) {
    $aggregations = [];
}

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Hydrate aggregation results into a DTO'); ?>
<div class="suggestion-content">
<div class="alert alert-warning">
    <?php echo $queryCount; ?> aggregation <?php echo $queryCount > 1 ? 'queries' : 'query'; ?><?php if ([] !== $aggregations) { ?> (<?php echo implode(', ', array_map(static fn (mixed $aggregation): string => $e((string) $aggregation), $aggregations)); ?>)<?php } ?> <?php echo $queryCount > 1 ? 'return' : 'returns'; ?> untyped arrays.
    <code>SELECT NEW</code> builds a typed, read-only object per row, without the unit of work.
</div>

<div class="query-item"><pre><code class="language-php">final class UserRevenue
{
    public function __construct(
        public readonly string $name,
        public readonly float $revenue,
    ) {}
}

$results = $em-&gt;createQuery('SELECT NEW App\DTO\UserRevenue(u.name, SUM(o.total))
    FROM App\Entity\User u JOIN u.orders o GROUP BY u.id')
    -&gt;getResult();</code></pre></div>

<p>For reports and exports, plain rows through DBAL skip hydration altogether:</p>
<div class="query-item"><pre><code class="language-php">$rows = $em-&gt;getConnection()-&gt;fetchAllAssociative(
    'SELECT u.name, SUM(o.total) AS revenue
     FROM users u JOIN orders o ON o.user_id = u.id
     GROUP BY u.id, u.name',
);</code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#new-operator-syntax', 'Doctrine NEW operator'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf(
        'Hydrate %d aggregation %s into a DTO with SELECT NEW',
        $queryCount,
        $queryCount > 1 ? 'queries' : 'query',
    ),
];
