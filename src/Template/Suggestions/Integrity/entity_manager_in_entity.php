<?php

declare(strict_types=1);

/**
 * Template for EntityManager in Entity suggestion.
 * Context variables:
 * @var string       $bad_code - Example of incorrect code
 * @var string       $good_code - Example of correct code
 * @var string       $description - Description of the issue
 * @var array<mixed> $benefits - List of benefits of separation
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$badCode = $context['bad_code'] ?? null;
$goodCode = $context['good_code'] ?? null;
$description = $context['description'] ?? '';
$benefits = $context['benefits'] ?? null;

// Helper function for safe HTML escaping
$e = fn (?string $str): string => htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="suggestion-header">
    <h4>EntityManager in Entity</h4>
</div>

<div class="suggestion-content">
    <div class="alert alert-danger">
        Entities should not handle database operations. Move persistence logic to services or repositories.
    </div>

    <h4>Current code</h4>
    <div class="query-item">
        <pre><code class="language-php"><?php echo $e($badCode); ?></code></pre>
    </div>

    <h4>Solution</h4>
    <div class="query-item">
        <pre><code class="language-php"><?php echo $e($goodCode); ?></code></pre>
    </div>

    <p>Entities represent data. Services handle persistence.</p>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/architecture.html" target="_blank" class="doc-link">
            📖 Doctrine architecture
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Remove EntityManager from entity to maintain domain model purity',
];
