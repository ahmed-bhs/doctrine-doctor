<?php

/*
<ul>
<li>This file is part of the Doctrine Doctor.</li>
<li>(c) 2025 Ahmed EBEN HASSINE</li>
<li>For the full copyright and license information, please view the LICENSE</li>
<li>file that was distributed with this source code.</li>
</ul>
 */

declare(strict_types=1);

/**
 * Variables provided by PhpTemplateRenderer::extract($context)
 * @var mixed $datetimeCount
 * @var mixed $datetimetzCount
 * @var mixed $context
 */
['datetime_count' => $datetimeCount, 'datetimetz_count' => $datetimetzCount] = $context;

// Escaping function
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>⚠️ Inconsistent Timezone Usage</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-warning">
        ⚠️ <strong>Warning</strong><br>
        Your application has <strong>inconsistent timezone handling</strong>:<br>
<ul>
<li><?php echo $datetimeCount; ?> fields use <code>datetime</code> (no timezone)<br></li>
<li><?php echo $datetimetzCount; ?> fields use <code>datetimetz</code> (with timezone)</li>
</ul>
    </div>

    <h4>Why is this a problem?</h4>
    <div class="query-item">
        <pre><code class="language-bash">Mixing datetime types can cause:
<ul>
<li>Inconsistent data storage</li>
<li>Timezone conversion bugs</li>
<li>Unpredictable query results</li>
<li>Maintenance confusion</code></pre></li>
</ul>
    </div>

    <h4>Recommended Solution</h4>
    <div class="alert alert-success">
        💡 <strong>Choose ONE approach for your entire application:</strong>
    </div>

    <h4>Option 1: Use datetime everywhere (Recommended for most apps)</h4>
    <div class="query-item">
        <pre><code class="language-php">// Best for: E-commerce, SaaS, CMS, blogs, APIs
// Strategy: Store everything in UTC

// Change datetimetz fields to datetime:
#[ORM\Column(type: 'datetime_immutable')]
private \DateTimeImmutable $createdAt;

// Benefit: Simple, standard, works for 99% of apps</code></pre>
    </div>

    <h4>Option 2: Use datetimetz everywhere (Only if needed)</h4>
    <div class="query-item">
        <pre><code class="language-php">// Best for: Calendar apps, hotel booking, medical appointments
// Strategy: Preserve original timezone

// Change datetime fields to datetimetz:
#[ORM\Column(type: 'datetimetz_immutable')]
private \DateTimeImmutable $createdAt;

// Drawback: More complex, larger storage</code></pre>
    </div>

    <h4>How to decide?</h4>
    <table>
        <tr>
            <th>Choose datetime if...</th>
            <th>Choose datetimetz if...</th>
        </tr>
        <tr>
            <td>Most web applications</td>
            <td>Need original timezone</td>
        </tr>
        <tr>
            <td>Store all timestamps in UTC</td>
            <td>Calendar/scheduling app</td>
        </tr>
        <tr>
            <td>Convert timezone in PHP</td>
            <td>BI tools query directly</td>
        </tr>
        <tr>
            <td>Simpler code</td>
            <td>Multi-timezone critical</td>
        </tr>
    </table>

    <div class="alert alert-info">
        💡 <strong>Our recommendation:</strong> Use <code>datetime</code> everywhere with UTC storage.<br>
        This is the industry standard for 99% of applications (Symfony, Laravel, Rails, etc.).
    </div>

    <h4>Migration Steps</h4>
    <div class="query-item">
        <pre><code class="language-bash"># 1. Update entity annotations
# Change all datetimetz → datetime (or vice versa)

# 2. Generate migration
php bin/console doctrine:migrations:diff

# 3. Review migration carefully
# ALTER TABLE changes will convert column types

# 4. Test thoroughly before deploying
php bin/console doctrine:migrations:migrate --dry-run

# 5. Deploy migration
php bin/console doctrine:migrations:migrate</code></pre>
    </div>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/types.html#datetime" target="_blank" class="doc-link">
            📜 Doctrine: DateTime Types
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => sprintf('Inconsistent timezone usage: %d datetime vs %d datetimetz fields', $datetimeCount, $datetimetzCount),
];
