<?php

declare(strict_types=1);

/**
 * Template for auto-increment suggestion.
 * @var string $entity_name Full entity class name
 * @var string $short_name  Short entity name
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$entity_name = $context['entity_name'] ?? 'App\Entity\Example';
$short_name = $context['short_name'] ?? 'Example';

ob_start();
?>

<div class="auto-increment-suggestion">
    <h2>🔢 Consider UUID v7 Instead of Auto-Increment</h2>

    <div class="entity-info">
        <p><strong>Entity:</strong> <code><?= htmlspecialchars($short_name) ?></code></p>
    </div>

    <div class="current-status">
        <p><strong>Current:</strong> Auto-increment INT - simple but has limitations for distributed systems and APIs</p>
    </div>

    <div class="issues">
        <h3>Issues with Auto-Increment:</h3>
        <ul>
            <li>Exposes business metrics (e.g., <code>/users/1042</code> reveals ~1000 users)</li>
            <li>Enumeration attacks possible (iterate through /users/1, /users/2, etc.)</li>
            <li>Hard to merge databases (ID conflicts)</li>
            <li>Not suitable for distributed/microservices architecture</li>
        </ul>
    </div>

    <div class="uuid-solution">
        <h3>Consider UUID v7</h3>
        <div class="code-comparison">
            <div class="current-example">
                <p><em>Current</em></p>
                <pre><code class="language-php">#[ORM\Id]
#[ORM\GeneratedValue]
#[ORM\Column(type: 'integer')]
private int $id;</code></pre>
            </div>
            <div class="alternative-example">
                <p><em>Alternative: UUID v7</em></p>
                <pre><code class="language-php">use Symfony\Component\Uid\UuidV7;

#[ORM\Id]
#[ORM\Column(type: 'uuid')]
private UuidV7 $id;

public function __construct() {
    $this->id = new UuidV7();
}</code></pre>
            </div>
        </div>
    </div>

    <div class="recommendations">
        <div class="when-to-keep">
            <p><strong>When to keep auto-increment:</strong> Internal entities, performance-critical tables, monolithic apps.</p>
        </div>
        <div class="when-to-use">
            <p><strong>When to use UUID v7:</strong> API resources, distributed systems, security-sensitive entities.</p>
        </div>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Consider UUID v7 for better security and scalability',
];
