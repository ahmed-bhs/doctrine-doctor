<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Template for Global Timezone Warning.
 * Context variables:
 * @var int $total_fields
 */
['total_fields' => $totalFields] = $context;

// Escaping function
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>🌍 Timezone Awareness (<?php echo $totalFields; ?> fields)</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-info">
        ℹ️ <strong>Information</strong><br>
        Your application has <strong><?php echo $totalFields; ?> timestamp fields</strong> using <code>datetime</code> type without timezone information.
    </div>

    <h4>When is this acceptable?</h4>
    <div class="query-item">
        <pre><code>Single timezone applications:
   - All timestamps stored in UTC
   - Timezone conversion happens in PHP
   - No direct SQL reports/BI tools

Common pattern:
   - Store everything in UTC in database
   - Convert to user timezone in application layer
   - Most web applications work this way</code></pre>
    </div>

    <h4>When should you use datetimetz?</h4>
    <div class="query-item">
        <pre><code>Multi-timezone applications:
   - Users in different timezones
   - Direct SQL reports/analytics
   - Third-party BI tools accessing database
   - Need to preserve original timezone</code></pre>
    </div>

    <h4>Recommendation</h4>
    <div class="alert alert-success">
        💡 If your application runs in a single timezone (most common case), <strong>this is acceptable</strong>.<br>
        You don't need to change anything.
    </div>

    <h4>If you need multi-timezone support:</h4>
    <div class="query-item">
        <pre><code class="language-php">// Change from:
#[ORM\Column(type: 'datetime_immutable')]
private \DateTimeImmutable $createdAt;

// To:
#[ORM\Column(type: 'datetimetz_immutable')]
private \DateTimeImmutable $createdAt;</code></pre>
    </div>

    <h4>Trade-offs</h4>
    <table class="comparison-table">
        <tr>
            <th>Approach</th>
            <th>Storage</th>
            <th>Complexity</th>
            <th>Best For</th>
        </tr>
        <tr>
            <td><code>datetime</code> (UTC)</td>
            <td>Smaller</td>
            <td>Simpler</td>
            <td>Most applications</td>
        </tr>
        <tr>
            <td><code>datetimetz</code></td>
            <td>📢 Larger</td>
            <td>📢 More complex</td>
            <td>Multi-timezone apps</td>
        </tr>
    </table>

    <div class="alert alert-info">
        💡 <strong>Bottom line:</strong> If you're not sure, keep <code>datetime</code> with UTC.<br>
        Only use <code>datetimetz</code> if you have a specific need for it.
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#datetimetz" target="_blank" class="doc-link">
            📖 Doctrine: DateTimeTZ Type →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('%d timestamp fields without timezone (acceptable for single-timezone apps)', $totalFields),
];
