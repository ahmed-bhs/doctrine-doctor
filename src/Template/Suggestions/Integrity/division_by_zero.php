<?php

declare(strict_types=1);

/** @var array<string, mixed> $context PHPStan: Template context */
$unsafe_division = $context['unsafe_division'] ?? 'dividend / divisor';
$safe_division = $context['safe_division'] ?? 'dividend / NULLIF(divisor, 0)';
$dividend = $context['dividend'] ?? 'dividend_field';
$divisor = $context['divisor'] ?? 'divisor_field';

$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php echo suggestionHeader('Guard the divisor with NULLIF()'); ?>
<div class="suggestion-content">
<p><?php echo t('<code>%s</code> raises an error on PostgreSQL and SQL Server when <code>%s</code> is 0. <code>NULLIF(%s, 0)</code> turns that case into <code>NULL</code>.', $e((string) $unsafe_division), $e((string) $divisor), $e((string) $divisor)); ?></p>

<div class="query-item"><pre><code class="language-sql">-- Before
<?php echo $e((string) $unsafe_division); ?>


-- After
<?php echo $e((string) $safe_division); ?></code></pre></div>

<?php echo suggestionDocLink('https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/dql-doctrine-query-language.html', 'Doctrine Query Language (DQL)'); ?>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => t('Use NULLIF() to prevent division by zero'),
];
