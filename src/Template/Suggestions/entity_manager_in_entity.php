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
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
    </svg>
    <h4>Remove EntityManager from Entity</h4>
</div>

<div class="suggestion-content">
    <p><?php echo $e($description); ?></p>

    <div class="alert alert-danger">
        🚨 <strong>Domain Model Purity</strong><br><br>
        Entities represent business concepts, not database operations.
        Mixing persistence logic violates Clean Architecture and DDD principles.
    </div>

    <h4>Current Code (Incorrect)</h4>
    <div class="query-item">
        <pre><code class="language-php"><?php echo $e($badCode); ?></code></pre>
    </div>

    <h4>Correct Code</h4>
    <div class="query-item">
        <pre><code class="language-php"><?php echo $e($goodCode); ?></code></pre>
    </div>

    <h4>Benefits of Separation</h4>
    <ul>
        <?php assert(is_iterable($benefits), '$benefits must be iterable');
foreach ($benefits as $benefit) { ?>
        <li><?php echo $benefit; ?></li>
        <?php } ?>
    </ul>

    <h4>Best Practices</h4>
    <ul>
        <li>Keep entities as pure domain models</li>
        <li>Use services or repositories for persistence</li>
        <li>Control transaction boundaries at application layer</li>
        <li>Make entities easily testable without database</li>
        <li>Follow Single Responsibility Principle</li>
    </ul>

    <p>
        <a href="https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/architecture.html" target="_blank" class="doc-link">
            📖 Doctrine Architecture Documentation →
        </a>
    </p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Remove EntityManager from entity to maintain domain model purity',
];
