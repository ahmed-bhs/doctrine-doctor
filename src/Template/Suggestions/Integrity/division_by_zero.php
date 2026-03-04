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
<div class="suggestion-header"><h4>Division by zero</h4></div>
<div class="suggestion-content">
<div class="alert alert-danger"><strong>Unsafe:</strong> <code><?php echo $e($unsafe_division); ?></code></div>

<p>If <code><?php echo $e($divisor); ?></code> is zero, database error.</p>

<h4>Use NULLIF()</h4>
<div class="query-item"><pre><code class="language-sql"><?php echo $e((string) $safe_division); ?></code></pre></div>

<p><code>NULLIF(<?php echo $e($divisor); ?>, 0)</code> returns <code>NULL</code> when divisor is 0.</p>

<h4>DQL Example</h4>
<div class="query-item"><pre><code class="language-php">// Unsafe
$qb->select('(o.revenue / o.quantity) as avg_price');

// Safe
$qb->select('(o.revenue / NULLIF(o.quantity, 0)) as avg_price');</code></pre></div>

<p><a href="https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/dql-doctrine-query-language.html" target="_blank" rel="noopener noreferrer" class="doc-link">Doctrine Query Language (DQL)</a></p>
</div>
<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Use NULLIF() to prevent division by zero',
];
