<?php

declare(strict_types=1);

/**
 * Template for division by zero suggestion.
 * @var string $unsafe_division Original unsafe division
 * @var string $safe_division   Safe division with NULLIF
 * @var string $dividend        Dividend field
 * @var string $divisor         Divisor field
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$unsafe_division = $context['unsafe_division'] ?? 'dividend / divisor';
$safe_division = $context['safe_division'] ?? 'dividend / NULLIF(divisor, 0)';
$dividend = $context['dividend'] ?? 'dividend_field';
$divisor = $context['divisor'] ?? 'divisor_field';

ob_start();
?>

<div class="division-zero-risk">
    <h2>Division by zero</h2>

    <div class="unsafe-operation">
        <p><strong>Unsafe:</strong></p>
        <pre><code class="language-sql"><?= htmlspecialchars($unsafe_division) ?></code></pre>
    </div>

    <div class="problem-description">
        <p>If <code><?= htmlspecialchars($divisor) ?></code> is zero, database error.</p>
    </div>

    <div class="recommended-fix">
        <h3>Use NULLIF()</h3>
        <pre><code class="language-sql"><?= htmlspecialchars($safe_division) ?></code></pre>
        <p><code>NULLIF(<?= htmlspecialchars($divisor) ?>, 0)</code> returns <code>NULL</code> when divisor is 0.</p>
    </div>

    <div class="doctrine-example">
        <h3>DQL Example</h3>
        <div class="code-comparison">
            <div class="unsafe-example">
                <p><em>Unsafe</em></p>
                <pre><code class="language-php">$qb->select('(o.revenue / o.quantity) as avg_price');</code></pre>
            </div>
            <div class="safe-example">
                <p><em>Safe</em></p>
                <pre><code class="language-php">$qb->select('(o.revenue / NULLIF(o.quantity, 0)) as avg_price');</code></pre>
            </div>
        </div>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
