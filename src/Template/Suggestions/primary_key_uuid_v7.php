<?php

declare(strict_types=1);

/**
 * Template for UUID v7 suggestion.
 * @var string $entity_name Full entity class name
 * @var string $short_name  Short entity name
 */

/** @var array<string, mixed> $context PHPStan: Template context */
// Extract context
$entity_name = $context['entity_name'] ?? 'App\Entity\Example';
$short_name = $context['short_name'] ?? 'Example';

ob_start();
?>

<div class="uuid-v7-suggestion">
    <h2>⚡ Upgrade to UUID v7 for Better Performance</h2>

    <div class="entity-info">
        <p><strong>Entity:</strong> <code><?= htmlspecialchars($short_name) ?></code></p>
    </div>

    <div class="current-status">
        <p><strong>Current:</strong> UUID v4 (random) - causes slow inserts and fragmented indexes</p>
        <p><strong>Recommended:</strong> UUID v7 (sequential, timestamp-based) - <strong>58% faster inserts, 29% smaller indexes</strong></p>
    </div>

    <div class="migration">
        <h3>Migration</h3>
        <div class="code-comparison">
            <div class="before-example">
                <p><em>Before: UUID v4</em></p>
                <pre><code class="language-php">use Symfony\Bridge\Doctrine\IdGenerator\UuidV4Generator;

#[ORM\Id]
#[ORM\GeneratedValue(strategy: 'CUSTOM')]
#[ORM\CustomIdGenerator(class: UuidV4Generator::class)]
private UuidInterface $id;</code></pre>
            </div>
            <div class="after-example">
                <p><em>After: UUID v7</em></p>
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

    <div class="benefits">
        <h3>Benefits</h3>
        <p>Sequential ordering reduces B-tree page splits by 98%, improving insert speed and index efficiency.</p>
    </div>
</div>

<?php
$code = ob_get_clean();

return [
    'code'        => $code,
    'description' => 'Upgrade to UUID v7 for better performance',
];
