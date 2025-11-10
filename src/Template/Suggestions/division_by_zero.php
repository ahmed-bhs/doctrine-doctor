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
    <h2>Division By Zero Risk Detected</h2>

    <div class="unsafe-operation">
        <p><strong>Unsafe operation:</strong></p>
        <pre><code class="language-sql"><?= htmlspecialchars($unsafe_division) ?></code></pre>
    </div>

    <div class="problem-description">
        <p><strong>Problem:</strong></p>
        <p>If <code><?= htmlspecialchars($divisor) ?></code> is zero, this will cause a database error and crash your application.</p>
    </div>

    <div class="recommended-fix">
        <h3>Recommended Fix</h3>
        <p>Use <code>NULLIF()</code> to safely handle zero values:</p>
        <pre><code class="language-sql"><?= htmlspecialchars($safe_division) ?></code></pre>

        <div class="explanation">
            <p><strong>How it works:</strong></p>
            <ul>
                <li><code>NULLIF(<?= htmlspecialchars($divisor) ?>, 0)</code> returns <code>NULL</code> if <?= htmlspecialchars($divisor) ?> equals 0</li>
                <li>Division by <code>NULL</code> returns <code>NULL</code> instead of an error</li>
                <li>Your application can handle <code>NULL</code> results gracefully</li>
            </ul>
        </div>
    </div>

    <div class="alternative-solution">
        <h3>📖 Alternative: CASE Statement</h3>
        <p>For more control over the result:</p>
        <pre><code class="language-sql">CASE
    WHEN <?= htmlspecialchars($divisor) ?> = 0 THEN 0  -- or NULL, or any default value
    ELSE <?= htmlspecialchars($dividend) ?> / <?= htmlspecialchars($divisor) ?>

END</code></pre>
    </div>

    <div class="doctrine-example">
        <h3>🔍 DQL Example (Doctrine)</h3>
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

    <div class="learn-more">
        <h3>📖 Learn More</h3>
        <ul>
            <li>Division by zero is a critical error that stops query execution</li>
            <li>Always validate divisor before division operations</li>
            <li>Use database functions (<code>NULLIF</code>, <code>COALESCE</code>, <code>CASE</code>) for safety</li>
        </ul>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Suggestion',
];
