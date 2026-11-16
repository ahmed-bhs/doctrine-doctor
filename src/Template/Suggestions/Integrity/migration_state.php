<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$case = $context['case'] ?? 'drift';
$items = is_array($context['items'] ?? null) ? $context['items'] : [];

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php if ('pending' === $case) { ?>
<?php echo suggestionHeader('Run the pending migrations'); ?>
<div class="suggestion-content">
<p>Check what the migrations will do, then run them. In production, run them in the deployment, before the new code receives traffic.</p>

<div class="query-item"><pre><code class="language-bash">php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:migrate --dry-run
php bin/console doctrine:migrations:migrate</code></pre></div>
<?php } else { ?>
<?php echo suggestionHeader('Generate the missing migration, then review it'); ?>
<div class="suggestion-content">
<p>Generate the migration from the mapping and read it before committing: a DROP removes the data of the column or table. When the column must stay, map it, or move its data first.</p>

<div class="query-item"><pre><code class="language-bash">php bin/console doctrine:migrations:diff
<?php foreach (array_slice($items, 0, 3) as $sql) { ?>
# <?php echo $e(mb_strimwidth((string) $sql, 0, 100, '…')); ?>

<?php } ?></code></pre></div>
<?php } ?>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-migrations/en/current/reference/managing-migrations.html', 'Doctrine Migrations: managing migrations'); ?>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'pending' === $case ? 'Run the pending migrations' : 'Generate and review the missing migration',
];
