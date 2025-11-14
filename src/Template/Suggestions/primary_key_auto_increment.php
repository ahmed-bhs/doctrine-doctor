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
    <h2>Consider UUID v7 Instead of Auto-Increment</h2>

    <div class="entity-info">
        <p><strong>Entity:</strong> <code><?= htmlspecialchars($short_name) ?></code></p>
    </div>

    <p><strong>Current:</strong> Auto-increment INT - simple but exposes business metrics, enables enumeration attacks, and doesn't work for distributed systems.</p>

    <h3>Consider UUID v7</h3>
    <p><em>Current:</em></p>
    <pre><code class="language-php">#[ORM\Id]
#[ORM\GeneratedValue]
#[ORM\Column(type: 'integer')]
private int $id;</code></pre>

    <p><em>Alternative:</em></p>
    <pre><code class="language-php">use Symfony\Component\Uid\UuidV7;

#[ORM\Id]
#[ORM\Column(type: 'uuid')]
private UuidV7 $id;

public function __construct() {
    $this->id = new UuidV7();
}</code></pre>

    <p><strong>Use UUID v7 for:</strong> API resources, distributed systems, security-sensitive entities.</p>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Consider UUID v7 for better security and scalability',
];
