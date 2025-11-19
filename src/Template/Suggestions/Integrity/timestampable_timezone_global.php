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
 * Context variables provided by PhpTemplateRenderer::extract($context):
 * @var mixed $totalFields Number of timestamp fields found
 */
['total_fields' => $totalFields] = $context;

// Escaping function
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>Timezone awareness (<?php echo $totalFields; ?> fields)</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-info">
        Found <strong><?php echo $totalFields; ?> timestamp fields</strong> using <code>datetime</code> without timezone information.
    </div>

    <p>This is fine for most applications. If you store everything in UTC and convert to the user's timezone when displaying, you don't need to change anything.</p>

    <h4>When this is acceptable</h4>
    <div class="query-item">
        <pre><code>Most web applications:
   - Store everything in UTC
   - Convert to user timezone in PHP
   - Simple and works well</code></pre>
    </div>

    <h4>When to use datetimetz</h4>
    <div class="query-item">
        <pre><code>Multi-timezone applications:
   - Users in different timezones
   - Direct SQL reports or BI tools
   - Need to preserve original timezone</code></pre>
    </div>

    <h4>Switching to datetimetz</h4>
    <div class="query-item">
        <pre><code class="language-php">#[ORM\Column(type: 'datetimetz_immutable')]
private \DateTimeImmutable $createdAt;</code></pre>
    </div>

    <p>If you're not sure, stick with <code>datetime</code> and UTC. It's simpler and works for most cases. Only switch to <code>datetimetz</code> if you have a specific need to preserve timezones.</p>

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
